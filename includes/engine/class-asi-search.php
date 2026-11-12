<?php
/**
 * Motor de búsqueda: candidatos → puntuación → fidelidad → página de resultados.
 *
 * Clase pura: habla con el índice a través de ASI_Repository, así se prueba sin WordPress.
 *
 * Reglas:
 *  - El contexto (idioma, y con él el país) lo aplica el repositorio antes de todo.
 *  - Los grupos críticos (códigos y términos del sitio) son obligatorios: si hay resultados que
 *    cumplen todos, solo se muestran esos, aunque sean pocos ("fidelidad antes que relleno").
 *  - Si ninguno cumple todo, se muestran los más cercanos y la calidad queda marcada como parcial.
 *
 * @package AsesorInteligente
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'ASI_CLI_TESTS' ) ) {
	exit;
}

/**
 * Acceso al índice. La implementación de WordPress está en ASI_Index_Repository.
 */
interface ASI_Repository {

	/**
	 * Filas candidatas del contexto que contienen alguna de las palabras.
	 *
	 * @param string   $lang  Idioma del contexto.
	 * @param string[] $words Formas normales a buscar (palabras o frases).
	 * @param string[] $codes Códigos compactos.
	 * @param string[] $roles Roles de contenido permitidos.
	 * @param int      $limit Máximo.
	 * @return array[] Filas: post_id, post_type, role, title, title_norm, terms_norm, codes, body_norm, card (array).
	 */
	public function candidates( $lang, array $words, array $codes, array $roles, $limit );

	/**
	 * Filas por ID (dentro del contexto).
	 *
	 * @param string $lang Idioma.
	 * @param int[]  $ids  IDs.
	 * @return array[]
	 */
	public function rows( $lang, array $ids );

	/**
	 * Filas de ciertos tipos de contenido del contexto, por título (p. ej. todas las sucursales).
	 *
	 * @param string   $lang  Idioma.
	 * @param string[] $types Tipos de contenido.
	 * @param int      $limit Máximo.
	 * @return array[]
	 */
	public function by_post_types( $lang, array $types, $limit );

	/**
	 * Datos de la ficha y texto original de varios posts (solo se piden para responder).
	 *
	 * @param string $lang Idioma.
	 * @param int[]  $ids  IDs.
	 * @return array<int,array{facts:array,text:string}>
	 */
	public function details( $lang, array $ids );

	/**
	 * Cuántas veces se abrió cada resultado después de esta búsqueda (aprendizaje por clics).
	 *
	 * @param string $lang Idioma.
	 * @param string $key  Clave de la búsqueda (ASI_Lexicon::query_key).
	 * @return array<int,int> post_id => clics
	 */
	public function boosts( $lang, $key );

	/**
	 * Vocabulario del contexto (ver ASI_Query_Analyzer).
	 *
	 * @param string $lang Idioma.
	 * @return array
	 */
	public function vocabulary( $lang );

	/**
	 * Todas las filas de catálogo del contexto (para "¿qué hay con 200 mil?", sin tipo de producto).
	 * @param string $lang  Idioma.
	 * @param int    $limit Máximo.
	 * @return array[]
	 */
	public function catalog( $lang, $limit );
}

class ASI_Search {

	const PAGE_SIZE       = 8;
	const CANDIDATE_LIMIT = 400;
	const BROWSE_LIMIT    = 3000;
	const BODY_POINTS     = 3;
	const MIN_CLICKS      = 3;
	const MAX_BOOST       = 0.5;

	const ROLE_WEIGHT = array(
		'catalog' => 1.0,
		'info'    => 0.75,
		'article' => 0.55,
	);

	/** @var ASI_Repository */
	private $repository;

	/** @var array */
	private $vocab;

	/** @var array Configuración de moneda. */
	private $money;

	/** @var int Cuántos productos quitó la exclusión en la última búsqueda ("que no sea X"). */
	private $excluded_hits = 0;

	public function __construct( ASI_Repository $repository, array $vocab, array $money = array() ) {
		$this->repository = $repository;
		$this->vocab      = $vocab;
		$this->money      = ASI_Money::config( $money );
	}

