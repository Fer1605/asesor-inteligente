<?php
/**
 * Conexión con la IA (opcional, apagada por defecto): servicios gratuitos que ya tienen los modelos
 * open source funcionando (Groq: Llama, Qwen, gpt-oss; Google AI Studio: Gemma). El sitio no aloja
 * ningún modelo: manda la frase y recibe una orden corta.
 *
 * - Solo se usa cuando las reglas no entendieron (ver ASI_AI_Interpreter).
 * - Cadena de respaldo: cada proveedor y cada modelo tienen su propio límite gratis; si uno se agota
 *   (error 429) se salta un rato y se prueba el siguiente. Si se acaban todos, el chat sigue como sin IA.
 * - Tope diario de consultas por sitio, para no pasar del nivel gratis.
 * - Caché: una frase ya interpretada no vuelve a consultar a la IA.
 * - Las claves se guardan en su propia opción (no se cargan en cada página) y nunca se muestran.
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || exit;

class ASI_AI {

	const KEYS_OPTION  = 'asi_ai_keys';
	const USAGE_OPTION = 'asi_ai_usage';
	const CACHE_OPTION = 'asi_ai_cache';
	const CACHE_MAX    = 1000;
	const TIMEOUT      = 6;   // Segundos por consulta.
	const DEADLINE     = 9;   // Segundos en total por mensaje (toda la cadena).

	const PROVIDERS = array(
		'groq'   => array(
			'label'    => 'Groq',
			'endpoint' => 'https://api.groq.com/openai/v1/chat/completions',
			'models'   => 'https://api.groq.com/openai/v1/models',
			'console'  => 'https://console.groq.com/keys',
			'default'  => 'openai/gpt-oss-120b, openai/gpt-oss-20b, qwen/qwen3.8-27b',
		),
		'google' => array(
			'label'    => 'Google AI Studio (Gemma)',
			'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
			'models'   => 'https://generativelanguage.googleapis.com/v1beta/openai/models',
			'console'  => 'https://aistudio.google.com/apikey',
			'default'  => 'gemma-3-27b-it, gemma-3-12b-it',
		),
	);

	/**
	 * Modelos recomendados para esta tarea (solo interpretar, respuestas cortas), del más barato al más
	 * caro. Precios por millón de tokens (entrada / salida) al 11 nov 2026; una consulta usa ~700 tokens.
	 * Cualquier otro modelo del proveedor también sirve: se escribe su nombre tal cual en la lista.
	 */
	const RECOMMENDED = array(
		'groq'   => array(
			array( 'openai/gpt-oss-20b', 'El más barato que funciona bien para esto.', '$0.075 / $0.30', '≈ $0.09' ),
			array( 'openai/gpt-oss-120b', 'Más preciso con frases enredadas; el doble de precio.', '$0.15 / $0.60', '≈ $0.18' ),
			array( 'qwen/qwen3.8-27b', 'Sirve de respaldo en el plan gratis; en el de pago es caro.', '$0.80 / $4.00', '≈ $1.10' ),
		),
		'google' => array(
			array( 'gemma-3-27b-it', 'Gratis, pero en el plan gratis Google usa los mensajes para mejorar sus productos.', 'gratis', '$0' ),
			array( 'gemma-3-12b-it', 'Más pequeño, igual de gratis.', 'gratis', '$0' ),
		),
	);

	const MODELS_PAGE = array(
		'groq'   => 'https://console.groq.com/docs/models',
		'google' => 'https://ai.google.dev/gemma/docs',
	);

	// ------------------------------------------------------------------
	// Ajustes
	// ------------------------------------------------------------------

	public static function settings() {
		$s = ASI_Settings::all();
		return array(
			'enabled'   => ! empty( $s['ai_enabled'] ),
			'order'     => array_values( array_intersect( (array) $s['ai_order'], array_keys( self::PROVIDERS ) ) ),
			'models'    => (array) $s['ai_models'],
			'daily_cap' => max( 0, (int) $s['ai_daily_cap'] ),
			'mode'      => isset( $s['ai_mode'] ) && 'review' === $s['ai_mode'] ? 'review' : 'rescue',
		);
	}

	public static function keys() {
		$keys = get_option( self::KEYS_OPTION );
		return is_array( $keys ) ? $keys : array();
	}

	public static function save_key( $provider, $key ) {
		$keys              = self::keys();
		$keys[ $provider ] = $key;
		update_option( self::KEYS_OPTION, array_filter( $keys ), false );
	}

	public static function has_key( $provider ) {
		$keys = self::keys();
		return ! empty( $keys[ $provider ] );
	}

	/** Últimos 4 caracteres de la clave, para reconocerla en el panel sin mostrarla. */
	public static function key_hint( $provider ) {
		$keys = self::keys();
		return empty( $keys[ $provider ] ) ? '' : substr( $keys[ $provider ], -4 );
	}

	/**
	 * Modelos de un proveedor, en orden.
	 *
	 * @return string[]
	 */
	public static function models_for( $provider ) {
		$settings = self::settings();
		$text     = isset( $settings['models'][ $provider ] ) && '' !== trim( (string) $settings['models'][ $provider ] ) ? $settings['models'][ $provider ] : self::PROVIDERS[ $provider ]['default'];
		return array_values( array_filter( array_map( 'trim', preg_split( '/[\s,]+/', (string) $text ) ) ) );
	}

	/**
	 * ¿Está prendida y con al menos una clave?
	 */
	public static function enabled() {
		$settings = self::settings();
		if ( ! $settings['enabled'] ) {
			return false;
		}
		foreach ( $settings['order'] as $provider ) {
			if ( self::has_key( $provider ) ) {
				return true;
			}
		}
		return false;
	}

	// ------------------------------------------------------------------
	// Rescate de un mensaje que las reglas no entendieron
	// ------------------------------------------------------------------

	/**
	 * @param ASI_Dialogue $dialogue Diálogo.
	 * @param string       $message  Mensaje del cliente.
	 * @param array        $state    Estado del navegador.
	 * @param array        $context  Contexto del diálogo (dialogue_context_for).
	 * @return array|null Respuesta nueva, o null si se queda la original.
	 */
	public static function rescue( ASI_Dialogue $dialogue, $message, array $state, array $context, $need_fin = false ) {
		$ids     = isset( $state['results'] ) && is_array( $state['results'] ) ? array_slice( array_values( array_filter( array_map( 'intval', $state['results'] ) ) ), 0, ASI_AI_Interpreter::MAX_VISIBLE ) : array();
		$titles  = self::titles( $context['lang'], $ids );
		$phrases = (array) ASI_Lexicon::get( $context['language'] )->get_list( 'ai_phrases' );
		$key     = ASI_AI_Interpreter::cache_key( $context['lang'], $message, (bool) $titles ) . ( $need_fin ? '|fin' : '' );

		// 1. Caché: ya se interpretó antes.
		$cache = self::cache();
		if ( isset( $cache[ $key ] ) && ASI_AI_Interpreter::unasked_action( $message, $cache[ $key ]['phrase'], $phrases ) ) {
			unset( $cache[ $key ] ); // Una comparación o cotización que no pidió: no se repite.
			update_option( self::CACHE_OPTION, $cache, false );
		}
		if ( isset( $cache[ $key ] ) ) {
			$response = ASI_AI_Interpreter::try_phrase( $dialogue, $message, $cache[ $key ]['phrase'], $state );
			if ( $response ) {
				$cache[ $key ]['hits'] = (int) $cache[ $key ]['hits'] + 1;
				$cache[ $key ]['last'] = time();
				update_option( self::CACHE_OPTION, $cache, false );
				return self::mark( $response, $cache[ $key ]['phrase'], $cache[ $key ]['model'], true );
			}
		}

		// 2. La IA.
		$actions = array( 'finance', 'detail' );
		$runner  = isset( $context['actions'] ) && $context['actions'] instanceof ASI_Action_Runner ? $context['actions'] : null;
		foreach ( array_keys( $runner ? (array) $runner->available() : array() ) as $action ) {
			if ( in_array( $action, array( 'quote', 'compare' ), true ) ) {
				$actions[] = $action;
			}
		}
		$result = ASI_AI_Interpreter::rescue( $dialogue, $message, $state, $titles, $actions, $phrases, array( __CLASS__, 'ask' ), isset( $context['site_name'] ) ? $context['site_name'] : '', $need_fin );
		if ( ! $result || empty( $result['response'] ) ) {
			return null;
		}

		$cache[ $key ] = array(
			'lang'    => $context['lang'],
			'message' => ASI_AI_Interpreter::scrub( $message ),
			'phrase'  => $result['phrase'],
			'model'   => $result['model'],
			'hits'    => 0,
			'time'    => time(),
			'last'    => time(),
		);
		self::save_cache( $cache );
		return self::mark( $result['response'], $result['phrase'], $result['model'], false );
	}

	/**
	 * Modo "lee todo": la IA revisa una búsqueda que las reglas ya contestaron. Si entendió lo mismo,
	 * se queda la respuesta de las reglas; si entendió otra cosa, se busca con su lectura.
	 *
	 * @return array|null Respuesta corregida, o null si se queda la de las reglas.
	 */
	public static function review( ASI_Dialogue $dialogue, $message, array $state, array $context, array &$response ) {
		$current  = isset( $state['q'] ) && is_string( $state['q'] ) ? $state['q'] : '';
		$key      = 'r|' . $context['lang'] . '|' . ASI_Normalizer::normalize( ASI_AI_Interpreter::scrub( $current ) ) . '|' . ASI_Normalizer::normalize( ASI_AI_Interpreter::scrub( $message ) );
		$phrases  = (array) ASI_Lexicon::get( $context['language'] )->get_list( 'ai_phrases' );
		$lexicon  = ASI_Lexicon::get( $context['language'] );
		$analyzer = new ASI_Query_Analyzer( $lexicon, ( new ASI_Index_Repository() )->vocabulary( $context['lang'] ), (array) $context['money'], (array) $context['packs'] );
		$cache    = self::cache();
		$cached   = isset( $cache[ $key ] ) ? (string) $cache[ $key ]['phrase'] : null;
		// Una lectura guardada con la versión anterior que no sirvió cuando el chat no entendió:
		// se vuelve a preguntar una vez (ahora la IA sabe qué tiene el sitio).
		if ( null !== $cached && empty( $cache[ $key ]['v'] ) && empty( $cache[ $key ]['changed'] ) && empty( $cache[ $key ]['edited'] ) && in_array( $response['kind'], array( 'none', 'unclear' ), true ) ) {
			$cached = null;
		}
		$note   = null !== $cached && isset( $cache[ $key ]['note'] ) ? (string) $cache[ $key ]['note'] : '';
		$spec   = null !== $cached && isset( $cache[ $key ]['spec'] ) ? $cache[ $key ]['spec'] : null;
		$result = ASI_AI_Interpreter::review( $dialogue, $analyzer, $message, $state, $response, $phrases, array( __CLASS__, 'ask' ), isset( $context['site_name'] ) ? $context['site_name'] : '', $cached, self::catalog( $context['lang'], $context['language'] ), $note, $spec );
		// La IA entendió lo mismo que las reglas: se queda esa respuesta, con la búsqueda armada.
		if ( ! empty( $result['keep'] ) && isset( $response['state'] ) && is_array( $response['state'] ) ) {
			$response['state'] = array_merge( $response['state'], $result['keep'] );
		}

		if ( null !== $cached ) {
			$cache[ $key ]['hits'] = (int) $cache[ $key ]['hits'] + 1;
			$cache[ $key ]['last'] = time();
			update_option( self::CACHE_OPTION, $cache, false );
		} elseif ( $result['asked'] ) {
			// Se guarda también cuando la IA estuvo de acuerdo: así esa frase no vuelve a gastar.
			$cache[ $key ] = array(
				'lang'    => $context['lang'],
				'message' => ASI_AI_Interpreter::scrub( $message ),
				'context' => ASI_AI_Interpreter::scrub( $current ),
				'phrase'  => $result['phrase'],
				'note'    => $result['note'],
				'spec'    => $result['spec'],
				'changed' => null !== $result['response'],
				'model'   => $result['model'],
				'v'       => 3,
				'hits'    => 0,
				'time'    => time(),
				'last'    => time(),
			);
			self::save_cache( $cache );
		}
		if ( ! $result['response'] ) {
			return null;
		}
		$model    = null !== $cached ? $cache[ $key ]['model'] : $result['model'];
		$response = $result['response'];
		// Lo tradujo por el uso ("familiar" → SUV o sedán): se le dice al cliente.
		if ( '' !== $result['note'] && isset( $phrases['assumed'] ) ) {
			$line             = str_replace( '{word}', $result['note'], $phrases['assumed'] );
			$response['text'] = is_array( $response['text'] ) ? array_merge( array( $line ), $response['text'] ) : $line . ' ' . $response['text'];
		}
		return self::mark( $response, $result['phrase'], $model, null !== $cached );
	}

	/**
	 * Resumen del catálogo para la IA (tipos, combustibles, pasajeros, marcas…). Se guarda unas
	 * horas; al reindexar se vuelve a armar.
	 */
	public static function catalog( $lang, $language ) {
		$key    = 'asi_ai_catalog_' . md5( $lang . '|' . $language );
		$cached = get_transient( $key );
		if ( is_string( $cached ) ) {
			return $cached;
		}
		$words = (array) ASI_Lexicon::get( $language )->get_list( 'ai_catalog_words' );
		$text  = $words ? ASI_AI_Interpreter::catalog_text( ( new ASI_Index_Repository() )->catalog( $lang, 5000 ), $words ) : '';
		set_transient( $key, $text, 6 * HOUR_IN_SECONDS );
		return $text;
	}

	/** Borra los resúmenes del catálogo (al reindexar). */
	public static function forget_catalog() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_asi_ai_catalog_' ) . '%', $wpdb->esc_like( '_transient_timeout_asi_ai_catalog_' ) . '%' ) );
	}

	/**
	 * Modo "lee todo", acciones: la IA revisa qué opciones y qué acción tomaron las reglas.
	 *
	 * @return array|null Respuesta corregida, o null si se queda la de las reglas.
	 */
	public static function review_action( ASI_Dialogue $dialogue, $message, array $state, array $context, array $response ) {
		$ids     = isset( $state['results'] ) && is_array( $state['results'] ) ? array_slice( array_values( array_filter( array_map( 'intval', $state['results'] ) ) ), 0, ASI_AI_Interpreter::MAX_VISIBLE ) : array();
		$titles  = self::titles( $context['lang'], $ids );
		$phrases = (array) ASI_Lexicon::get( $context['language'] )->get_list( 'ai_phrases' );
		$key     = 'a|' . $context['lang'] . '|' . md5( implode( ',', $ids ) . '|' . ( isset( $state['last'] ) ? $state['last'] : '' ) ) . '|' . ASI_Normalizer::normalize( ASI_AI_Interpreter::scrub( $message ) );
		$actions = array( 'finance', 'detail' );
		$runner  = isset( $context['actions'] ) && $context['actions'] instanceof ASI_Action_Runner ? $context['actions'] : null;
		foreach ( array_keys( $runner ? (array) $runner->available() : array() ) as $action ) {
			if ( in_array( $action, array( 'quote', 'compare' ), true ) ) {
				$actions[] = $action;
			}
		}
		$cache  = self::cache();
		$cached = isset( $cache[ $key ] ) ? (string) $cache[ $key ]['phrase'] : null;
		$result = ASI_AI_Interpreter::review_action( $dialogue, $message, $state, $response, $titles, $actions, $phrases, array( __CLASS__, 'ask' ), isset( $context['site_name'] ) ? $context['site_name'] : '', $cached );
		if ( null !== $cached ) {
			$cache[ $key ]['hits'] = (int) $cache[ $key ]['hits'] + 1;
			$cache[ $key ]['last'] = time();
			update_option( self::CACHE_OPTION, $cache, false );
		} elseif ( $result['asked'] ) {
			$cache[ $key ] = array(
				'lang'    => $context['lang'],
				'message' => ASI_AI_Interpreter::scrub( $message ),
				'context' => 'opciones a la vista',
				'phrase'  => $result['phrase'],
				'changed' => null !== $result['response'],
				'model'   => $result['model'],
				'hits'    => 0,
				'time'    => time(),
				'last'    => time(),
			);
			self::save_cache( $cache );
		}
		return $result['response'] ? self::mark( $result['response'], $result['phrase'], null !== $cached ? $cache[ $key ]['model'] : $result['model'], null !== $cached ) : null;
	}

	/** Nota técnica en la respuesta (solo la ve un administrador en la pestaña Probar). */
	private static function mark( array $response, $phrase, $model, $cached ) {
		if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
			$response['ai'] = array( 'phrase' => $phrase, 'model' => $model, 'cached' => $cached );
		}
		return $response;
	}

	private static function titles( $lang, array $ids ) {
		if ( ! $ids ) {
			return array();
		}
		$by_id = array();
		foreach ( ( new ASI_Index_Repository() )->rows( $lang, $ids ) as $row ) {
			$by_id[ (int) $row['post_id'] ] = wp_strip_all_tags( (string) $row['title'] );
		}
		$titles = array();
		foreach ( $ids as $id ) {
			if ( isset( $by_id[ $id ] ) ) {
				$titles[] = $by_id[ $id ];
			}
		}
		// Si falta alguna, los números ya no coinciden con la pantalla: mejor sin opciones.
		return count( $titles ) === count( $ids ) ? $titles : array();
	}

	// ------------------------------------------------------------------
	// Consulta con cadena de respaldo
	// ------------------------------------------------------------------

	/**
	 * Pregunta a la IA: el primer proveedor/modelo disponible que conteste.
	 *
	 * @param string $prompt Instrucciones + mensaje.
	 * @return array|null { text, model }
	 */
	public static function ask( $prompt ) {
		$settings = self::settings();
		$usage    = self::usage();
		if ( $settings['daily_cap'] && $usage['calls'] >= $settings['daily_cap'] ) {
			self::note_error( 'tope', 'Se llegó al tope diario de ' . $settings['daily_cap'] . ' consultas.' );
			return null;
		}
		$start = microtime( true );
		foreach ( $settings['order'] as $provider ) {
			if ( ! self::has_key( $provider ) || get_transient( self::block_key( $provider, '' ) ) ) {
				continue;
			}
			foreach ( self::models_for( $provider ) as $model ) {
				if ( microtime( true ) - $start > self::DEADLINE ) {
					return null;
				}
				if ( get_transient( self::block_key( $provider, $model ) ) ) {
					continue;
				}
				$answer = self::call( $provider, $model, $prompt );
				if ( null !== $answer ) {
					return array( 'text' => $answer, 'model' => $provider . ':' . $model );
				}
				if ( get_transient( self::block_key( $provider, '' ) ) ) {
					break; // Clave mala: no tiene caso probar los otros modelos de este proveedor.
				}
			}
		}
		return null;
	}

	/**
	 * Una consulta. Devuelve el texto o null (y bloquea un rato lo que falló).
	 */
	private static function call( $provider, $model, $prompt ) {
		$keys = self::keys();
		$body = array(
			'model'       => $model,
			'messages'    => array( array( 'role' => 'user', 'content' => $prompt ) ),
			'temperature' => 0,
			// Tope de respuesta: solo se cobra lo que escribe; alto para que una lista larga no se corte.
			'max_tokens'  => 'groq' === $provider && 0 === strpos( $model, 'openai/gpt-oss' ) ? 1500 : 500,
		);
		// Los modelos que "razonan" gastan menos y tardan menos con el razonamiento corto.
		if ( 'groq' === $provider && 0 === strpos( $model, 'openai/gpt-oss' ) ) {
			$body['reasoning_effort'] = 'low';
		}
		$t0       = microtime( true );
		$response = wp_remote_post(
			self::PROVIDERS[ $provider ]['endpoint'],
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $keys[ $provider ],
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		$ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );

		if ( is_wp_error( $response ) ) {
			self::block( $provider, $model, 60 );
			self::note_error( $provider . ':' . $model, 'Sin conexión: ' . $response->get_error_message() );
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code && isset( $data['choices'][0]['message']['content'] ) ) {
			self::count( $provider . ':' . $model, $ms, isset( $data['usage']['total_tokens'] ) ? (int) $data['usage']['total_tokens'] : 0 );
			return (string) $data['choices'][0]['message']['content'];
		}

		$detail = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : 'HTTP ' . $code;
		if ( 429 === $code ) {
			// Límite del nivel gratis: esperar lo que diga el servicio (de 1 minuto a 6 horas).
			$wait = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			self::block( $provider, $model, max( 60, min( 6 * HOUR_IN_SECONDS, $wait ? $wait : 300 ) ) );
			self::note_error( $provider . ':' . $model, 'Límite gratis alcanzado (429). ' . $detail );
		} elseif ( in_array( $code, array( 401, 403 ), true ) ) {
			self::block( $provider, '', HOUR_IN_SECONDS );
			self::note_error( $provider, 'Clave rechazada (' . $code . '). ' . $detail );
		} elseif ( in_array( $code, array( 400, 404 ), true ) ) {
			self::block( $provider, $model, DAY_IN_SECONDS );
			self::note_error( $provider . ':' . $model, 'Modelo no disponible (' . $code . '). ' . $detail );
		} else {
			self::block( $provider, $model, 120 );
			self::note_error( $provider . ':' . $model, 'Error ' . $code . '. ' . $detail );
		}
		return null;
	}

	private static function block_key( $provider, $model ) {
		return 'asi_ai_blk_' . md5( $provider . '|' . $model );
	}

	private static function block( $provider, $model, $seconds ) {
		set_transient( self::block_key( $provider, $model ), time() + $seconds, $seconds );
	}

	/** Quita los bloqueos (al guardar una clave nueva o al probar la conexión). */
	public static function unblock( $provider ) {
		delete_transient( self::block_key( $provider, '' ) );
		foreach ( self::models_for( $provider ) as $model ) {
			delete_transient( self::block_key( $provider, $model ) );
		}
	}

	/**
	 * Modelos bloqueados ahora y hasta cuándo (para el panel).
	 *
	 * @return array model => timestamp
	 */
	public static function blocked() {
		$out = array();
		foreach ( array_keys( self::PROVIDERS ) as $provider ) {
			$until = get_transient( self::block_key( $provider, '' ) );
			if ( $until ) {
				$out[ $provider ] = (int) $until;
			}
			foreach ( self::models_for( $provider ) as $model ) {
				$until = get_transient( self::block_key( $provider, $model ) );
				if ( $until ) {
					$out[ $provider . ':' . $model ] = (int) $until;
				}
			}
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// Uso del día y errores (para el panel)
	// ------------------------------------------------------------------

	public static function usage() {
		$usage = get_option( self::USAGE_OPTION );
		$today = gmdate( 'Y-m-d' );
		if ( ! is_array( $usage ) || ! isset( $usage['day'] ) || $usage['day'] !== $today ) {
			$usage = array(
				'day'    => $today,
				'calls'  => 0,
				'by'     => array(),
				'ms'     => 0,
				'tokens' => 0,
				'errors' => is_array( $usage ) && isset( $usage['errors'] ) ? $usage['errors'] : array(),
			);
		}
		return $usage;
	}

	private static function count( $model, $ms, $tokens ) {
		$usage                 = self::usage();
		$usage['calls']       += 1;
		$usage['by'][ $model ] = ( isset( $usage['by'][ $model ] ) ? $usage['by'][ $model ] : 0 ) + 1;
		$usage['ms']          += $ms;
		$usage['tokens']      += $tokens;
		update_option( self::USAGE_OPTION, $usage, false );
	}

	private static function note_error( $where, $text ) {
		$usage = self::usage();
		array_unshift( $usage['errors'], array( 'time' => time(), 'where' => $where, 'text' => function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 200 ) : substr( $text, 0, 200 ) ) );
		$usage['errors'] = array_slice( $usage['errors'], 0, 10 );
		update_option( self::USAGE_OPTION, $usage, false );
	}

	// ------------------------------------------------------------------
	// Caché de frases interpretadas
	// ------------------------------------------------------------------

	public static function cache() {
		$cache = get_option( self::CACHE_OPTION );
		return is_array( $cache ) ? $cache : array();
	}

	private static function save_cache( array $cache ) {
		if ( count( $cache ) > self::CACHE_MAX ) {
			uasort(
				$cache,
				function ( $a, $b ) {
					return $b['last'] - $a['last'];
				}
			);
			$cache = array_slice( $cache, 0, self::CACHE_MAX, true );
		}
		update_option( self::CACHE_OPTION, $cache, false );
	}

	/**
	 * Corrección a mano de lo aprendido: desde ahora ese mensaje se entiende con esta frase.
	 */
	public static function edit( $key, $phrase ) {
		$cache  = self::cache();
		$phrase = trim( (string) $phrase );
		if ( ! isset( $cache[ $key ] ) || '' === $phrase ) {
			return;
		}
		$cache[ $key ]['phrase']  = $phrase;
		$cache[ $key ]['changed'] = true;
		$cache[ $key ]['edited']  = true;
		$cache[ $key ]['last']    = time();
		update_option( self::CACHE_OPTION, $cache, false );
	}

	public static function forget( $key ) {
		$cache = self::cache();
		unset( $cache[ $key ] );
		update_option( self::CACHE_OPTION, $cache, false );
	}

	// ------------------------------------------------------------------
	// Probar la conexión desde el panel
	// ------------------------------------------------------------------

	/**
	 * Hace una consulta real de prueba y lista los modelos de la cuenta.
	 *
	 * @return array ok, text, models[], ms
	 */
	public static function test( $provider ) {
		if ( ! isset( self::PROVIDERS[ $provider ] ) || ! self::has_key( $provider ) ) {
			return array( 'ok' => false, 'text' => 'Falta la clave.', 'models' => array(), 'ms' => 0 );
		}
		self::unblock( $provider );
		$keys   = self::keys();
		$list   = wp_remote_get(
			self::PROVIDERS[ $provider ]['models'],
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Authorization' => 'Bearer ' . $keys[ $provider ] ),
			)
		);
		$models = array();
		if ( ! is_wp_error( $list ) && 200 === (int) wp_remote_retrieve_response_code( $list ) ) {
			$data = json_decode( (string) wp_remote_retrieve_body( $list ), true );
			foreach ( isset( $data['data'] ) ? (array) $data['data'] : array() as $row ) {
				if ( isset( $row['id'] ) ) {
					$models[] = preg_replace( '#^models/#', '', (string) $row['id'] );
				}
			}
			sort( $models );
		}
		$prompt = ASI_AI_Interpreter::prompt( 'pero dame la cuoa de cada uno', array( 'Opción A', 'Opción B', 'Opción C' ), array( 'finance', 'quote', 'compare', 'detail' ) );
		foreach ( self::models_for( $provider ) as $model ) {
			$t0     = microtime( true );
			$answer = self::call( $provider, $model, $prompt );
			$ms     = (int) round( ( microtime( true ) - $t0 ) * 1000 );
			if ( null !== $answer ) {
				$intent = ASI_AI_Interpreter::parse( $answer, 3, array( 'finance', 'quote', 'compare', 'detail' ) );
				$good   = $intent && 'finance' === $intent['action'] && 'all' === $intent['items'];
				return array(
					'ok'     => true,
					'text'   => $model . ' respondió en ' . $ms . ' ms' . ( $good ? ' y entendió bien la frase de prueba ("la cuoa de cada uno" = cuotas de todas).' : ', pero no entendió bien la frase de prueba: ' . ( function_exists( 'mb_substr' ) ? mb_substr( $answer, 0, 160 ) : substr( $answer, 0, 160 ) ) ),
					'models' => $models,
					'ms'     => $ms,
				);
			}
		}
		$usage = self::usage();
		return array( 'ok' => false, 'text' => $usage['errors'] ? $usage['errors'][0]['text'] : 'No respondió.', 'models' => $models, 'ms' => 0 );
	}
}
