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
			'finance' => 'finance: quiere cuotas, pagos mensuales, enganche o financiamiento de opciones en pantalla.',
			'quote'   => 'quote: quiere una cotización de opciones en pantalla.',
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
		$lines[] = 'En "items" pon los números de las opciones a las que se refiere, o "all" si habla de todas, de cada una o de estas. Vacío si no aplica.';
		$lines[] = 'Si dudas, usa "none". No inventes opciones que no están en la lista.';
		$lines[] = 'Responde SOLO con JSON en una línea, sin explicación: {"action":"finance","items":"all","query":"","topic":""}';
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
	public static function parse( $raw, $visible, array $actions ) {
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

		$out = array( 'action' => $action, 'items' => $items, 'query' => '', 'topic' => '' );

		switch ( $action ) {
			case 'finance':
			case 'quote':
			case 'compare':
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
		if ( 'all' === $intent['items'] && isset( $phrases[ $action . '_all' ] ) ) {
			return $phrases[ $action . '_all' ];
		}
		if ( ! is_array( $intent['items'] ) || ! $intent['items'] || ! isset( $phrases[ $action ], $phrases['item'], $phrases['join'] ) ) {
			return '';
		}
		$parts = array();
		foreach ( $intent['items'] as $n ) {
			$parts[] = str_replace( '{n}', (string) $n, $phrases['item'] );
		}
		return str_replace( '{items}', implode( $phrases['join'], $parts ), $phrases[ $action ] );
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
	public static function rescue( ASI_Dialogue $dialogue, $message, array $state, array $titles, array $actions, array $phrases, callable $ask, $site = '' ) {
		$clean = self::scrub( $message );
		if ( '' === $clean ) {
			return null;
		}
		$answer = call_user_func( $ask, self::prompt( $clean, $titles, $actions, $site ) );
		if ( ! is_array( $answer ) || empty( $answer['text'] ) ) {
			return null;
		}
		$intent = self::parse( $answer['text'], count( $titles ), $actions );
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