	/**
	 * Busca.
	 *
	 * @param string $lang    Idioma del contexto.
	 * @param array  $query   Resultado de ASI_Query_Analyzer::analyze().
	 * @param array  $options roles (string[]), exclude (int[]), limit (int), boosts (post_id => clics).
	 * @return array {
	 *   items:   array[] filas con score,
	 *   total:   int     resultados del conjunto elegido,
	 *   quality: string  exact | partial | loose | none,
	 *   facets:  array   categoría => cantidad (sobre el conjunto elegido),
	 *   ids:     int[]   todos los IDs del conjunto en orden (para "más opciones")
	 * }
	 */
	public function search( $lang, array $query, array $options = array() ) {
		$options = array_merge(
			array(
				'roles'   => array( 'catalog', 'info', 'article' ),
				'exclude' => array(),
				'limit'   => self::PAGE_SIZE,
			),
			$options
		);
		$this->excluded_hits = 0;

		$empty = array( 'items' => array(), 'total' => 0, 'quality' => 'none', 'facets' => array(), 'brands' => array(), 'ids' => array(), 'similar' => array(), 'named' => false, 'body_only' => false );
		// Sin palabras obligatorias pero con presupuesto o atributos ("camioneta del 2024", "7
		// pasajeros 4x4"): se recorre el catálogo por atributos; el texto solo ordena.
		$soft_only = ! empty( $query['groups'] ) && ! empty( $query['attrs'] ) && count( array_filter( array_column( $query['groups'], 'soft' ) ) ) === count( $query['groups'] );
		if ( empty( $query['groups'] ) || $soft_only ) {
			return self::has_hard_filter( $query ) && in_array( 'catalog', $options['roles'], true )
				? $this->browse_by_budget( $lang, $query, $options, $empty )
				: $empty;
		}

		list( $words, $codes ) = $this->search_terms( $query['groups'] );
		$rows                  = $this->repository->candidates( $lang, $words, $codes, $options['roles'], self::CANDIDATE_LIMIT );

		$scored = array();
		foreach ( $rows as $row ) {
			$result = self::score( $row, $query, $this->vocab );
			if ( $result['score'] <= 0 || ! $this->passes_price( $row, $query ) || $this->is_excluded( $row, $query ) ) {
				continue;
			}
			// Atributos del giro: lo que no cumple se va; lo que cumple todo sube; lo que no se
			// sabe (p. ej. un accesorio, o un vehículo sin ese dato) se queda sin premio.
			$fits = self::fits( $row, $query );
			if ( false === $fits ) {
				continue;
			}
			if ( true === $fits && ! empty( $query['attrs'] ) ) {
				$result['score'] *= 1.5;
				$result['final'] *= 1.5;
			}
			$result['fits'] = $fits;
			$scored[]       = array_merge( $row, $result );
		}
		// Pidió algo concreto además del tipo ("4x4", "usada", "7 pasajeros") y hay productos que
		// lo cumplen de verdad: los que no se sabe si lo cumplen (accesorios, fichas incompletas) se van.
		if ( ! empty( $query['attrs'] ) && array_diff( array_keys( (array) $query['attrs'] ), array( 'body' ) ) && in_array( true, array_column( $scored, 'fits' ), true ) ) {
			$scored = array_values(
				array_filter(
					$scored,
					static function ( $row ) {
						return true === $row['fits'];
					}
				)
			);
		}
		if ( ! $scored ) {
			return $empty;
		}

		list( $pool, $quality ) = self::faithful_pool( $scored, $query );

		// Si algo se llama exactamente como lo pidió ("producto 2"), se muestra eso; los nombres
		// parecidos ("Producto 2 of") quedan como sugerencia.
		$similar = array();
		$named   = false;
		$exact   = self::exact_title_rows( $pool, $query );
		if ( $exact ) {
			foreach ( $pool as $row ) {
				if ( ! in_array( $row, $exact, true ) && false !== strpos( ' ' . $row['title_norm'] . ' ', ' ' . $query['content'] . ' ' ) ) {
					$similar[] = $row;
				}
			}
			$pool    = $exact;
			$quality = 'exact';
			$named   = true;
		}

		// Aprendizaje por clics: lo que la gente abre después de esta búsqueda sube un poco.
		// Solo reordena lo que ya es relevante; nunca agrega resultados.
		if ( ! empty( $options['boosts'] ) ) {
			foreach ( $pool as &$row ) {
				$clicks = isset( $options['boosts'][ (int) $row['post_id'] ] ) ? (int) $options['boosts'][ (int) $row['post_id'] ] : 0;
				if ( $clicks >= self::MIN_CLICKS ) {
					$row['final'] = round( $row['final'] * ( 1 + min( self::MAX_BOOST, 0.12 * log( $clicks, 2 ) ) ), 4 );
				}
			}
			unset( $row );
		}

		usort(
			$pool,
			function ( $a, $b ) {
				if ( $a['final'] === $b['final'] ) {
					return strcmp( $a['title'], $b['title'] );
				}
				return $a['final'] < $b['final'] ? 1 : -1;
			}
		);

		$ids     = array_map( 'intval', array_column( $pool, 'post_id' ) );
		$exclude = array_flip( array_map( 'intval', $options['exclude'] ) );
		$items   = array();
		foreach ( $pool as $row ) {
			if ( ! isset( $exclude[ (int) $row['post_id'] ] ) ) {
				$items[] = $row;
			}
			if ( count( $items ) >= $options['limit'] ) {
				break;
			}
		}

		return array(
			'items'   => $items,
			'total'   => count( $pool ),
			'quality' => $quality,
			'facets'  => self::facets( $pool ),
			'brands'  => self::brands( $pool ),
			'ids'     => $ids,
			'similar' => array_slice( $similar, 0, 4 ), // Filas con nombre parecido (si hubo nombre exacto).
			'named'   => $named, // Lo que escribió es exactamente el nombre de un resultado.
			// Lo pedido solo aparece dentro del texto de las fichas ("llanta de repuesto"), nunca en un
			// nombre, categoría o marca: no es algo que el sitio venda.
			'body_only' => ! array_filter( array_column( $pool, 'strong' ) ),
			'excluded'  => $this->excluded_hits, // Cuántos quitó "que no sea X" (0 = no quitó nada).
		);
	}

