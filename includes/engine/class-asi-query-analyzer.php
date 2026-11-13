<?php
/**
 * Convierte un mensaje en una consulta estructurada.
 *
 * Clase pura. Usa el vocabulario real del contexto (idioma + país) para:
 *  - reconocer términos del sitio (categorías, marcas, distintivos) aunque vengan en plural,
 *    con sinónimos o con errores de ortografía;
 *  - reconocer códigos de producto;
 *  - separar lo que se busca de las palabras de petición ("quiero", "busco", "tienen").
 *
 * Vocabulario esperado:
 *   docs    => int, documentos del contexto
 *   tokens  => [forma_normal => df]  palabras de títulos, términos y códigos
 *   phrases => [forma_normal => [label, kind, df]]  términos del sitio (taxonomías, marcas, distintivos)
 *
 * Resultado: ver analyze().
 *
 * @package AsesorInteligente
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'ASI_CLI_TESTS' ) ) {
	exit;
}

class ASI_Query_Analyzer {

	const MAX_PHRASE_TOKENS = 5;

	/** @var ASI_Lexicon */
	private $lexicon;

	/** @var array */
	private $vocab;

	/** @var array<string,array[]> primer token => lista de [tokens, forma_normal] de frases del vocabulario. */
	private $phrase_index = array();

	/**
	 * @param ASI_Lexicon $lexicon Idioma.
	 * @param array      $vocab   Vocabulario del contexto.
	 */
	/** @var array Configuración de moneda (ASI_Money::config). */
	private $money;

	/** @var ASI_Pack[] Packs activos (atributos del giro). */
	private $packs;

	/** @var string[] Palabras de pedido que se ignoran siempre. */
	private $request_words = array();

	/** @var array<string,bool> Palabras que entienden los packs. */
	private $pack_words = array();

	public function __construct( ASI_Lexicon $lexicon, array $vocab, array $money = array(), array $packs = array() ) {
		$this->lexicon = $lexicon;
		$this->money   = ASI_Money::config( $money );
		$this->packs   = $packs;
		$this->request_words = array_map( array( 'ASI_Normalizer', 'normalize' ), (array) $lexicon->get_list( 'request_words' ) );
		$this->vocab   = array_merge( array( 'docs' => 0, 'tokens' => array(), 'phrases' => array() ), $vocab );
		// Palabras de los packs (tipos de vehículo, transmisión, combustible…): el corrector también las conoce.
		foreach ( $packs as $pack ) {
			if ( is_object( $pack ) && method_exists( $pack, 'query_words' ) ) {
				foreach ( (array) $pack->query_words() as $word ) {
					$this->pack_words[ ASI_Normalizer::normalize( $word ) ] = true;
				}
			}
		}

		foreach ( $this->vocab['phrases'] as $normal => $info ) {
			$tokens = $this->content_tokens_of( $normal );
			if ( ! $tokens || count( $tokens ) > self::MAX_PHRASE_TOKENS ) {
				continue;
			}
			$this->phrase_index[ $tokens[0] ][] = array( $tokens, $normal );
		}
	}

	/**
	 * Analiza un mensaje.
	 *
	 * @param string $message Mensaje del usuario.
	 * @return array {
	 *   normalized:  string,
	 *   groups:      array[] cada uno: text, alts[], kind (code|phrase|word), critical, label, term_kind,
	 *   label:       string  lo que se busca, para mostrar ("montacargas eléctricos"),
	 *   content:     string  palabras con contenido, en forma normal,
	 *   price_max:   float|null, price_min: float|null, asks_price: bool,
	 *   corrections: array<string,string>,
	 *   exclude:     array[] grupos que NO quiere ("que no sea eléctrico"), exclude_label: string
	 * }
	 */
	public function analyze( $message ) {
		// Errores de dedo primero, antes de leer tipo de vehículo, atributos y montos:
		// "camioneeta automatica" se lee igual que "camioneta automatica".
		$typos   = array();
		$message = $this->fix_typos( $message, $typos );
		$normalized = ASI_Normalizer::normalize( $message );
		$display    = $this->display_words( $message );

		$result = array(
			'normalized'  => $normalized,
			'groups'      => array(),
			'label'       => '',
			'content'     => '',
			'price_max'   => null,
			'price_min'   => null,
			'price_currency' => '', // Moneda que dijo el cliente ('' = la base del sitio).
			'budget'      => false,  // Lo dijo como presupuesto ("tengo 200 mil").
			'attrs'       => array(), // Restricciones del giro (ASI_Attributes): plazas, tracción, año…
			'attr_labels' => array(), // Cómo nombrarlas ("de 7 pasajeros", "4x4").
			'asks_price'  => '' !== ASI_Lexicon::find_phrase( $normalized, $this->lexicon->get_list( 'price_words' ) ),
			'corrections' => $typos,
			'exclude'     => array(),
			'exclude_label' => '',
			'attr_not_labels' => array(), // Lo que no quiere y es un atributo ("que no sea gasolina").
			'attr_spans'      => array(), // Palabras de cada atributo que quiere (key => "camioneta").
			'attr_not_spans'  => array(), // Palabras de cada atributo que no quiere.
		);

		$text = $this->extract_attributes( $message, $result, $this->extract_prices( $message, $normalized, $result ) );
		$text = $this->extract_separated_codes( $message, $text, $result );
		$text = $this->extract_negation( $text, $result, $display );

		// 1. Tokens con contenido, en forma normal, recordando cómo los escribió el usuario.
		$tokens = array();
		foreach ( explode( ' ', $text ) as $token ) {
			if ( '' === $token || $this->lexicon->is_stopword( $token ) ) {
				continue;
			}
			// "producto", "precio", "info"… son palabras de petición, salvo que el sitio las use en sus
			// nombres: en una tienda donde los productos se llaman "Producto 2", "producto" sí se busca.
			if ( $this->lexicon->is_filler( $token ) && ! isset( $this->vocab['tokens'][ ASI_Normalizer::singular( $token ) ] ) ) {
				continue;
			}
			// Palabras de pedido que nunca son lo que se busca, aunque el sitio las use en algún texto
			// ("puedes mostrarme opciones híbridas por favor" busca solo "híbridas").
			if ( in_array( $token, $this->request_words, true ) ) {
				continue;
			}
			if ( 1 === strlen( $token ) && ! ctype_digit( $token ) ) {
				continue;
			}
			$tokens[] = array(
				'normal'  => ASI_Normalizer::looks_like_code( $token ) ? $token : ASI_Normalizer::singular( $token ),
				'raw'     => $token,
				'display' => isset( $display[ $token ] ) ? $display[ $token ] : $token,
			);
		}

		// 2. Códigos que existen en el sitio.
		$remaining = array();
		foreach ( $tokens as $token ) {
			$is_code = ASI_Normalizer::looks_like_code( $token['raw'] ) && $this->is_known_code( $token['raw'] );
			// Códigos solo numéricos ("12345"): cuentan si existen tal cual en el sitio.
			$is_code = $is_code || ( ctype_digit( $token['raw'] ) && strlen( $token['raw'] ) >= 4 && isset( $this->vocab['tokens'][ $token['raw'] ] ) );
			if ( $is_code ) {
				$result['groups'][] = array(
					'text'      => $token['raw'],
					'alts'      => array( ASI_Normalizer::compact_code( $token['raw'] ) ),
					'kind'      => 'code',
					'critical'  => true,
					'label'     => strtoupper( $token['raw'] ),
					'term_kind' => 'code',
				);
				continue;
			}
			$remaining[] = $token;
		}

		// 3. Corrección ortográfica contra el vocabulario del sitio.
		foreach ( $remaining as &$token ) {
			$fixed = $this->correct( $token['normal'] );
			if ( $fixed !== $token['normal'] ) {
				$result['corrections'][ $token['raw'] ] = $fixed;
				$token['normal']                        = $fixed;
				$token['display']                       = $fixed;
			}
		}
		unset( $token );

		// 4. Términos del sitio (frases), del más largo al más corto; luego sinónimos que llevan a un término.
		$result['groups'] = self::merge_brands( array_merge( $result['groups'], $this->match_terms( $remaining ) ) );

		$contents = array();
		$labels   = array();
		foreach ( $result['groups'] as $group ) {
			$contents[] = $group['text'];
			$labels[]   = $group['label'];
		}
		$result['content'] = implode( ' ', $contents );
		$result['label']   = $this->span_label( $text, $display, $result['groups'], implode( ' ', $labels ) );
		// Varias marcas: se nombran todas ("suv volkswagen, toyota o isuzu"), no solo la primera.
		foreach ( $result['groups'] as $group ) {
			if ( ! empty( $group['brand_labels'] ) && count( $group['brand_labels'] ) > 1 ) {
				$names = array_map( array( __CLASS__, 'display_name' ), (array) $group['brand_labels'] );
				$names = array_map( 'strtolower', $names );
				$last  = array_pop( $names );
				$all   = implode( ', ', $names ) . ' o ' . $last;
				$label = preg_replace( '/\b' . preg_quote( (string) $group['text'], '/' ) . '\b(\s+o\s+[^\s]+)*/iu', $all, $result['label'], 1, $done );
				$result['label'] = $done ? $label : trim( $result['label'] . ' ' . $all );
			}
		}

		// Palabras que un pack ya entendió como atributo ("camioneta" → carrocería SUV): siguen
		// sumando en la búsqueda, pero ya no son obligatorias en el texto (las controla el atributo).
		if ( ! empty( $result['attr_soft'] ) ) {
			foreach ( $result['groups'] as &$group ) {
				$alts = array_merge( array( $group['text'] ), isset( $group['alts'] ) ? (array) $group['alts'] : array() );
				if ( array_intersect( $alts, $result['attr_soft'] ) ) {
					$group['critical'] = false;
					$group['soft']     = true;
				}
			}
			unset( $group );
		}
		unset( $result['attr_soft'] );
		// Lo que tuvo que adivinar: palabras corregidas o que no son nombre, marca, categoría ni
		// palabra del giro. Con alguna, en modo "lee todo" interpreta la IA.
		$result['guessed'] = array_keys( $result['corrections'] );
		foreach ( $result['groups'] as $group ) {
			if ( '' === (string) $group['term_kind'] && 'code' !== $group['kind'] && ! isset( $this->pack_words[ $group['text'] ] ) && ! isset( $this->pack_words[ ASI_Lexicon::normal_form( $group['text'] ) ] ) ) {
				$result['guessed'][] = $group['text'];
			}
		}
		return $result;
	}

	/**
	 * Lo que busca, dicho con sus palabras: desde la primera hasta la última palabra con contenido,
	 * conservando las de enlace ("desodorantes en oferta", "maquillaje de ojos").
	 *
	 * @param string $text     Texto normalizado (sin rangos de precio).
	 * @param array  $display  Palabra normalizada => como la escribió.
	 * @param array  $groups   Grupos.
	 * @param string $fallback Etiqueta armada con los grupos.
	 * @return string
	 */
	private function span_label( $text, array $display, array $groups, $fallback ) {
		if ( ! $groups ) {
			return $fallback;
		}
		$wanted = array();
		foreach ( $groups as $group ) {
			if ( 'code' === $group['kind'] ) {
				return $fallback;
			}
			foreach ( explode( ' ', $group['text'] ) as $word ) {
				$wanted[ $word ] = true;
			}
		}
		$words = explode( ' ', $text );
		$first = null;
		$last  = null;
		foreach ( $words as $i => $word ) {
			$normal = ASI_Normalizer::singular( $word );
			if ( isset( $wanted[ $normal ] ) || isset( $wanted[ $word ] ) ) {
				$first = null === $first ? $i : $first;
				$last  = $i;
			}
		}
		// Si hubo correcciones ortográficas, las palabras no coinciden: se usa la etiqueta de los grupos.
		if ( null === $first ) {
			return $fallback;
		}
		$shown = array();
		for ( $i = $first; $i <= $last; $i++ ) {
			$shown[] = isset( $display[ $words[ $i ] ] ) ? $display[ $words[ $i ] ] : $words[ $i ];
		}
		return count( $shown ) > 8 ? $fallback : implode( ' ', $shown );
	}

	/**
	 * Recorre los tokens buscando términos del sitio; lo que no es término queda como palabra.
	 *
	 * @param array $tokens Tokens.
	 * @return array[] Grupos.
	 */
	private function match_terms( array $tokens ) {
		$groups = array();
		$count  = count( $tokens );
		$i      = 0;

		while ( $i < $count ) {
			$match = $this->longest_phrase_at( $tokens, $i );
			if ( $match ) {
				list( $length, $normal ) = $match;
				$groups[]                = $this->phrase_group( $normal, $tokens, $i, $length );
				$i                      += $length;
				continue;
			}

			// Un sinónimo de varias palabras ("carretilla elevadora") o de una que lleva a un término del sitio.
			$synonym_match = $this->synonym_phrase_at( $tokens, $i );
			if ( $synonym_match ) {
				list( $length, $normal ) = $synonym_match;
				$groups[]                = $this->phrase_group( $normal, $tokens, $i, $length );
				$i                      += $length;
				continue;
			}

			$groups[] = $this->word_group( $tokens[ $i ] );
			$i++;
		}
		return $groups;
	}

	/**
	 * Frase del vocabulario más larga que empieza en $i.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $i      Posición.
	 * @return array|null [longitud, forma_normal]
	 */
	private function longest_phrase_at( array $tokens, $i ) {
		$first = $tokens[ $i ]['normal'];
		if ( empty( $this->phrase_index[ $first ] ) ) {
			return null;
		}
		$best = null;
		foreach ( $this->phrase_index[ $first ] as $candidate ) {
			list( $phrase_tokens, $normal ) = $candidate;
			$length                         = count( $phrase_tokens );
			if ( $i + $length > count( $tokens ) || ( $best && $length <= $best[0] ) ) {
				continue;
			}
			$ok = true;
			for ( $k = 1; $k < $length; $k++ ) {
				if ( $tokens[ $i + $k ]['normal'] !== $phrase_tokens[ $k ] ) {
					$ok = false;
					break;
				}
			}
			if ( $ok ) {
				$best = array( $length, $normal );
			}
		}
		return $best;
	}

	/**
	 * Sinónimo (de 1 a 3 palabras) que corresponde a un término del sitio.
	 *
	 * @param array $tokens Tokens.
	 * @param int   $i      Posición.
	 * @return array|null [longitud, forma_normal del término]
	 */
	private function synonym_phrase_at( array $tokens, $i ) {
		for ( $length = min( 3, count( $tokens ) - $i ); $length >= 1; $length-- ) {
			$words = array();
			for ( $k = 0; $k < $length; $k++ ) {
				$words[] = $tokens[ $i + $k ]['normal'];
			}
			foreach ( $this->lexicon->synonyms( implode( ' ', $words ) ) as $synonym ) {
				if ( isset( $this->vocab['phrases'][ $synonym ] ) ) {
					return array( $length, $synonym );
				}
			}
		}
		return null;
	}

	/**
	 * Grupo de un término del sitio: crítico (los resultados tienen que tenerlo).
	 *
	 * @param string $normal Forma normal del término.
	 * @param array  $tokens Tokens del mensaje.
	 * @param int    $start  Inicio.
	 * @param int    $length Longitud.
	 * @return array
	 */
	private function phrase_group( $normal, array $tokens, $start, $length ) {
		$info  = $this->vocab['phrases'][ $normal ];
		$typed = array();
		$shown = array();
		for ( $k = $start; $k < $start + $length; $k++ ) {
			$typed[] = $tokens[ $k ]['normal'];
			$shown[] = $tokens[ $k ]['display'];
		}
		$typed = implode( ' ', $typed );

		$alts = array_values( array_unique( array_merge( array( $normal, $typed ), $this->known_synonyms( $normal ) ) ) );
		$kind = isset( $info['kind'] ) ? $info['kind'] : 'category';

		return array(
			'text'      => $typed,
			'alts'      => $alts,
			'kind'      => 'phrase',
			'critical'  => true,
			// Las marcas se muestran como en el sitio; el resto, como lo escribió el usuario.
			'label'     => 'brand' === $kind ? $info['label'] : implode( ' ', $shown ),
			'term_kind' => $kind,
		);
	}

	/**
	 * Grupo de una palabra suelta (no es término del sitio): suma relevancia pero no es obligatoria.
	 *
	 * @param array $token Token.
	 * @return array
	 */
	private function word_group( array $token ) {
		$alts  = array_values( array_unique( array_merge( array( $token['normal'] ), $this->inflections( $token['normal'] ), $this->known_synonyms( $token['normal'] ) ) ) );
		$known = false;
		foreach ( $alts as $alt ) {
			$known = $known || isset( $this->vocab['tokens'][ $alt ] ) || isset( $this->pack_words[ $alt ] );
		}
		return array(
			'text'      => $token['normal'],
			'alts'      => $alts,
			'kind'      => 'word',
			'critical'  => false,
			'label'     => $token['display'],
			'term_kind' => '',
			// ¿Aparece en algún producto del sitio? Si no ("querio", "mi"), no hay nada que buscar con ella.
			'known'     => $known,
		);
	}

	/**
	 * Variantes de género del idioma ("automatico" ↔ "automatica"), según su archivo de idioma.
	 *
	 * @param string $word Forma normal.
	 * @return string[]
	 */
	private function inflections( $word ) {
		$variants = array();
		if ( strlen( $word ) < 5 || preg_match( '/[0-9]/', $word ) ) {
			return $variants;
		}
		foreach ( (array) $this->lexicon->get_list( 'gender_endings' ) as $from => $to ) {
			if ( substr( $word, -strlen( $from ) ) === $from ) {
				$variants[] = substr( $word, 0, -strlen( $from ) ) . $to;
			}
		}
		return $variants;
	}

	/**
	 * Sinónimos que de verdad aparecen en el sitio (los demás no ayudan a encontrar nada).
	 *
	 * @param string $normal Forma normal.
	 * @return string[]
	 */
	private function known_synonyms( $normal ) {
		$known = array();
		foreach ( $this->lexicon->synonyms( $normal ) as $synonym ) {
			if ( isset( $this->vocab['tokens'][ $synonym ] ) || isset( $this->vocab['phrases'][ $synonym ] ) ) {
				$known[] = $synonym;
			}
		}
		return $known;
	}

	/**
	 * Corrige una palabra desconocida con la más parecida del vocabulario (distancia de edición).
	 *
	 * @param string $word Forma normal.
	 * @return string
	 */
	/**
	 * Corrige en el mensaje original las palabras desconocidas (5+ letras) contra el vocabulario del
	 * sitio. Las que el idioma ya conoce (montos, acciones, negaciones, conectores) no se tocan.
	 *
	 * @param string $message Mensaje original.
	 * @param array  $typos   escrita => corregida.
	 * @return string
	 */
	public function fix_typos( $message, array &$typos ) {
		return preg_replace_callback(
			'/\p{L}{5,}/u',
			function ( $m ) use ( &$typos ) {
				$word = ASI_Normalizer::normalize( $m[0] );
				// Diminutivos de algo del catálogo o del giro: "carrito" = carro, "camionetita" = camioneta.
				$plain = $this->undiminish( $word );
				if ( $plain !== $word ) {
					$typos[ $m[0] ] = $plain;
					return $plain;
				}
				$base = ASI_Lexicon::normal_form( $word );
				// Los números en letras no se corrigen ("cuatro" no es "cuanto").
				if ( in_array( $word, (array) $this->lexicon->get_list( 'number_words' ), true ) ) {
					return $m[0];
				}
				if ( '' === $word || ! preg_match( '/^[a-z]+$/', $word ) || isset( $this->vocab['tokens'][ $base ] ) || isset( $this->phrase_index[ $base ] ) || isset( $this->pack_words[ $word ] ) || isset( $this->pack_words[ $base ] ) || $this->lexicon->is_stopword( $word ) || $this->lexicon->is_known_word( $word ) || $this->lexicon->is_known_word( $base ) ) {
					return $m[0];
				}
				// "Querio", "nesecito", "buscnado": palabras de pedido mal escritas. No se buscan.
				$request = $this->near_request_word( $word );
				if ( '' !== $request ) {
					return $request;
				}
				$fixed = $this->correct( $word );
				if ( $fixed === $word ) {
					return $m[0];
				}
				$typos[ $m[0] ] = $fixed;
				return $fixed;
			},
			(string) $message
		);
	}

	/**
	 * La palabra sin diminutivo si esa palabra se conoce (sitio, giro o chat); si no, igual.
	 */
	private function undiminish( $word ) {
		if ( ! preg_match( '/^(.{3,}?)(c?it[oa]s?)$/', $word, $m ) ) {
			return $word;
		}
		$stem = $m[1];
		foreach ( array( $stem . 'o', $stem . 'a', $stem, rtrim( $stem, 'c' ) . 'o', rtrim( $stem, 'c' ) . 'a', rtrim( $stem, 'c' ) ) as $base ) {
			if ( strlen( $base ) >= 3 && ( isset( $this->vocab['tokens'][ $base ] ) || isset( $this->pack_words[ $base ] ) ) ) {
				return $base;
			}
		}
		return $word;
	}

	/**
	 * Distancia de errores de dedo: letras de más, de menos, cambiadas o dos vecinas al revés
	 * (cada caso cuenta 1). Se corta al pasar el máximo.
	 */
	private static function typo_distance( $a, $b, $max ) {
		if ( abs( strlen( $a ) - strlen( $b ) ) > $max ) {
			return $max + 1;
		}
		$n = strlen( $a );
		$m = strlen( $b );
		$d = array();
		for ( $i = 0; $i <= $n; $i++ ) {
			$d[ $i ][0] = $i;
		}
		for ( $j = 0; $j <= $m; $j++ ) {
			$d[0][ $j ] = $j;
		}
		for ( $i = 1; $i <= $n; $i++ ) {
			$row_min = PHP_INT_MAX;
			for ( $j = 1; $j <= $m; $j++ ) {
				$cost        = $a[ $i - 1 ] === $b[ $j - 1 ] ? 0 : 1;
				$d[ $i ][ $j ] = min( $d[ $i - 1 ][ $j ] + 1, $d[ $i ][ $j - 1 ] + 1, $d[ $i - 1 ][ $j - 1 ] + $cost );
				if ( $i > 1 && $j > 1 && $a[ $i - 1 ] === $b[ $j - 2 ] && $a[ $i - 2 ] === $b[ $j - 1 ] ) {
					$d[ $i ][ $j ] = min( $d[ $i ][ $j ], $d[ $i - 2 ][ $j - 2 ] + 1 );
				}
				$row_min = min( $row_min, $d[ $i ][ $j ] );
			}
			if ( $row_min > $max ) {
				return $max + 1;
			}
		}
		return $d[ $n ][ $m ];
	}

	/** ¿Dos palabras iguales salvo dos letras vecinas cambiadas de lugar? ("sedna" / "sedan"). */
	private static function is_swap( $a, $b ) {
		$n = strlen( $a );
		if ( $n !== strlen( $b ) ) {
			return false;
		}
		for ( $i = 0; $i < $n - 1; $i++ ) {
			if ( $a[ $i ] !== $b[ $i ] ) {
				return $a[ $i ] === $b[ $i + 1 ] && $a[ $i + 1 ] === $b[ $i ] && substr( $a, $i + 2 ) === substr( $b, $i + 2 );
			}
		}
		return false;
	}

	private function correct( $word ) {
		$length = strlen( $word );
		if ( $length < 4 || isset( $this->vocab['tokens'][ $word ] ) || isset( $this->phrase_index[ $word ] ) || ctype_digit( $word ) ) {
			return $word;
		}
		if ( $this->lexicon->synonyms( $word ) ) {
			return $word;
		}
		// Singular o plural de una palabra conocida no es un error ("sede" / "sedes").
		$base = ASI_Lexicon::normal_form( $word );
		if ( $base !== $word && ( isset( $this->vocab['tokens'][ $base ] ) || isset( $this->pack_words[ $base ] ) ) ) {
			return $word;
		}
		if ( in_array( $word . 's', $this->lexicon->known_words(), true ) || in_array( $base, $this->lexicon->known_words(), true ) ) {
			return $word;
		}

		// Dos errores solo en palabras largas: en una de 7 letras, dos cambios ya es otra palabra
		// ("carrito" no es "carreton").
		$max_distance = $length <= 7 ? 1 : 2;
		$best         = $word;
		$best_score   = PHP_INT_MAX;

		// Candidatas: el vocabulario del sitio, las palabras de los packs y las del propio chat
		// ("ubicaciones", "horario", "asesor", "hablar"…), para que una pregunta mal escrita la
		// conteste el chat sin consultar a la IA.
		$candidates = $this->vocab['tokens'];
		foreach ( $this->pack_words as $pack_word => $true ) {
			if ( ! isset( $candidates[ $pack_word ] ) ) {
				$candidates[ $pack_word ] = 500;
			}
		}
		$command = array();
		foreach ( $length >= 5 ? $this->lexicon->known_words() : array() as $lex_word ) {
			if ( strlen( $lex_word ) >= 4 && ! isset( $candidates[ $lex_word ] ) ) {
				$candidates[ $lex_word ] = 400;
				$command[ $lex_word ]    = true;
			}
		}
		foreach ( $candidates as $candidate => $df ) {
			$candidate = (string) $candidate;
			if ( abs( strlen( $candidate ) - $length ) > $max_distance || strlen( $candidate ) < 4 ) {
				continue;
			}
			// Dos letras al revés cuentan como un solo error ("asesro", "ubicaicnoes").
			$distance = self::typo_distance( $word, $candidate, $max_distance );
			// Hacia una palabra del chat, más estricto: un solo error (dos en palabras largas), para no
			// cambiar palabras normales ("ruedas" no es "buenas").
			if ( isset( $command[ $candidate ] ) && $distance > ( $length >= 9 ? 2 : 1 ) ) {
				continue;
			}
			if ( $distance > $max_distance ) {
				continue;
			}
			// A igual distancia gana la palabra más frecuente; la primera letra igual desempata.
			$score = $distance * 1000 - min( 999, (int) $df ) + ( $word[0] === $candidate[0] ? 0 : 500 );
			if ( $score < $best_score ) {
				$best       = $candidate;
				$best_score = $score;
			}
		}
		return $best;
	}

	/**
	 * ¿Este código existe en el sitio (exacto o como inicio de uno)?
	 *
	 * @param string $token Token.
	 * @return bool
	 */
	private function is_known_code( $token ) {
		$compact = ASI_Normalizer::compact_code( $token );
		if ( isset( $this->vocab['tokens'][ $compact ] ) ) {
			return true;
		}
		if ( strlen( $compact ) < 3 ) {
			return false;
		}
		foreach ( $this->vocab['tokens'] as $candidate => $df ) {
			if ( 0 === strpos( (string) $candidate, $compact ) && ASI_Normalizer::looks_like_code( (string) $candidate ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Códigos con guiones, puntos o barras ("GM-1234", "CAM-001-R", "RE.504836"): se reconocen
	 * antes de separar palabras, porque la normalización los partiría en dos.
	 *
	 * @param string $message Mensaje original.
	 * @param string $text    Texto normalizado.
	 * @param array  $result  Resultado (se agregan los grupos de código).
	 * @return string Texto sin los códigos reconocidos.
	 */
	private function extract_separated_codes( $message, $text, array &$result ) {
		if ( ! preg_match_all( '/[\p{L}\p{N}]+(?:[-_.\/][\p{L}\p{N}]+)+/u', (string) $message, $matches ) ) {
			return $text;
		}
		foreach ( $matches[0] as $raw ) {
			$compact = ASI_Normalizer::compact_code( $raw );
			if ( ! preg_match( '/[0-9]/', $compact ) || ! $this->is_known_code( $compact ) ) {
				continue;
			}
			$result['groups'][] = array(
				'text'      => $compact,
				'alts'      => array( $compact ),
				'kind'      => 'code',
				'critical'  => true,
				'label'     => strtoupper( $raw ),
				'term_kind' => 'code',
			);
			$text = trim( str_replace( ' ' . ASI_Normalizer::normalize( $raw ) . ' ', ' ', ' ' . $text . ' ' ) );
		}
		return $text;
	}

	/**
	 * "Montacargas que no sean eléctricos": lo que sigue a la negación se busca para excluirlo.
	 *
	 * @param string $text   Texto normalizado.
	 * @param array  $result Resultado.
	 * @return string Texto sin la parte negada.
	 */
	private function extract_negation( $text, array &$result, array $display ) {
		$marker = ASI_Lexicon::find_phrase( $text, (array) $this->lexicon->get_list( 'negation_markers' ) );
		if ( '' === $marker ) {
			return $text;
		}
		$position = strpos( ' ' . $text . ' ', ' ' . $marker . ' ' );
		$before   = trim( substr( $text, 0, $position ) );
		$after    = trim( substr( $text, $position + strlen( $marker ) ) );
		// La negación llega hasta que empieza otra idea: "que no sean eléctricos, solo diésel"
		// o, al sumar mensajes, "que no sean caros quiero solo toyota". Lo que sigue se busca normal.
		$rest = '';
		foreach ( (array) $this->lexicon->get_list( 'negation_stops' ) as $stop ) {
			$at = strpos( ' ' . $after . ' ', ' ' . $stop . ' ' );
			if ( false !== $at && $at > 0 ) {
				$rest  = trim( substr( $after, $at ) . ' ' . $rest );
				$after = trim( substr( $after, 0, $at ) );
			}
		}
		if ( '' === $after ) {
			return trim( $before . ' ' . $rest );
		}
		$negated = ( new self( $this->lexicon, $this->vocab ) )->analyze( $after );
		if ( ! $negated['groups'] ) {
			return '' === $rest ? $text : trim( $before . ' ' . $rest );
		}
		// Como lo escribió ("eléctricos"), sin palabras vacías.
		$label = array();
		foreach ( explode( ' ', $after ) as $word ) {
			if ( '' !== $word && ! $this->lexicon->is_stopword( $word ) ) {
				$label[] = isset( $display[ $word ] ) ? $display[ $word ] : $word;
			}
		}
		$result['exclude']       = $negated['groups'];
		$result['exclude_label'] = $label ? implode( ' ', $label ) : $negated['label'];
		return trim( $before . ' ' . $rest );
	}

	/**
	 * Saca el presupuesto o rango de precio de la frase original (con símbolos y separadores) y
	 * devuelve el texto normalizado sin esos montos.
	 *
	 * @param string $message    Frase original.
	 * @param string $normalized Texto normalizado.
	 * @param array  $result     Resultado.
	 * @return string
	 */
	private function extract_prices( $message, $normalized, array &$result ) {
		$budget = ASI_Money::extract_budget( $message, (array) $this->lexicon->get_list( 'money_words' ), $this->money );
		if ( ! $budget['spans'] ) {
			return $normalized;
		}
		$result['price_min']      = $budget['min'];
		$result['price_max']      = $budget['max'];
		$result['price_currency'] = $budget['currency'];
		$result['budget']         = $budget['budget'];
		return ASI_Normalizer::normalize( str_replace( $budget['spans'], ' ', ASI_Money::fold( $message ) ) );
	}

	/**
	 * Separa lo que quiere de lo que no: "suv hasta 180000 que no sea gasolina" →
	 * [ "suv hasta 180000", "gasolina" ]. La negación llega hasta que empieza otra idea.
	 *
	 * @return string[] { lo que quiere, lo negado ('' si no hay negación) }
	 */
	private function split_negation( $normalized ) {
		$marker = ASI_Lexicon::find_phrase( $normalized, (array) $this->lexicon->get_list( 'negation_markers' ) );
		if ( '' === $marker ) {
			return array( $normalized, '' );
		}
		$position = strpos( ' ' . $normalized . ' ', ' ' . $marker . ' ' );
		if ( false === $position ) {
			return array( $normalized, '' );
		}
		$before = trim( substr( $normalized, 0, $position ) );
		$after  = trim( substr( $normalized, $position + strlen( $marker ) ) );
		$rest   = '';
		foreach ( (array) $this->lexicon->get_list( 'negation_stops' ) as $stop ) {
			$at = strpos( ' ' . $after . ' ', ' ' . $stop . ' ' );
			if ( false !== $at && $at > 0 ) {
				$rest  = trim( substr( $after, $at ) . ' ' . $rest );
				$after = trim( substr( $after, 0, $at ) );
			}
		}
		return array( trim( $before . ' ' . $rest ), $after );
	}

	/**
	 * Atributos del giro que pidió el cliente ("de 7 pasajeros", "4x4", "del 2020 en adelante").
	 * Se quitan del texto para que no se busquen como palabras; la carrocería se queda.
	 *
	 * @param string $message Frase original.
	 * @param array  $result  Resultado.
	 * @param string $text    Texto normalizado hasta ahora.
	 * @return string
	 */
	private function extract_attributes( $message, array &$result, $text ) {
		if ( ! $this->packs ) {
			return $text;
		}
		// "suv que no sea gasolina": lo negado no es un filtro de lo que quiere sino de lo que no.
		list( $wanted, $negated ) = $this->split_negation( ASI_Normalizer::normalize( $message ) );
		$parsed                = ASI_Attributes::parse( $this->packs, '' === $negated ? $message : $wanted, $this->lexicon->language() );
		$result['attrs']       = $parsed['constraints'];
		$result['attr_labels'] = $parsed['labels'];
		$result['attr_soft']   = array();
		$result['attr_not_labels'] = array();
		foreach ( $parsed['soft'] as $word ) {
			$result['attr_soft'][] = ASI_Lexicon::normal_form( $word );
		}
		if ( '' !== $negated ) {
			$not = ASI_Attributes::parse( $this->packs, $negated, $this->lexicon->language() );
			foreach ( $not['constraints'] as $key => $rule ) {
				if ( ! isset( $result['attrs'][ $key ] ) && isset( $rule['in'] ) ) {
					$result['attrs'][ $key ] = array( 'not_in' => $rule['in'] );
				}
			}
			if ( $not['constraints'] ) {
				$result['attr_not_labels'] = $not['labels'];
				$wanted_only               = $parsed['spans'];
				$parsed['spans']           = array_merge( $parsed['spans'], $not['spans'] );
			}
		}
		// Qué palabras dieron cada atributo ("camioneta" → carrocería, "gasolina" → combustible): la
		// ficha de la búsqueda las guarda tal cual para volver a leerlas igual.
		// Las palabras de tipo ("camioneta") el pack las deja en el texto (soft): también cuentan.
		$result['attr_spans']     = $this->spans_by_key( array_merge( isset( $wanted_only ) ? $wanted_only : $parsed['spans'], (array) $parsed['soft'] ) );
		$result['attr_not_spans'] = '' !== $negated && ! empty( $not['spans'] ) ? $this->spans_by_key( $not['spans'] ) : array();
		foreach ( $parsed['spans'] as $span ) {
			$normal = ASI_Normalizer::normalize( $span );
			if ( '' !== $normal ) {
				$text = trim( preg_replace( '/(^|\s)' . preg_quote( $normal, '/' ) . '(\s|$)/', ' ', ' ' . $text . ' ' ) );
			}
		}
		return preg_replace( '/\s+/', ' ', $text );
	}

	/**
	 * "25 mil" → 25000; "5k" → 5000; "12 500" → 12500.
	 *
	 * @param string $raw Texto.
	 * @return float
	 */
	public static function parse_amount( $raw ) {
		$multiplier = preg_match( '/(mil|k)\s*$/', $raw ) ? 1000 : 1;
		$digits     = preg_replace( '/[^0-9]/', '', preg_replace( '/(mil|k)\s*$/', '', $raw ) );
		return (float) $digits * $multiplier;
	}

	/**
	 * Tokens con contenido de una frase del vocabulario (sin palabras vacías).
	 *
	 * @param string $normal Forma normal.
	 * @return string[]
	 */
	private function content_tokens_of( $normal ) {
		$tokens = array();
		foreach ( explode( ' ', $normal ) as $token ) {
			if ( '' !== $token && ! $this->lexicon->is_stopword( $token ) ) {
				$tokens[] = $token;
			}
		}
		return $tokens;
	}

	/**
	 * Cómo escribió el usuario cada palabra (con acentos), indexado por su forma normalizada.
	 *
	 * @param string $message Mensaje.
	 * @return array<string,string>
	 */
	private function display_words( $message ) {
		$map   = array();
		$words = preg_split( '/[^\p{L}\p{N}]+/u', (string) $message, -1, PREG_SPLIT_NO_EMPTY );
		foreach ( $words as $word ) {
			$normal = ASI_Normalizer::normalize( $word );
			if ( '' !== $normal && false === strpos( $normal, ' ' ) ) {
				$map[ $normal ] = function_exists( 'mb_strtolower' ) ? mb_strtolower( $word, 'UTF-8' ) : strtolower( $word );
			}
		}
		return $map;
	}

	/**
	 * Varias marcas que quiere ("Toyota o Volkswagen", "toyota volkswagen") son una sola condición
	 * con alternativas: un vehículo no puede ser de dos marcas a la vez, así que pedirlas todas
	 * juntas nunca da nada. Las que no quiere van aparte (exclude) y no se tocan.
	 */
	private static function merge_brands( array $groups ) {
		$first = null;
		$out   = array();
		foreach ( $groups as $group ) {
			if ( 'brand' !== ( isset( $group['term_kind'] ) ? $group['term_kind'] : '' ) ) {
				$out[] = $group;
				continue;
			}
			if ( null === $first ) {
				$group['alts']   = array_values( array_unique( array_merge( array( $group['text'] ), isset( $group['alts'] ) ? (array) $group['alts'] : array() ) ) );
				$group['brands']       = array( $group['text'] );
				$group['brand_labels'] = array( $group['label'] );
				$out[]         = $group;
				$first         = count( $out ) - 1;
				continue;
			}
			$out[ $first ]['alts']  = array_values( array_unique( array_merge( $out[ $first ]['alts'], array( $group['text'] ), isset( $group['alts'] ) ? (array) $group['alts'] : array() ) ) );
			$out[ $first ]['brands'][]       = $group['text'];
			$out[ $first ]['brand_labels'][] = $group['label'];
			$labels                          = $out[ $first ]['brand_labels'];
			$last                            = array_pop( $labels );
			$out[ $first ]['label']          = implode( ', ', $labels ) . ' o ' . $last;
		}
		return $out;
	}

	/**
	 * Palabras de cada atributo: cada frase se vuelve a leer sola para saber qué atributo dio.
	 * "pickup" y "suv" → array( 'body' => 'pickup o suv' ).
	 *
	 * @param string[] $spans
	 * @return array<string,string>
	 */
	private function spans_by_key( array $spans ) {
		$out = array();
		foreach ( $spans as $span ) {
			$span = trim( (string) $span );
			if ( '' === $span ) {
				continue;
			}
			$alone = ASI_Attributes::parse( $this->packs, $span, $this->lexicon->language() );
			foreach ( array_keys( (array) $alone['constraints'] ) as $key ) {
				$out[ $key ] = isset( $out[ $key ] ) && false === strpos( ' ' . $out[ $key ] . ' ', ' ' . $span . ' ' ) ? $out[ $key ] . ' o ' . $span : ( isset( $out[ $key ] ) ? $out[ $key ] : $span );
			}
		}
		return $out;
	}

	/**
	 * La palabra de pedido del idioma ("quiero", "necesito", "busco"…) a la que se parece, con un
	 * error de dedo (una letra de más, de menos, cambiada o dos letras al revés); '' si ninguna.
	 */
	private function near_request_word( $word ) {
		$words = array_merge( $this->request_words, array_map( array( 'ASI_Normalizer', 'normalize' ), (array) $this->lexicon->get_list( 'fillers' ) ) );
		foreach ( array_unique( $words ) as $request ) {
			if ( strlen( $request ) < 5 || false !== strpos( $request, ' ' ) ) {
				continue;
			}
			if ( $request === $word ) {
				return '';
			}
			if ( levenshtein( $word, $request ) <= 1 || ( strlen( $word ) === strlen( $request ) && count_chars( $word, 1 ) === count_chars( $request, 1 ) && levenshtein( $word, $request ) <= 2 ) ) {
				return $request;
			}
		}
		return '';
	}

	/** "VOLKSWAGEN" → "Volkswagen" para decirlo en una frase; las siglas ("BMW", "BYD") se quedan. */
	public static function display_name( $name ) {
		$name = trim( (string) $name );
		if ( strlen( $name ) <= 4 || $name !== strtoupper( $name ) ) {
			return $name;
		}
		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );
		return ucwords( $lower, " -\t" );
	}
}
