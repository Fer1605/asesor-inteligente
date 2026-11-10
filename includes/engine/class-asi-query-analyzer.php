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

	public function __construct( ASI_Lexicon $lexicon, array $vocab, array $money = array() ) {
		$this->lexicon = $lexicon;
		$this->money   = ASI_Money::config( $money );
		$this->vocab   = array_merge( array( 'docs' => 0, 'tokens' => array(), 'phrases' => array() ), $vocab );

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
			'asks_price'  => '' !== ASI_Lexicon::find_phrase( $normalized, $this->lexicon->get_list( 'price_words' ) ),
			'corrections' => array(),
			'exclude'     => array(),
			'exclude_label' => '',
		);

		$text = $this->extract_prices( $message, $normalized, $result );
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
		$result['groups'] = array_merge( $result['groups'], $this->match_terms( $remaining ) );

		$contents = array();
		$labels   = array();
		foreach ( $result['groups'] as $group ) {
			$contents[] = $group['text'];
			$labels[]   = $group['label'];
		}
		$result['content'] = implode( ' ', $contents );
		$result['label']   = $this->span_label( $text, $display, $result['groups'], implode( ' ', $labels ) );

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
		return array(
			'text'      => $token['normal'],
			'alts'      => array_values( array_unique( array_merge( array( $token['normal'] ), $this->inflections( $token['normal'] ), $this->known_synonyms( $token['normal'] ) ) ) ),
			'kind'      => 'word',
			'critical'  => false,
			'label'     => $token['display'],
			'term_kind' => '',
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
	private function correct( $word ) {
		$length = strlen( $word );
		if ( $length < 4 || isset( $this->vocab['tokens'][ $word ] ) || isset( $this->phrase_index[ $word ] ) || ctype_digit( $word ) ) {
			return $word;
		}
		if ( $this->lexicon->synonyms( $word ) ) {
			return $word;
		}

		$max_distance = $length <= 5 ? 1 : 2;
		$best         = $word;
		$best_score   = PHP_INT_MAX;

		foreach ( $this->vocab['tokens'] as $candidate => $df ) {
			$candidate = (string) $candidate;
			if ( abs( strlen( $candidate ) - $length ) > $max_distance || strlen( $candidate ) < 4 ) {
				continue;
			}
			$distance = levenshtein( $word, $candidate );
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
		if ( '' === $after ) {
			return $text;
		}
		$negated = ( new self( $this->lexicon, $this->vocab ) )->analyze( $after );
		if ( ! $negated['groups'] ) {
			return $text;
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
		return $before;
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
}