	/**
	 * ¿La fila cumple los atributos pedidos? true / false / null (no se sabe).
	 */
	private static function fits( array $row, array $query ) {
		if ( empty( $query['attrs'] ) ) {
			return true;
		}
		$attrs = isset( $row['card']['attrs'] ) ? (array) $row['card']['attrs'] : array();
		return ASI_Attributes::accepts( $attrs, (array) $query['attrs'] );
	}

	/**
	 * ¿Pidió algo que se puede listar sin palabras? (presupuesto o atributos: "7 pasajeros 4x4").
	 */
	public static function has_hard_filter( array $query ) {
		return self::has_price_filter( $query ) || ! empty( $query['attrs'] );
	}

	public static function has_price_filter( array $query ) {
		return isset( $query['price_max'] ) && null !== $query['price_max'] || isset( $query['price_min'] ) && null !== $query['price_min'];
	}

	/**
	 * Solo presupuesto o atributos ("algo de 60 a 70 mil dólares", "7 pasajeros 4x4"): todo el
	 * catálogo que cumple, primero lo que más aprovecha el presupuesto (el precio más cercano al
	 * tope) o, sin presupuesto, lo más reciente.
	 */
	private function browse_by_budget( $lang, array $query, array $options, array $empty ) {
		$pool = array();
		$by_price = self::has_price_filter( $query );
		foreach ( $this->repository->catalog( $lang, self::BROWSE_LIMIT ) as $row ) {
			if ( ! $this->passes_price( $row, $query ) || $this->is_excluded( $row, $query ) ) {
				continue;
			}
			// Sin palabras de búsqueda, solo entra lo que cumple de verdad (no "no se sabe").
			if ( ! empty( $query['attrs'] ) && true !== self::fits( $row, $query ) ) {
				continue;
			}
			$price         = ASI_Money::card_price( (array) $row['card'], $this->money );
			$year          = isset( $row['card']['attrs']['year'] ) ? (int) $row['card']['attrs']['year'] : 0;
			$text          = empty( $query['groups'] ) ? 0.0 : (float) self::score( $row, $query, $this->vocab )['score'];
			$row['score']  = 1 + $text;
			$row['text']   = $text;
			$row['final']  = $by_price ? (float) $price['base'] : (float) $year;
			$pool[]        = $row;
		}
		if ( ! $pool ) {
			return $empty;
		}
		// Primero lo que también coincide con las palabras; después, presupuesto o año.
		usort(
			$pool,
			static function ( $a, $b ) {
				if ( $a['text'] !== $b['text'] ) {
					return $a['text'] < $b['text'] ? 1 : -1;
				}
				return $a['final'] === $b['final'] ? strcmp( $a['title'], $b['title'] ) : ( $a['final'] < $b['final'] ? 1 : -1 );
			}
		);
		$exclude = array_flip( array_map( 'intval', $options['exclude'] ) );
		$items   = array();
		foreach ( $pool as $row ) {
			if ( ! isset( $exclude[ (int) $row['post_id'] ] ) ) {
				$items[] = $row;
			}
			if ( count( $items ) >= $options['limit'] ) {
				break;
			}
		}
		return array_merge(
			$empty,
			array(
				'items'   => $items,
				'total'   => count( $pool ),
				'quality' => 'exact',
				'facets'  => self::facets( $pool ),
				'brands'  => self::brands( $pool ),
				'ids'     => array_map( 'intval', array_column( $pool, 'post_id' ) ),
				'budget'  => $by_price,
				'browse'  => true,
				'excluded' => $this->excluded_hits,
			)
		);
	}

