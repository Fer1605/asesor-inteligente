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
	public static function prompt( $message, array $titles, array $actions, $site = '' ) {
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
	private static function numbers_in( $message ) {
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
		$answer = call_user_func( $ask, self::prompt( $clean, $titles, $actions, $site ) );
		if ( ! is_array( $answer ) || empty( $answer['text'] ) ) {
			return null;
		}
		$intent = self::parse( $answer['text'], count( $titles ), $actions, $clean );
		// Si las reglas ya entendieron todo menos el enganche o el plazo, solo sirve si la IA los leyó.
		if ( $intent && $need_fin && ! $intent['fin'] ) {
			$intent = null;
		}
		// Ahí las reglas ya sabían de qué vehículos hablaba: de la IA solo se toman los números.
		if ( $intent && $need_fin ) {
			$intent = array_merge( $intent, array( 'action' => 'finance', 'items' => array() ) );
		}
		if ( ! $intent ) {
			return array( 'response' => null, 'phrase' => '', 'intent' => null, 'model' => isset( $answer['model'] ) ? $answer['model'] : '' );
		}
		$phrase = self::phrase( $intent, $phrases );
		$result = self::try_phrase( $dialogue, $message, $phrase, $state );
		return array( 'response' => $result, 'phrase' => $result ? $phrase : '', 'intent' => $intent, 'model' => isset( $answer['model'] ) ? $answer['model'] : '' );
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
