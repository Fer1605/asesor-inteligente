<?php
/**
 * Interpretación con IA (opcional, apagada por defecto). Lógica pura, sin WordPress.
 *
 * La IA NO le contesta al cliente: solo traduce un mensaje que las reglas no entendieron a una
 * orden cerrada (acción + números de las opciones a la vista, o unas palabras para buscar). Con esa
 * orden se arma una frase que las reglas ya saben responder ("cuotas de la 1 y la 3") y el plugin
 * contesta con sus propios datos. Así la IA no puede inventar precios, vehículos ni condiciones.
 *
 * Si la IA no responde, se equivoca de formato o propone algo que las reglas tampoco entienden, se
 * queda la respuesta original (la pregunta "no estoy seguro de haberte entendido").
 *
 * @package AsesorInteligente
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'ASI_CLI_TESTS' ) ) {
	exit;
}

class ASI_AI_Interpreter {

	const ACTIONS      = array( 'finance', 'quote', 'compare', 'detail', 'search', 'info', 'none' );
	const TOPICS       = array( 'contact', 'location', 'hours', 'financing' );
	const MAX_VISIBLE  = 8;
	const MAX_QUERY    = 6;
	const MAX_PLAIN_WORDS = 5; // Más palabras que esto: la IA revisa (hay más formas de malinterpretar).

	/** Respuestas de las reglas en las que vale la pena preguntarle a la IA. */
	const HELP_KINDS = array( 'unclear', 'none' );

	/** Respuestas que no cuentan como "ya entendió" al volver a preguntar con la frase de la IA. */
	const WEAK_KINDS = array( 'unclear', 'none', 'smalltalk' );

	/**
	 * ¿Las reglas no entendieron este mensaje?
	 */
	public static function needs_help( array $response ) {
		return isset( $response['kind'] ) && in_array( $response['kind'], self::HELP_KINDS, true );
	}

	/**
	 * ¿La respuesta con la frase de la IA sí es útil?
	 */
	public static function accepted( array $response ) {
		if ( ! isset( $response['kind'] ) ) {
			return false;
		}
		// Al cotizar, el chat pide los datos con una respuesta de conversación: eso sí es entender.
		if ( ! empty( $response['state']['pending'] ) ) {
			return true;
		}
		return ! in_array( $response['kind'], self::WEAK_KINDS, true );
	}

	/**
	 * Quita datos personales antes de mandar el mensaje fuera: correos, teléfonos, números largos
	 * (documentos, tarjetas) y enlaces.
	 */
	public static function scrub( $text ) {
		$text = preg_replace( '/[^\s@]+@[^\s@]+\.[^\s@]+/u', '[correo]', (string) $text );
		$text = preg_replace( '#https?://\S+#u', '[enlace]', $text );
		$text = preg_replace( '/\+?\d[\d\s\-\.]{6,}\d/u', '[numero]', $text );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Clave de la caché: idioma, si había opciones a la vista y el mensaje limpio.
	 */
	public static function cache_key( $lang, $message, $visible ) {
		return $lang . '|' . ( $visible ? 'v' : '-' ) . '|' . ASI_Normalizer::normalize( self::scrub( $message ) );
	}

	/**
	 * Instrucciones para la IA, en un solo mensaje (Gemma no acepta mensaje de sistema).
	 *
	 * @param string   $message   Mensaje del cliente (ya sin datos personales).
	 * @param string[] $titles    Opciones a la vista, en orden.
	 * @param string[] $actions   Acciones permitidas en este sitio.
	 * @param string   $site      Nombre del sitio.
	 */
	public static function prompt( $message, array $titles, array $actions, $site = '', $note = '' ) {
		$titles = array_slice( array_values( $titles ), 0, self::MAX_VISIBLE );
		$lines  = array();
		$lines[] = 'Eres el intérprete de un chat de ventas' . ( '' !== $site ? ' del sitio "' . $site . '"' : '' ) . '. NO le respondes al cliente: solo traduces su mensaje a una orden para el sistema.';
		$lines[] = 'El cliente puede escribir en español o inglés, con errores de dedo o modismos de Guatemala y Centroamérica.';
		$lines[] = '';
		if ( $titles ) {
			$lines[] = 'Opciones que el cliente tiene en pantalla:';
			foreach ( $titles as $i => $title ) {
				$lines[] = ( $i + 1 ) . '. ' . $title;
			}
		} else {
			$lines[] = 'El cliente no tiene opciones en pantalla.';
		}
		$lines[] = '';
		$lines[] = 'Acciones posibles:';
		$help = array(
			'finance' => 'finance: quiere saber cuánto pagaría: cuotas (también mal escrito: cuoa, cuots, cutoas), pagos al mes, a plazos, enganche, crédito o financiamiento de opciones en pantalla.',
			'quote'   => 'quote: pide expresamente una cotización o proforma (cotizar, cotízame, mándame la cotización). Pedir cuotas NO es cotizar.',
			'compare' => 'compare: quiere comparar opciones en pantalla.',
			'detail'  => 'detail: quiere más información de UNA opción en pantalla.',
			'search'  => 'search: busca otro producto. En "query" pon de 1 a ' . self::MAX_QUERY . ' palabras clave (tipo, marca, modelo, uso, presupuesto), sin datos personales.',
			'info'    => 'info: pregunta por datos del negocio. En "topic" pon contact (teléfono, correo, whatsapp), location (dirección, sucursales), hours (horario) o financing (cómo funciona el financiamiento en general).',
			'none'    => 'none: no se entiende o no tiene que ver con el sitio.',
		);
		foreach ( self::ACTIONS as $action ) {
			if ( in_array( $action, $actions, true ) || in_array( $action, array( 'search', 'info', 'none' ), true ) ) {
				$lines[] = '- ' . $help[ $action ];
			}
		}
		$lines[] = '';
		$lines[] = 'En "items" pon los números de las opciones a las que se refiere, o "all" si habla de todas, de cada una o de estas. Vacío si no aplica o si se refiere a lo último que vio.';
		if ( in_array( 'finance', $actions, true ) ) {
			$lines[] = 'Si da un enganche o un plazo, ponlo: "down_pct" solo si dice % o "por ciento"; si no, es un monto: "down_amount" en número completo (160 mil = 160000; "160" o "unos 150" de enganche son miles: 160000, 150000) con "currency" ("USD" si dice dólares, $, usd o "verdes"; "GTQ" si dice quetzales o Q; vacío si no dice), y "term_months" (meses: 4 años = 48). Si no lo dice, déjalos vacíos. No inventes números que no estén en el mensaje.';
		}
		$lines[] = 'Si dudas, usa "none". No inventes opciones que no están en la lista.';
		$lines[] = 'Responde SOLO con JSON en una línea, sin explicación: {"action":"finance","items":"all","query":"","topic":"","down_pct":null,"down_amount":null,"currency":"","term_months":null}';
		if ( '' !== $note ) {
			$lines[] = $note;
		}
		$lines[] = '';
		$lines[] = 'Mensaje del cliente: """' . str_replace( '"""', '"', $message ) . '"""';
		return implode( "\n", $lines );
	}

	/**
	 * Lee y valida lo que devolvió la IA.
	 *
	 * @param string   $raw     Texto de la IA.
	 * @param int      $visible Cuántas opciones hay a la vista.
	 * @param string[] $actions Acciones permitidas.
	 * @return array|null action, items (int[] base 1 | 'all'), query, topic
	 */
	public static function parse( $raw, $visible, array $actions, $message = '' ) {
		if ( ! preg_match( '/\{[^{}]*\}/s', (string) $raw, $m ) ) {
			return null;
		}
		$data = json_decode( $m[0], true );
		if ( ! is_array( $data ) || empty( $data['action'] ) || ! is_string( $data['action'] ) ) {
			return null;
		}
		$action  = strtolower( trim( $data['action'] ) );
		$visible = min( self::MAX_VISIBLE, max( 0, (int) $visible ) );
		$allowed = array_merge( $actions, array( 'search', 'info', 'none' ) );
		if ( ! in_array( $action, self::ACTIONS, true ) || ! in_array( $action, $allowed, true ) ) {
			return null;
		}

		$items = isset( $data['items'] ) ? $data['items'] : array();
		if ( is_string( $items ) && in_array( strtolower( trim( $items ) ), array( 'all', 'todas', 'todos' ), true ) ) {
			$items = 'all';
		} else {
			$list = array();
			foreach ( is_array( $items ) ? $items : preg_split( '/[^\d]+/', (string) $items ) as $item ) {
				$n = is_numeric( $item ) ? (int) $item : 0;
				if ( $n >= 1 && $n <= $visible && ! in_array( $n, $list, true ) ) {
					$list[] = $n;
				}
			}
			$items = $list;
		}

		$out = array( 'action' => $action, 'items' => $items, 'query' => '', 'topic' => '', 'fin' => array() );
		if ( in_array( $action, array( 'finance', 'quote' ), true ) ) {
			$out['fin'] = self::fin( $data, $message );
		}

		switch ( $action ) {
			case 'finance':
			case 'quote':
			case 'compare':
				// "Con 160 mil de enganche" sin nombrar vehículo: sobre la última tabla que vio.
				if ( 'finance' === $action && $out['fin'] && array() === $items ) {
					return $out;
				}
				if ( ! $visible ) {
					// Cuotas sin nada a la vista = cómo funciona el financiamiento; lo demás no aplica.
					return 'finance' === $action ? array_merge( $out, array( 'action' => 'info', 'items' => array(), 'topic' => 'financing' ) ) : null;
				}
				if ( array() === $items || ( 'compare' === $action && is_array( $items ) && count( $items ) < 2 ) ) {
					$out['items'] = 'all';
				}
				if ( 'compare' === $action && $visible < 2 ) {
					return null;
				}
				if ( is_array( $out['items'] ) && count( $out['items'] ) === $visible ) {
					$out['items'] = 'all';
				}
				return $out;

			case 'detail':
				if ( ! $visible || ! is_array( $items ) || ! $items ) {
					return 'all' === $items && 1 === $visible ? array_merge( $out, array( 'items' => array( 1 ) ) ) : null;
				}
				$out['items'] = array( $items[0] );
				return $out;

			case 'search':
				$query = self::clean_query( isset( $data['query'] ) ? (string) $data['query'] : '' );
				return '' === $query ? null : array_merge( $out, array( 'items' => array(), 'query' => $query ) );

			case 'info':
				$topic = isset( $data['topic'] ) ? strtolower( trim( (string) $data['topic'] ) ) : '';
				return in_array( $topic, self::TOPICS, true ) ? array_merge( $out, array( 'items' => array(), 'topic' => $topic ) ) : null;
		}
		return null; // none.
	}

	/**
	 * Enganche y plazo que leyó la IA, solo si esos números están en el mensaje del cliente (así no
	 * puede inventar un monto). 160 mil = 160000; 4 años = 48 meses.
	 *
	 * @return array pct, amt, cur, term (solo lo que vino)
	 */
	public static function fin( array $data, $message ) {
		$numbers = self::numbers_in( $message );
		$found   = function ( $value, $scales ) use ( $numbers ) {
			foreach ( $numbers as $n ) {
				foreach ( $scales as $scale ) {
					if ( abs( $n * $scale - $value ) <= max( 0.5, $value * 0.001 ) ) {
						return true;
					}
				}
			}
			return false;
		};
		$out = array();
		// Porcentaje solo si lo dijo como porcentaje ("20 de los verdes" es un monto, no 20 %).
		$says_pct = (bool) preg_match( '/%|por\s*ciento|percent/i', (string) $message );
		$amount   = isset( $data['down_amount'] ) && is_numeric( $data['down_amount'] ) ? (float) $data['down_amount'] : 0;
		if ( ! $says_pct && ! $amount && isset( $data['down_pct'] ) && is_numeric( $data['down_pct'] ) ) {
			$amount = (float) $data['down_pct'];
		}
		// Un enganche de "150" son 150 mil: nadie da 150 de enganche por un vehículo.
		if ( $amount > 0 && $amount < 1000 ) {
			$amount *= 1000;
		}
		if ( $says_pct && isset( $data['down_pct'] ) && is_numeric( $data['down_pct'] ) && $data['down_pct'] > 0 && $data['down_pct'] < 100 && $found( (float) $data['down_pct'], array( 1 ) ) ) {
			$out['pct'] = round( (float) $data['down_pct'], 1 );
		} elseif ( $amount >= 1000 && $found( $amount, array( 1, 1000, 1000000 ) ) ) {
			$out['amt'] = (int) round( $amount );
			// La moneda la deciden las palabras del cliente, no la IA: "$", "dólares", "verdes" → USD;
			// "Q", "quetzales" → GTQ; si no dice nada, la del sitio.
			$cur = '';
			if ( preg_match( '/(\$|d[oó]lar|dollar|usd|verdes)/iu', (string) $message ) ) {
				$cur = 'USD';
			} elseif ( preg_match( '/(quetzal|gtq|(^|[^a-z])q\.?\s?[0-9])/iu', (string) $message ) ) {
				$cur = 'GTQ';
			}
			$out['cur'] = $cur;
		}
		if ( isset( $data['term_months'] ) && is_numeric( $data['term_months'] ) && $data['term_months'] >= 6 && $data['term_months'] <= 120 && $found( (float) $data['term_months'], array( 1, 12 ) ) ) {
			$out['term'] = (int) $data['term_months'];
		}
		return $out;
	}

	/** Números escritos en el mensaje ("160", "160,000", "1.5", "ciento sesenta mil"). */
	public static function numbers_in( $message ) {
		preg_match_all( '/[0-9]+(?:[.,][0-9]+)*/', (string) $message, $m );
		$out = self::word_numbers( $message );
		foreach ( $m[0] as $raw ) {
			$out[] = (float) preg_replace( '/[^0-9]/', '', $raw );
			if ( preg_match( '/^([0-9]+)[.,]([0-9]{1,2})$/', $raw, $d ) ) {
				$out[] = (float) ( $d[1] . '.' . $d[2] );
			}
		}
		return $out;
	}

	/**
	 * Números en letras, en español e inglés básicos: "ciento sesenta mil" = 160000, "veinte" = 20,
	 * "cuarenta y cinco" = 45, "two hundred" = 200.
	 *
	 * @return float[]
	 */
	private static function word_numbers( $message ) {
		$units = array(
			'un' => 1, 'uno' => 1, 'una' => 1, 'dos' => 2, 'tres' => 3, 'cuatro' => 4, 'cinco' => 5, 'seis' => 6, 'siete' => 7, 'ocho' => 8, 'nueve' => 9,
			'diez' => 10, 'once' => 11, 'doce' => 12, 'trece' => 13, 'catorce' => 14, 'quince' => 15, 'dieciseis' => 16, 'diecisiete' => 17, 'dieciocho' => 18, 'diecinueve' => 19,
			'veinte' => 20, 'veinti' => 20, 'veintiuno' => 21, 'veintidos' => 22, 'veintitres' => 23, 'veinticuatro' => 24, 'veinticinco' => 25, 'veintiseis' => 26, 'veintisiete' => 27, 'veintiocho' => 28, 'veintinueve' => 29,
			'treinta' => 30, 'cuarenta' => 40, 'cincuenta' => 50, 'sesenta' => 60, 'setenta' => 70, 'ochenta' => 80, 'noventa' => 90,
			'cien' => 100, 'ciento' => 100, 'doscientos' => 200, 'trescientos' => 300, 'cuatrocientos' => 400, 'quinientos' => 500, 'seiscientos' => 600, 'setecientos' => 700, 'ochocientos' => 800, 'novecientos' => 900,
			'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10, 'twenty' => 20, 'thirty' => 30, 'forty' => 40, 'fifty' => 50, 'sixty' => 60, 'seventy' => 70, 'eighty' => 80, 'ninety' => 90,
		);
		$out     = array();
		$current = 0;
		$total   = 0;
		$active  = false;
		foreach ( preg_split( '/\s+/', ASI_Normalizer::normalize( (string) $message ) ) as $word ) {
			if ( isset( $units[ $word ] ) ) {
				$current += $units[ $word ];
				$active   = true;
			} elseif ( $active && in_array( $word, array( 'y', 'and' ), true ) ) {
				continue;
			} elseif ( $active && in_array( $word, array( 'hundred' ), true ) ) {
				$current *= 100;
			} elseif ( in_array( $word, array( 'mil', 'thousand' ), true ) ) {
				$total  += max( 1, $current ) * 1000;
				$current = 0;
				$active  = true;
			} elseif ( $active && in_array( $word, array( 'millon', 'millones', 'million' ), true ) ) {
				$total  += max( 1, $current ) * 1000000;
				$current = 0;
			} elseif ( $active ) {
				$out[]   = (float) ( $total + $current );
				$total   = 0;
				$current = 0;
				$active  = false;
			}
		}
		if ( $active ) {
			$out[] = (float) ( $total + $current );
		}
		return $out;
	}

	/**
	 * Palabras para buscar: solo letras, números cortos y espacios. Nada de correos ni teléfonos.
	 */
	public static function clean_query( $query ) {
		$query = preg_replace( '/<[^>]*>/', ' ', (string) $query );
		$query = preg_replace( '/[^\s@]+@[^\s@]+\.[^\s@]+/u', ' ', $query );
		$query = preg_replace( '#https?://\S+#u', ' ', $query );
		$query = preg_replace( '/\d[\d\-\.]{4,}/', ' ', $query );
		$query = preg_replace( '/[^\p{L}\p{N}\s\-]+/u', ' ', $query );
		$words = array_slice( preg_split( '/\s+/u', trim( $query ), -1, PREG_SPLIT_NO_EMPTY ), 0, self::MAX_QUERY );
		$query = implode( ' ', $words );
		return function_exists( 'mb_substr' ) ? mb_substr( $query, 0, 60 ) : substr( $query, 0, 60 );
	}

	/**
	 * Frase que las reglas ya saben responder, con las plantillas del idioma ('ai_phrases').
	 *
	 * @param array $intent  Resultado de parse().
	 * @param array $phrases Plantillas del idioma.
	 * @return string
	 */
	public static function phrase( array $intent, array $phrases ) {
		$action = $intent['action'];
		if ( 'search' === $action ) {
			return $intent['query'];
		}
		if ( 'info' === $action ) {
			return isset( $phrases[ $intent['topic'] ] ) ? $phrases[ $intent['topic'] ] : '';
		}
		$fin = self::fin_phrase( isset( $intent['fin'] ) ? (array) $intent['fin'] : array(), $phrases );
		if ( 'finance' === $action && '' !== $fin && array() === $intent['items'] && isset( $phrases['finance_fin'] ) ) {
			return str_replace( '{fin}', $fin, $phrases['finance_fin'] );
		}
		if ( 'all' === $intent['items'] && isset( $phrases[ $action . '_all' ] ) ) {
			return trim( $phrases[ $action . '_all' ] . ' ' . $fin );
		}
		if ( ! is_array( $intent['items'] ) || ! $intent['items'] || ! isset( $phrases[ $action ], $phrases['item'], $phrases['join'] ) ) {
			return '';
		}
		$parts = array();
		foreach ( $intent['items'] as $n ) {
			$parts[] = str_replace( '{n}', (string) $n, $phrases['item'] );
		}
		return trim( str_replace( '{items}', implode( $phrases['join'], $parts ), $phrases[ $action ] ) . ( 'quote' === $action && '' !== $fin ? ' ' . $phrases['with_finance'] : '' ) . ' ' . $fin );
	}

	/** "con q160000 de enganche a 48 meses": en la forma que las reglas leen sin dudar. */
	public static function fin_phrase( array $fin, array $phrases ) {
		$parts = array();
		if ( isset( $fin['pct'] ) && isset( $phrases['fin_pct'] ) ) {
			$parts[] = str_replace( '{pct}', (string) $fin['pct'], $phrases['fin_pct'] );
		} elseif ( isset( $fin['amt'] ) && isset( $phrases['fin_amount'] ) ) {
			$symbol  = 'USD' === ( isset( $fin['cur'] ) ? $fin['cur'] : '' ) ? '$' : ( 'GTQ' === ( isset( $fin['cur'] ) ? $fin['cur'] : '' ) ? 'q' : '' );
			$parts[] = str_replace( '{amount}', $symbol . $fin['amt'], $phrases['fin_amount'] );
		}
		if ( isset( $fin['term'] ) && isset( $phrases['fin_term'] ) ) {
			$parts[] = str_replace( '{n}', (string) $fin['term'], $phrases['fin_term'] );
		}
		return implode( ' ', $parts );
	}

	/**
	 * Todo el rescate: pregunta a la IA, valida y vuelve a pasar la frase por las reglas.
	 *
	 * @param ASI_Dialogue $dialogue Diálogo del mensaje.
	 * @param string       $message  Mensaje original.
	 * @param array        $state    Estado que mandó el navegador.
	 * @param string[]     $titles   Opciones a la vista, en orden.
	 * @param string[]     $actions  Acciones que permite el sitio.
	 * @param array        $phrases  Plantillas del idioma.
	 * @param callable     $ask      function( string $prompt ): array|null  { text, model }
	 * @param string       $site     Nombre del sitio.
	 * @return array|null  { response, phrase, intent, model } o null si no ayudó.
	 */
	public static function rescue( ASI_Dialogue $dialogue, $message, array $state, array $titles, array $actions, array $phrases, callable $ask, $site = '', $need_fin = false ) {
		$clean = self::scrub( $message );
		if ( '' === $clean ) {
			return null;
		}
		$note   = $need_fin ? 'Contexto: el cliente está viendo una tabla de cuotas y su mensaje habla de cuánto da de enganche o en cuánto tiempo paga; usa "finance" y llena down_amount / down_pct / term_months.' : '';
		$answer = call_user_func( $ask, self::prompt( $clean, $titles, $actions, $site, $note ) );
		if ( ! is_array( $answer ) || empty( $answer['text'] ) ) {
			return null;
		}
		if ( $need_fin ) {
			// Las reglas ya sabían que eran cuotas y de qué vehículos: de la IA solo se toman los números
			// (validados contra el mensaje), diga la acción que diga.
			$data   = preg_match( '/\{[^{}]*\}/s', (string) $answer['text'], $m ) ? json_decode( $m[0], true ) : null;
			$fin    = is_array( $data ) ? self::fin( $data, $clean ) : array();
			$intent = $fin ? array( 'action' => 'finance', 'items' => array(), 'query' => '', 'topic' => '', 'fin' => $fin ) : null;
		} else {
			$intent = self::parse( $answer['text'], count( $titles ), $actions, $clean );
		}
		if ( ! $intent ) {
			return array( 'response' => null, 'phrase' => '', 'intent' => null, 'model' => isset( $answer['model'] ) ? $answer['model'] : '' );
		}
		$phrase = self::phrase( $intent, $phrases );
		$result = self::try_phrase( $dialogue, $message, $phrase, $state );
		return array( 'response' => $result, 'phrase' => $result ? $phrase : '', 'intent' => $intent, 'model' => isset( $answer['model'] ) ? $answer['model'] : '' );
	}

	// ------------------------------------------------------------------
	// Modo "lee todo": la IA revisa cada búsqueda y corrige solo si entendió otra cosa
	// ------------------------------------------------------------------

	/** Respuestas que la IA revisa en modo "lee todo": búsquedas y lo que las reglas no entendieron. */
	const REVIEW_KINDS = array( 'results', 'info', 'unclear', 'none' );

	/**
	 * ¿Esta respuesta se revisa con la IA? Búsquedas normales: no un formulario a medias (datos
	 * personales) ni la recomendación por perfil (esa ya la arma el asesor).
	 */
	public static function reviewable( array $response, array $state, $message, array $signals = array() ) {
		if ( ! isset( $response['kind'] ) || ! in_array( $response['kind'], self::REVIEW_KINDS, true ) ) {
			return false;
		}
		// Búsquedas: la IA lee cuando el chat tuvo que adivinar o hay riesgo de malinterpretar.
		if ( 'results' === $response['kind'] && ! self::worth_asking( $message, $response, $signals ) ) {
			return false;
		}
		// Información (ubicaciones, horarios, contacto, quiénes somos): la contesta el sitio, sin IA.
		// Solo si trae un monto: "camionetas con presupuesto no mayor a 500 mil" no es pedir una cotización.
		if ( 'info' === $response['kind'] && ! self::has_amount( $message ) ) {
			return false;
		}
		if ( ! empty( $state['pending'] ) ) {
			return false;
		}
		// Una búsqueda sin texto es la recomendación por perfil: esa la arma el asesor.
		if ( 'results' === $response['kind'] && empty( $response['state']['q'] ) ) {
			return false;
		}
		return '' !== self::scrub( $message );
	}

	/**
	 * ¿Vale la pena gastar una consulta en esta búsqueda? Las reglas ya contestaron; la IA solo
	 * revisa lo que tiene riesgo de malinterpretarse:
	 * - montos o números ("no mayor a 100 mil", "unos 25 mil dólares"),
	 * - negaciones, comparaciones y giros ("que no sea", "ni", "más barato", "pero", "mejor"),
	 * - mensajes largos (más de MAX_PLAIN_WORDS palabras),
	 * - búsquedas en las que las reglas solo encontraron algo parecido.
	 * "SUV", "toyota", "pickup 4x4" o un botón que tocó el cliente no gastan.
	 *
	 * @param string   $message  Mensaje.
	 * @param array    $response Respuesta de las reglas.
	 * @param string[] $signals  Palabras de riesgo del idioma ('ai_signals'). Vacío = siempre revisar.
	 */
	public static function worth_asking( $message, array $response, array $signals ) {
		// Las reglas tuvieron que adivinar (error de dedo, palabras que no son del sitio): lee la IA.
		if ( ! $signals || ! empty( $response['learn'] ) || ! empty( $response['guessed'] ) ) {
			return true;
		}
		$normal = ASI_Normalizer::normalize( (string) $message );
		$words  = preg_split( '/\s+/', $normal, -1, PREG_SPLIT_NO_EMPTY );
		// "4x4" o "4x2" son tracción, no números; "una camioneta" no es un monto.
		$plain  = preg_replace( '/\b[0-9]+x[0-9]+\b/', ' ', $normal );
		$big    = array_filter(
			self::word_numbers( $plain ),
			function ( $n ) {
				return $n >= 10;
			}
		);
		if ( count( $words ) > self::MAX_PLAIN_WORDS || preg_match( '/[0-9]/', $plain ) || $big ) {
			return true;
		}
		$padded = ' ' . $normal . ' ';
		foreach ( $signals as $signal ) {
			$signal = ASI_Normalizer::normalize( (string) $signal );
			if ( '' !== $signal && false !== strpos( $padded, ' ' . $signal . ' ' ) ) {
				return true;
			}
		}
		return false;
	}

	/** ¿El mensaje trae un número que podría ser un monto ("500 mil", "cien mil")? "4x4" no cuenta. */
	public static function has_amount( $message ) {
		$plain = preg_replace( '/\b[0-9]+x[0-9]+\b/', ' ', ASI_Normalizer::normalize( (string) $message ) );
		if ( preg_match( '/[0-9]/', $plain ) ) {
			return true;
		}
		foreach ( self::word_numbers( $plain ) as $n ) {
			if ( $n >= 10 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Instrucciones del modo "lee todo": la IA devuelve cómo queda la búsqueda completa.
	 *
	 * @param string $message Mensaje del cliente (sin datos personales).
	 * @param string $current Lo que venía buscando (texto acumulado), o ''.
	 * @param string $site    Nombre del sitio.
	 */
	public static function review_prompt( $message, $current, $site = '', $reading = '' ) {
		$lines   = array();
		$lines[] = 'Eres el intérprete de un chat de ventas de vehículos' . ( '' !== $site ? ' del sitio "' . $site . '"' : '' ) . '. NO le respondes al cliente: traduces lo que busca a filtros.';
		$lines[] = 'El cliente escribe en español o inglés, con errores de dedo y modismos de Guatemala.';
		$lines[] = '';
		$lines[] = '' !== trim( $current ) ? 'Lo que venía buscando (mensajes anteriores juntos): """' . str_replace( '"""', '"', $current ) . '"""' : 'No venía buscando nada.';
		$lines[] = 'Mensaje nuevo: """' . str_replace( '"""', '"', $message ) . '"""';
		if ( '' !== trim( $reading ) ) {
			$lines[] = 'El chat ya lo leyó así (con los errores de dedo corregidos): """' . str_replace( '"""', '"', $reading ) . '"""';
			$lines[] = 'Si esa lectura es correcta, devuelve esos mismos filtros. Corrígela solo si el chat entendió mal.';
		}
		$lines[] = '';
		$lines[] = 'Vocabulario de Guatemala: "camioneta" es una SUV o una pickup, NUNCA un camión (camión es un vehículo de carga grande). "Carro" es cualquier automóvil. "Picop"/"palangana" es pickup. "Troca" es pickup o camión pequeño. Con errores de dedo, quédate con la palabra más parecida ("camiooneeta" = camioneta).';
		$lines[] = 'Devuelve la búsqueda COMPLETA como queda después del mensaje nuevo:';
		$lines[] = '- Si el mensaje afina ("solo toyota", "que no pase de 100 mil", "y diésel"), conserva lo anterior y suma lo nuevo.';
		$lines[] = '- Si cambia de tema ("ahora busco un pickup"), empieza de cero.';
		$lines[] = '- Un monto nuevo reemplaza al anterior. "No mayor a", "que no pase de", "no más de", "hasta", "menos de" = max. "Mayor a", "más de", "desde", "arriba de" = min. "Tengo 200 mil" = max.';
		$lines[] = '- "solo X" o "quiero X" NO es excluir. Excluir es "que no sea X", "sin X", "menos X", "no quiero X".';
		$lines[] = '- Montos en número completo: 100 mil = 100000, 1.5 millones = 1500000.';
		$lines[] = '- Si da un monto aproximado ("unos 250 mil", "alrededor de", "como de", "más o menos"), ponlo en "around" y deja min y max en null.';
		$lines[] = '';
		$lines[] = 'Campos: "type" tipo de vehículo (camioneta, suv, pickup, sedan, hatchback, microbus, panel, camion…; si dice "camioneta" pon "camioneta") o vacío; "brands" marcas que quiere; "words" otras características (4x4, diesel, automatica, hibrido, 7 pasajeros, año, modelo) o vacío; "exclude" marcas o tipos que NO quiere; "min" y "max" montos o null; "around" monto aproximado o null; "currency" "USD" si dice dólares/$, "GTQ" si dice quetzales/Q, si no vacío.';
		$lines[] = 'Si el mensaje no es una búsqueda (pregunta cuotas, cotizar, horario, saludo…), responde {"search":false}.';
		$lines[] = 'Responde SOLO con JSON en una línea, sin explicación: {"search":true,"type":"suv","brands":["toyota"],"words":"","exclude":[],"min":null,"max":100000,"around":null,"currency":""}';
		return implode( "\n", $lines );
	}

	/**
	 * Lee la búsqueda que devolvió la IA. Los montos solo valen si están escritos en el mensaje o
	 * en lo que venía buscando (la IA no puede inventar un presupuesto).
	 *
	 * @return array|null type, brands[], words, exclude[], min, max, currency — o null si no es búsqueda.
	 */
	public static function parse_review( $raw, $message, $current = '' ) {
		if ( ! preg_match( '/\{.*\}/s', (string) $raw, $m ) ) {
			return null;
		}
		$data = json_decode( $m[0], true );
		if ( ! is_array( $data ) || empty( $data['search'] ) ) {
			return null;
		}
		$words = function ( $value, $max_items ) {
			$list = is_array( $value ) ? $value : ( '' === trim( (string) $value ) ? array() : array( $value ) );
			$out  = array();
			foreach ( array_slice( $list, 0, $max_items ) as $item ) {
				$item = self::clean_query( is_scalar( $item ) ? (string) $item : '' );
				if ( '' !== $item && ! in_array( $item, $out, true ) ) {
					$out[] = $item;
				}
			}
			return $out;
		};
		$numbers = array_merge( self::numbers_in( $message ), self::numbers_in( $current ) );
		$amount  = function ( $key ) use ( $data, $numbers ) {
			if ( ! isset( $data[ $key ] ) || ! is_numeric( $data[ $key ] ) || $data[ $key ] <= 0 ) {
				return null;
			}
			$value = (float) $data[ $key ];
			foreach ( $numbers as $n ) {
				foreach ( array( 1, 1000, 1000000 ) as $scale ) {
					if ( abs( $n * $scale - $value ) <= max( 0.5, $value * 0.001 ) ) {
						return $value;
					}
				}
			}
			return null;
		};
		$spec = array(
			'type'     => implode( ' ', $words( isset( $data['type'] ) ? $data['type'] : '', 1 ) ),
			'brands'   => $words( isset( $data['brands'] ) ? $data['brands'] : array(), 3 ),
			'words'    => implode( ' ', $words( isset( $data['words'] ) ? $data['words'] : '', 4 ) ),
			'exclude'  => $words( isset( $data['exclude'] ) ? $data['exclude'] : array(), 3 ),
			'min'      => $amount( 'min' ),
			'max'      => $amount( 'max' ),
			'around'   => $amount( 'around' ),
			'currency' => '',
		);
		if ( null !== $spec['around'] ) {
			$spec['min'] = null;
			$spec['max'] = null;
		}
		if ( null !== $spec['min'] && null !== $spec['max'] && $spec['min'] >= $spec['max'] ) {
			$spec['min'] = null;
		}
		// La moneda la deciden las palabras del cliente, no la IA.
		$said = $message . ' ' . $current;
		if ( preg_match( '/(\$|d[oó]lar|dollar|usd|verdes)/iu', $said ) ) {
			$spec['currency'] = 'USD';
		} elseif ( preg_match( '/(quetzal|gtq|(^|[^a-z])q\.?\s?[0-9])/iu', $said ) ) {
			$spec['currency'] = 'GTQ';
		}
		if ( '' === $spec['type'] && ! $spec['brands'] && '' === $spec['words'] && null === $spec['min'] && null === $spec['max'] && null === $spec['around'] ) {
			return null;
		}
		return $spec;
	}

	/**
	 * La búsqueda de la IA como frase que las reglas leen sin dudar:
	 * "suv toyota hasta q100000 que no sea nissan".
	 */
	public static function search_phrase( array $spec, array $phrases ) {
		$parts  = array_filter( array( $spec['type'], implode( ' ', $spec['brands'] ), $spec['words'] ), 'strlen' );
		$symbol = 'USD' === $spec['currency'] ? '$' : ( 'GTQ' === $spec['currency'] ? 'q' : '' );
		$money  = function ( $v ) use ( $symbol ) {
			return $symbol . (int) round( $v );
		};
		if ( ! empty( $spec['around'] ) && isset( $phrases['search_around'] ) ) {
			$parts[] = str_replace( '{amount}', $money( $spec['around'] ), $phrases['search_around'] );
		} elseif ( null !== $spec['min'] && null !== $spec['max'] && isset( $phrases['search_range'] ) ) {
			$parts[] = str_replace( array( '{min}', '{max}' ), array( $money( $spec['min'] ), $money( $spec['max'] ) ), $phrases['search_range'] );
		} elseif ( null !== $spec['max'] && isset( $phrases['search_max'] ) ) {
			$parts[] = str_replace( '{amount}', $money( $spec['max'] ), $phrases['search_max'] );
		} elseif ( null !== $spec['min'] && isset( $phrases['search_min'] ) ) {
			$parts[] = str_replace( '{amount}', $money( $spec['min'] ), $phrases['search_min'] );
		}
		if ( $spec['exclude'] && isset( $phrases['search_not'] ) ) {
			$parts[] = str_replace( '{words}', implode( ' ', $spec['exclude'] ), $phrases['search_not'] );
		}
		return trim( implode( ' ', $parts ) );
	}

	/**
	 * Qué entendió el analizador, para comparar dos lecturas: términos, atributos, montos y exclusiones.
	 */
	public static function signature( array $query ) {
		$terms = function ( $groups ) {
			$out = array();
			foreach ( (array) $groups as $g ) {
				$out[] = isset( $g['text'] ) ? (string) $g['text'] : '';
			}
			sort( $out );
			return $out;
		};
		$attrs = isset( $query['attrs'] ) ? (array) $query['attrs'] : array();
		ksort( $attrs );
		return json_encode(
			array(
				$terms( $query['groups'] ),
				$attrs,
				null === $query['price_min'] ? null : (float) $query['price_min'],
				null === $query['price_max'] ? null : (float) $query['price_max'],
				$terms( isset( $query['exclude'] ) ? $query['exclude'] : array() ),
			)
		);
	}

	/**
	 * Revisión completa de una búsqueda ya contestada por las reglas.
	 *
	 * @param ASI_Dialogue       $dialogue Diálogo.
	 * @param ASI_Query_Analyzer $analyzer Analizador del sitio (para comparar lecturas).
	 * @param string             $message  Mensaje original.
	 * @param array              $state    Estado ANTES del mensaje.
	 * @param array              $response Respuesta de las reglas.
	 * @param array              $phrases  Plantillas del idioma.
	 * @param callable           $ask      function( string $prompt ): array|null { text, model }
	 * @return array { response|null (null = las reglas tenían razón), phrase, model, asked }
	 */
	public static function review( ASI_Dialogue $dialogue, ASI_Query_Analyzer $analyzer, $message, array $state, array $response, array $phrases, callable $ask, $site = '', $cached = null ) {
		$current = isset( $state['q'] ) && is_string( $state['q'] ) ? self::scrub( $state['q'] ) : '';
		$out     = array( 'response' => null, 'phrase' => '', 'model' => '', 'asked' => false );
		if ( null !== $cached ) {
			$phrase = (string) $cached;
		} else {
			$reading = isset( $response['state']['q'] ) ? self::scrub( (string) $response['state']['q'] ) : '';
			$answer  = call_user_func( $ask, self::review_prompt( self::scrub( $message ), $current, $site, $reading ) );
			if ( ! is_array( $answer ) || empty( $answer['text'] ) ) {
				return $out;
			}
			$out['asked'] = true;
			$out['model'] = isset( $answer['model'] ) ? $answer['model'] : '';
			$spec         = self::parse_review( $answer['text'], self::scrub( $message ), $current );
			$phrase       = $spec ? self::search_phrase( $spec, $phrases ) : '';
		}
		$out['phrase'] = $phrase;
		if ( '' === $phrase ) {
			return $out;
		}
		// ¿Entendieron lo mismo? Se compara lo que buscaron las reglas con la frase de la IA.
		$rules_q = isset( $response['state']['q'] ) ? (string) $response['state']['q'] : '';
		if ( 'results' === $response['kind'] && self::signature( $analyzer->analyze( $rules_q ) ) === self::signature( $analyzer->analyze( $phrase ) ) ) {
			return $out;
		}
		// Entendieron otra cosa: se busca con la frase de la IA como búsqueda completa (no se suma a la anterior).
		$fresh      = $state;
		$fresh['q'] = '';
		$new        = $dialogue->handle( $phrase, $fresh );
		// Solo si con su lectura sí hay resultados exactos (no "estas se acercan").
		if ( isset( $new['kind'] ) && 'results' === $new['kind'] && ! empty( $new['cards'] ) && empty( $new['learn'] ) ) {
			$out['response'] = $new;
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// Modo "lee todo": acciones con vehículos (cuotas, cotizar, comparar)
	// ------------------------------------------------------------------

	/**
	 * Qué hicieron las reglas con las opciones: acción y vehículos.
	 *
	 * @return array|null { action: finance|quote|compare, ids: int[] }
	 */
	public static function rules_action( array $response ) {
		$state = isset( $response['state'] ) && is_array( $response['state'] ) ? $response['state'] : array();
		if ( ! empty( $state['pending']['action'] ) && 'quote' === $state['pending']['action'] ) {
			return array( 'action' => 'quote', 'ids' => array_map( 'intval', (array) $state['pending']['ids'] ) );
		}
		$kind = isset( $response['kind'] ) ? $response['kind'] : '';
		if ( 'finance' === $kind && ! empty( $state['fin_ids'] ) ) {
			// Más de 4: las que quedaron pendientes para "sí" también cuentan como leídas.
			$rest = ! empty( $state['offer']['action'] ) && 'finance' === $state['offer']['action'] ? (array) $state['offer']['ids'] : array();
			return array( 'action' => 'finance', 'ids' => array_map( 'intval', array_merge( (array) $state['fin_ids'], $rest ) ) );
		}
		if ( 'compare' === $kind && ! empty( $state['selected'] ) ) {
			return array( 'action' => 'compare', 'ids' => array_map( 'intval', (array) $state['selected'] ) );
		}
		return null;
	}

	/**
	 * ¿Vale la pena que la IA revise esta acción? Solo con riesgo de haber tomado mal las opciones:
	 * - nombra dos o más opciones ("la 2, 6 y 1", "la primera y la quinta"),
	 * - o las reglas empezaron una cotización sin que dijera "cotizar" ("¿y la 6?").
	 *
	 * @param string[]       $quote_words Palabras de cotizar del idioma.
	 * @param array<string,int> $ordinals Ordinales del idioma ("tercera" => 3).
	 */
	public static function action_worth_asking( $message, array $response, array $state, array $quote_words, array $ordinals ) {
		$done    = self::rules_action( $response );
		$visible = isset( $state['results'] ) ? count( (array) $state['results'] ) : 0;
		if ( ! $done || $visible < 2 || '' === self::scrub( $message ) ) {
			return false;
		}
		$normal = ASI_Normalizer::normalize( (string) $message );
		$refs   = 0;
		foreach ( preg_split( '/\s+/', $normal, -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
			if ( ( ctype_digit( $token ) && (int) $token >= 1 && (int) $token <= $visible ) || ( ! ctype_digit( $token ) && isset( $ordinals[ $token ] ) ) ) {
				$refs++;
			}
		}
		if ( $refs >= 2 ) {
			return true;
		}
		if ( 'quote' === $done['action'] ) {
			foreach ( $quote_words as $word ) {
				$word = ASI_Normalizer::normalize( (string) $word );
				if ( '' !== $word && false !== strpos( ' ' . $normal . ' ', ' ' . $word . ' ' ) ) {
					return false;
				}
			}
			return ! preg_match( '/(^|\s)cotiz/', $normal );
		}
		return false;
	}

	/**
	 * ¿Se corrige a las reglas con lo que entendió la IA? Solo en dos casos claros:
	 * - misma acción, pero la IA vio más opciones y las de las reglas están todas incluidas
	 *   ("cuotas de la 2, 6 y 1": las reglas vieron 2 y 1, la IA 2, 6 y 1);
	 * - las reglas empezaron una cotización y la IA ve cuotas o detalle ("¿y la 6?").
	 *
	 * @param array $done    rules_action().
	 * @param array $intent  parse() de la IA.
	 * @param int[] $visible Ids a la vista, en orden.
	 */
	public static function action_override( array $done, array $intent, array $visible ) {
		$ai_ids = array();
		if ( 'all' === $intent['items'] ) {
			$ai_ids = array_map( 'intval', $visible );
		} else {
			foreach ( (array) $intent['items'] as $n ) {
				if ( isset( $visible[ $n - 1 ] ) ) {
					$ai_ids[] = (int) $visible[ $n - 1 ];
				}
			}
		}
		if ( $intent['action'] === $done['action'] ) {
			return count( $ai_ids ) > count( $done['ids'] ) && ! array_diff( $done['ids'], $ai_ids );
		}
		return 'quote' === $done['action'] && in_array( $intent['action'], array( 'finance', 'detail' ), true );
	}

	/**
	 * Revisión completa de una acción ya contestada por las reglas.
	 *
	 * @return array { response|null, phrase, model, asked }
	 */
	public static function review_action( ASI_Dialogue $dialogue, $message, array $state, array $response, array $titles, array $actions, array $phrases, callable $ask, $site = '', $cached = null ) {
		$out     = array( 'response' => null, 'phrase' => '', 'model' => '', 'asked' => false );
		$done    = self::rules_action( $response );
		$visible = array_map( 'intval', isset( $state['results'] ) ? (array) $state['results'] : array() );
		if ( ! $done || ! $titles ) {
			return $out;
		}
		if ( null !== $cached ) {
			$phrase = (string) $cached;
		} else {
			$answer = call_user_func( $ask, self::prompt( self::scrub( $message ), $titles, $actions, $site, 'Contexto: el cliente acaba de ver una lista numerada; si nombra varias opciones (por número u ordinal), pon TODAS en "items". "¿Y la 6?" justo después de ver cuotas es pedir las cuotas de la 6, no cotizar.' ) );
			if ( ! is_array( $answer ) || empty( $answer['text'] ) ) {
				return $out;
			}
			$out['asked'] = true;
			$out['model'] = isset( $answer['model'] ) ? $answer['model'] : '';
			$intent       = self::parse( $answer['text'], count( $titles ), $actions, self::scrub( $message ) );
			$phrase       = $intent && self::action_override( $done, $intent, array_slice( $visible, 0, count( $titles ) ) ) ? self::phrase( $intent, $phrases ) : '';
		}
		$out['phrase'] = $phrase;
		if ( '' === $phrase ) {
			return $out;
		}
		$out['response'] = self::try_phrase( $dialogue, $message, $phrase, $state );
		return $out;
	}

	/**
	 * Pasa una frase (de la IA o de la caché) por las reglas. Null si tampoco la entienden.
	 */
	public static function try_phrase( ASI_Dialogue $dialogue, $message, $phrase, array $state ) {
		$phrase = trim( (string) $phrase );
		if ( '' === $phrase || ASI_Normalizer::normalize( $phrase ) === ASI_Normalizer::normalize( $message ) ) {
			return null;
		}
		$response = $dialogue->handle( $phrase, $state );
		return self::accepted( $response ) ? $response : null;
	}
}