	/**
	 * ¿La fila es de lo que dijo que no quiere? Cuenta solo en el nombre o los términos:
	 * "que no sea eléctrico" quita los montacargas eléctricos, no los que mencionan "eléctrico" en el texto.
	 *
	 * @param array $row   Fila.
	 * @param array $query Consulta.
	 * @return bool
	 */
	private function is_excluded( array $row, array $query ) {
		if ( empty( $query['exclude'] ) ) {
			return false;
		}
		foreach ( $query['exclude'] as $group ) {
			// Como término crítico: solo cuenta si coincide fuerte (nombre o términos).
			$group['critical'] = true;
			$result            = self::score( $row, array( 'groups' => array( $group ), 'content' => '' ), $this->vocab );
			if ( $result['matched'] ) {
				++$this->excluded_hits;
				return true;
			}
		}
		return false;
	}

	/**
	 * Filas cuyo nombre es exactamente lo que escribió (en forma normal).
	 *
	 * @param array[] $rows  Filas.
	 * @param array   $query Consulta.
	 * @return array[]
	 */
	private static function exact_title_rows( array $rows, array $query ) {
		if ( '' === $query['content'] || count( $query['groups'] ) < 1 ) {
			return array();
		}
		return array_values(
			array_filter(
				$rows,
				function ( $row ) use ( $query ) {
					return trim( $row['title_norm'] ) === $query['content'];
				}
			)
		);
	}

	/**
	 * Puntuación de una fila para una consulta.
	 *
	 * @param array $row   Fila del índice.
	 * @param array $query Consulta analizada.
	 * @param array $vocab Vocabulario (para dar más peso a las palabras poco comunes).
	 * @return array score, final, coverage, critical_ok, matched (int[] índices de grupos)
	 */
	public static function score( array $row, array $query, array $vocab ) {
		// El índice ya guarda título, términos y cuerpo en forma normal (singular, sin acentos).
		$title     = ' ' . $row['title_norm'] . ' ';
		$terms_set = array();
		foreach ( explode( '|', (string) $row['terms_norm'] ) as $term ) {
			$term = trim( $term );
			if ( '' !== $term ) {
				$terms_set[ $term ] = true;
			}
		}
		$terms = ' ' . implode( ' ', array_keys( $terms_set ) ) . ' ';
		$codes = array_filter( explode( ' ', (string) $row['codes'] ) );
		$body  = ' ' . $row['body_norm'] . ' ';

		$docs      = max( 1, isset( $vocab['docs'] ) ? (int) $vocab['docs'] : 1 );
		$strongs   = 0;
		$total     = 0.0;
		$matched   = array();
		$critical  = 0;
		$crit_hits = 0;

		foreach ( $query['groups'] as $index => $group ) {
			$best = 0;
			foreach ( $group['alts'] as $alt ) {
				$best = max( $best, self::score_alt( $group['kind'], (string) $alt, $title, $terms, $terms_set, $codes, $body ) );
			}

			if ( $group['critical'] ) {
				$critical++;
			}
			if ( $best <= 0 ) {
				continue;
			}
			// Un término del sitio (categoría, marca, código) solo se cumple en el título o en los términos:
			// "filtro para tractores" en la descripción no hace que un filtro sea un tractor.
			$strong   = $best > self::BODY_POINTS;
			$strongs += $strong ? 1 : 0;
			if ( ! $group['critical'] || $strong ) {
				$matched[] = $index;
			}
			if ( $group['critical'] && $strong ) {
				$crit_hits++;
			}

			// Las palabras raras en el sitio pesan más que las comunes.
			$weight = 1.2;
			if ( 'word' === $group['kind'] ) {
				$df     = isset( $vocab['tokens'][ $group['text'] ] ) ? (int) $vocab['tokens'][ $group['text'] ] : 0;
				$weight = 0.6 + log( 1 + $docs / ( 1 + $df ) ) / log( 1 + $docs );
			}
			$total += $best * $weight;
		}

		$groups   = count( $query['groups'] );
		$coverage = $groups ? count( $matched ) / $groups : 0;

		// Todo lo que escribió, tal cual, dentro del título.
		if ( '' !== $query['content'] && false !== strpos( $title, ' ' . $query['content'] . ' ' ) && $groups > 1 ) {
			$total += 10;
		}

		$role  = isset( $row['role'] ) && isset( self::ROLE_WEIGHT[ $row['role'] ] ) ? self::ROLE_WEIGHT[ $row['role'] ] : 0.5;
		$final = $total * ( 0.4 + 0.6 * $coverage ) * $role;

		return array(
			'score'       => $total,
			'final'       => round( $final, 4 ),
			'coverage'    => $coverage,
			'critical_ok' => $crit_hits === $critical,
			'matched'     => $matched,
			'strong'      => $strongs, // Grupos que aparecen en el nombre o los términos (no solo en el texto).
		);
	}

	/**
	 * Puntos de una alternativa en una fila.
	 */
	private static function score_alt( $kind, $alt, $title, $terms, array $terms_set, array $codes, $body ) {
		if ( 'code' === $kind ) {
			foreach ( $codes as $code ) {
				if ( $code === $alt ) {
					return 100;
				}
			}
			foreach ( $codes as $code ) {
				if ( strlen( $alt ) >= 3 && 0 === strpos( $code, $alt ) ) {
					return 50;
				}
			}
			return false !== strpos( str_replace( ' ', '', $title ), $alt ) ? 30 : 0;
		}

		$needle = ' ' . $alt . ' ';
		$points = 0;

		if ( isset( $terms_set[ $alt ] ) ) {
			$points = max( $points, 14 );
		} elseif ( false !== strpos( $terms, $needle ) ) {
			$points = max( $points, 9 );
		}
		if ( false !== strpos( $title, $needle ) ) {
			$points = max( $points, 12 );
		} elseif ( strlen( $alt ) >= 4 && false === strpos( $alt, ' ' ) && false !== strpos( $title, ' ' . $alt ) ) {
			$points = max( $points, 6 ); // Prefijo: "electr" en "electrico".
		}
		// Un número corto ("2") solo cuenta en el nombre o los términos: en el texto largo aparece en cualquier parte.
		if ( ! $points && ! ( ctype_digit( $alt ) && strlen( $alt ) <= 3 ) && false !== strpos( $body, $needle ) ) {
			$points = self::BODY_POINTS;
		}
		return $points;
	}

	/**
	 * Elige el conjunto a mostrar según fidelidad.
	 *
	 * @param array[] $scored Filas puntuadas.
	 * @param array   $query  Consulta.
	 * @return array [filas, calidad]
	 */
	private static function faithful_pool( array $scored, array $query ) {
		$has_critical = false;
		foreach ( $query['groups'] as $group ) {
			$has_critical = $has_critical || $group['critical'];
		}

		$full = array_filter(
			$scored,
			function ( $row ) {
				return $row['critical_ok'] && $row['coverage'] >= 1;
			}
		);
		if ( $full ) {
			return array( array_values( $full ), 'exact' );
		}

		if ( $has_critical ) {
			$critical = array_filter(
				$scored,
				function ( $row ) {
					return $row['critical_ok'];
				}
			);
			if ( $critical ) {
				// Cumple todos los términos del sitio; faltan palabras sueltas.
				return array( array_values( $critical ), 'partial' );
			}
			// Nadie cumple todo: manda lo principal (categoría o código) sobre atributos y marcas.
			// "Zapatos rojos" sin zapatos rojos muestra zapatos, no camisetas rojas.
			$main = array();
			foreach ( $query['groups'] as $index => $group ) {
				if ( 'code' === $group['kind'] || 'category' === $group['term_kind'] ) {
					$main[] = $index;
				}
			}
			if ( $main ) {
				$main_rows = array_filter(
					$scored,
					function ( $row ) use ( $main ) {
						return ! array_diff( $main, $row['matched'] );
					}
				);
				if ( $main_rows ) {
					return array( array_values( $main_rows ), 'partial' );
				}
			}
		} else {
			$most = array_filter(
				$scored,
				function ( $row ) {
					return $row['coverage'] >= 0.5;
				}
			);
			if ( $most ) {
				return array( array_values( $most ), 'partial' );
			}
		}

		return array( $scored, 'loose' );
	}

	/**
	 * Categorías más comunes del conjunto (para sugerencias y aclaraciones).
	 *
	 * @param array[] $rows Filas.
	 * @return array<string,int>
	 */
	private static function facets( array $rows ) {
		$facets = array();
		foreach ( $rows as $row ) {
			$categories = ! empty( $row['card']['categories'] ) ? (array) $row['card']['categories'] : array( isset( $row['card']['category'] ) ? $row['card']['category'] : '' );
			foreach ( array_unique( array_filter( array_map( 'trim', $categories ) ) ) as $category ) {
				$facets[ $category ] = isset( $facets[ $category ] ) ? $facets[ $category ] + 1 : 1;
			}
		}
		arsort( $facets );
		return $facets;
	}

	/**
	 * Marcas más comunes del conjunto (para preguntar cuál prefiere).
	 *
	 * @param array[] $rows Filas.
	 * @return array<string,int>
	 */
	private static function brands( array $rows ) {
		$brands = array();
		foreach ( $rows as $row ) {
			$brand = isset( $row['card']['brand'] ) ? trim( (string) $row['card']['brand'] ) : '';
			if ( '' !== $brand ) {
				$brands[ $brand ] = isset( $brands[ $brand ] ) ? $brands[ $brand ] + 1 : 1;
			}
		}
		arsort( $brands );
		return $brands;
	}

	/**
	 * Palabras y códigos a pedir al repositorio.
	 *
	 * @param array[] $groups Grupos.
	 * @return array [palabras, códigos]
	 */
	private function search_terms( array $groups ) {
		$words = array();
		$codes = array();
		foreach ( $groups as $group ) {
			foreach ( $group['alts'] as $alt ) {
				if ( 'code' === $group['kind'] ) {
					$codes[] = $alt;
				} else {
					$words[] = $alt;
				}
			}
		}
		return array( array_values( array_unique( $words ) ), array_values( array_unique( $codes ) ) );
	}

	/**
	 * Filtro de precio, siempre en la moneda base: el monto del cliente y el de cada producto se
	 * convierten antes de comparar. Sin precio publicado o con una moneda que no se puede
	 * convertir, el producto se descarta (no se sabe si cumple).
	 *
	 * @param array $row   Fila.
	 * @param array $query Consulta.
	 * @return bool
	 */
	private function passes_price( array $row, array $query ) {
		if ( null === $query['price_max'] && null === $query['price_min'] ) {
			return true;
		}
		$price = ASI_Money::card_price( isset( $row['card'] ) ? (array) $row['card'] : array(), $this->money );
		// Con presupuesto solo cuentan los que tienen precio: un accesorio sin precio no "cabe" en él.
		if ( null === $price['amount'] ) {
			return false;
		}
		if ( null === $price['base'] ) {
			return false;
		}
		$currency = isset( $query['price_currency'] ) ? $query['price_currency'] : '';
		$max      = null === $query['price_max'] ? null : ASI_Money::to_base( $query['price_max'], $currency, $this->money );
		$min      = null === $query['price_min'] ? null : ASI_Money::to_base( $query['price_min'], $currency, $this->money );
		if ( null !== $max && $price['base'] > $max ) {
			return false;
		}
		return null === $min || $price['base'] >= $min;
	}

	/**
	 * "Q 12,500.00" → 12500.0; "$1.299,90" → 1299.9; texto sin números → null.
	 *
	 * @param string $raw Precio mostrado.
	 * @return float|null
	 */
	public static function parse_price( $raw ) {
		if ( ! preg_match( '/[0-9][0-9.,\s]*/', (string) $raw, $m ) ) {
			return null;
		}
		$number = preg_replace( '/\s/', '', $m[0] );
		// El último separador con 1-2 decimales detrás es el decimal.
		if ( preg_match( '/^(.*)[.,](\d{1,2})$/', $number, $parts ) ) {
			return (float) ( preg_replace( '/[^0-9]/', '', $parts[1] ) . '.' . $parts[2] );
		}
		return (float) preg_replace( '/[^0-9]/', '', $number );
	}

}
