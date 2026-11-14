<?php
/**
 * Conexión con la IA: el sitio no aloja ningún modelo; manda la frase y recibe una orden corta.
 * Se puede conectar Groq y Google AI Studio (tienen plan gratis), Claude, ChatGPT u otra IA que hable
 * como la de OpenAI (DeepSeek, Grok de xAI, Mistral, OpenRouter…).
 *
 * - La IA lee cada búsqueda y corrige si el chat entendió otra cosa (ver ASI_AI_Interpreter).
 * - Cadena de respaldo: la IA elegida va primero; cada modelo tiene su propio límite. Si uno se agota
 *   se salta un rato y se prueba el siguiente (y las otras IA con clave). Si se acaban todos, el chat
 *   sigue como sin IA y queda un aviso en el panel.
 * - Tope diario en dólares: cálculo con los tokens que reporta la IA y el precio de lista del modelo.
 * - Caché: una frase ya interpretada no vuelve a consultar a la IA.
 * - Las claves se guardan en su propia opción (no se cargan en cada página) y nunca se muestran.
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || exit;

class ASI_AI {

	const KEYS_OPTION    = 'asi_ai_keys';
	const USAGE_OPTION   = 'asi_ai_usage';
	const CACHE_OPTION   = 'asi_ai_cache';
	const AUTO_OPTION    = 'asi_ai_auto';    // Modelos elegidos solos por proveedor (de la lista de la cuenta).
	const NOTICES_OPTION = 'asi_ai_notices'; // Avisos para el panel (modelo agotado, chat sin IA…).
	const DOWN_OPTION    = 'asi_ai_down';    // Marca de "el chat está sin IA" (para avisar cuando vuelva).
	const CACHE_MAX      = 1000;
	const NOTICES_MAX    = 30;
	const TIMEOUT        = 6;   // Segundos por consulta.
	const DEADLINE       = 9;   // Segundos en total por mensaje (toda la cadena).

	/**
	 * IA que se pueden conectar. format: cómo se le habla (openai = la mayoría; anthropic = Claude).
	 * auto: patrones (en orden de preferencia) para elegir solos los modelos de la lista de la cuenta.
	 */
	const PROVIDERS = array(
		'groq'      => array(
			'label'    => 'Groq',
			'tag'      => 'Gratis · recomendada',
			'paid'     => false,
			'format'   => 'openai',
			'endpoint' => 'https://api.groq.com/openai/v1/chat/completions',
			'models'   => 'https://api.groq.com/openai/v1/models',
			'signup'   => 'https://console.groq.com/login',
			'console'  => 'https://console.groq.com/keys',
			'where'    => 'En el menú, entra a “API Keys” y presiona “Create API Key”. Ponle un nombre (por ejemplo, el del sitio).',
			'default'  => 'openai/gpt-oss-120b, openai/gpt-oss-20b, qwen/qwen3.8-27b',
			'auto'     => array(),
		),
		'google'    => array(
			'label'    => 'Google AI Studio',
			'tag'      => 'Gratis · Gemini y Gemma',
			'paid'     => false,
			'format'   => 'openai',
			'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
			'models'   => 'https://generativelanguage.googleapis.com/v1beta/openai/models',
			'signup'   => 'https://aistudio.google.com/',
			'console'  => 'https://aistudio.google.com/apikey',
			'where'    => 'Entra con tu cuenta de Google, presiona “Get API key” y luego “Create API key”.',
			'default'  => 'gemma-3-27b-it, gemma-3-12b-it',
			'auto'     => array(),
		),
		'anthropic' => array(
			'label'    => 'Claude',
			'tag'      => 'De pago',
			'paid'     => true,
			'format'   => 'anthropic',
			'endpoint' => 'https://api.anthropic.com/v1/messages',
			'models'   => 'https://api.anthropic.com/v1/models',
			'signup'   => 'https://console.anthropic.com/',
			'console'  => 'https://console.anthropic.com/settings/keys',
			'where'    => 'En “Settings”, entra a “API Keys” y presiona “Create Key”. Antes carga saldo en “Billing”.',
			'default'  => 'claude-haiku-5-5',
			'auto'     => array( '/^claude-haiku-\d+(-\d+)?$/' ),
		),
		'openai'    => array(
			'label'    => 'ChatGPT',
			'tag'      => 'De pago · OpenAI',
			'paid'     => true,
			'format'   => 'openai',
			'endpoint' => 'https://api.openai.com/v1/chat/completions',
			'models'   => 'https://api.openai.com/v1/models',
			'signup'   => 'https://platform.openai.com/signup',
			'console'  => 'https://platform.openai.com/api-keys',
			'where'    => 'Entra a “API keys” y presiona “Create new secret key”. Antes carga saldo en “Billing”.',
			'default'  => 'gpt-5-mini, gpt-5-nano',
			'auto'     => array( '/^gpt-\d+(\.\d+)?-mini$/', '/^gpt-\d+(\.\d+)?-nano$/' ),
		),
		'custom'    => array(
			'label'    => 'Otra IA',
			'tag'      => 'DeepSeek, Grok, Mistral…',
			'paid'     => true,
			'format'   => 'openai',
			'endpoint' => '', // Lo escribe el usuario (ai_custom.url).
			'models'   => '',
			'signup'   => '',
			'console'  => '',
			'where'    => 'Dentro de su panel, busca en la configuración la sección de claves (casi siempre se llama “API keys”) y crea una.',
			'default'  => '',
			'auto'     => array(),
		),
	);

	/**
	 * Modelos recomendados para esta tarea (solo interpretar, respuestas cortas), del más barato al más
	 * caro. Precios por millón de tokens (entrada / salida) al 13 nov 2026; una consulta usa ~1,300 tokens.
	 * Cualquier otro modelo del proveedor también sirve: se escribe su nombre tal cual en la lista.
	 */
	const RECOMMENDED = array(
		'groq'      => array(
			array( 'openai/gpt-oss-20b', 'El más barato que funciona bien para esto.', '$0.075 / $0.30', '≈ $0.09' ),
			array( 'openai/gpt-oss-120b', 'Más preciso con frases enredadas; el doble de precio.', '$0.15 / $0.60', '≈ $0.18' ),
			array( 'qwen/qwen3.8-27b', 'Sirve de respaldo en el plan gratis; en el de pago es caro.', '$0.80 / $4.00', '≈ $1.10' ),
		),
		'google'    => array(
			array( 'gemma-3-27b-it', 'Gratis, pero en el plan gratis Google usa los mensajes para mejorar sus productos.', 'gratis', '$0' ),
			array( 'gemma-3-12b-it', 'Más pequeño, igual de gratis.', 'gratis', '$0' ),
		),
		'anthropic' => array(
			array( 'claude-haiku-5-5', 'El más barato de Claude y de sobra para esto.', '$0.10 / $0.50', '≈ $0.25' ),
			array( 'claude-sonnet-5-5', 'Mucho más caro y no hace falta para interpretar.', '$2.00 / $10.00', '≈ $5' ),
		),
		'openai'    => array(
			array( 'gpt-5-nano', 'El más barato de OpenAI.', '$0.05 / $0.40', '≈ $0.20' ),
			array( 'gpt-5-mini', 'Más preciso; unas cinco veces el precio.', '$0.25 / $2.00', '≈ $1' ),
		),
	);

	const MODELS_PAGE = array(
		'groq'      => 'https://console.groq.com/docs/models',
		'google'    => 'https://ai.google.dev/gemini-api/docs/models',
		'anthropic' => 'https://docs.claude.com/en/docs/about-claude/models/overview',
		'openai'    => 'https://platform.openai.com/docs/models',
	);

	/**
	 * Precio de lista por millón de tokens (entrada, salida), para el tope diario en dólares.
	 * El primer patrón que coincida con el nombre del modelo; si ninguno, el "*" del proveedor (alto a
	 * propósito: un modelo desconocido no debe pasarse del tope por haberlo calculado barato).
	 */
	const PRICES = array(
		'groq'      => array( '#gpt-oss-20b#' => array( 0.075, 0.30 ), '#gpt-oss-120b#' => array( 0.15, 0.60 ), '*' => array( 0.80, 4.00 ) ),
		'google'    => array( '#^gemma#' => array( 0, 0 ), '#flash-lite#' => array( 0.10, 0.40 ), '#flash#' => array( 0.30, 2.50 ), '*' => array( 1.25, 10.00 ) ),
		'anthropic' => array( '#haiku-5#' => array( 0.10, 0.50 ), '#haiku#' => array( 1.00, 5.00 ), '#sonnet-5#' => array( 2.00, 10.00 ), '#sonnet#' => array( 3.00, 15.00 ), '#opus-5-5#' => array( 4.00, 20.00 ), '*' => array( 8.00, 40.00 ) ),
		'openai'    => array( '#nano#' => array( 0.05, 0.40 ), '#mini#' => array( 0.25, 2.00 ), '*' => array( 2.50, 15.00 ) ),
		'custom'    => array( '*' => array( 2.00, 8.00 ) ), // Si el usuario no puso el precio de su modelo.
	);

	// ------------------------------------------------------------------
	// Ajustes
	// ------------------------------------------------------------------

	public static function settings() {
		$s      = ASI_Settings::all();
		$order  = array_values( array_intersect( (array) $s['ai_order'], array_keys( self::PROVIDERS ) ) );
		$custom = isset( $s['ai_custom'] ) && is_array( $s['ai_custom'] ) ? $s['ai_custom'] : array();
		return array(
			'enabled'   => ! empty( $s['ai_enabled'] ),
			'provider'  => $order ? $order[0] : 'groq', // La elegida en el panel.
			// La elegida primero; las demás con clave quedan de respaldo.
			'order'     => array_values( array_unique( array_merge( $order, array_keys( self::PROVIDERS ) ) ) ),
			'models'    => (array) $s['ai_models'],
			'daily_usd' => isset( $s['ai_daily_usd'] ) ? max( 0, (float) $s['ai_daily_usd'] ) : 1.0,
			'custom'    => array(
				'name'      => isset( $custom['name'] ) ? (string) $custom['name'] : '',
				'url'       => isset( $custom['url'] ) ? (string) $custom['url'] : '',
				'price_in'  => isset( $custom['price_in'] ) && '' !== $custom['price_in'] ? (float) $custom['price_in'] : null,
				'price_out' => isset( $custom['price_out'] ) && '' !== $custom['price_out'] ? (float) $custom['price_out'] : null,
			),
			'mode'      => 'review', // Siempre lee cada búsqueda (ya no se elige).
		);
	}

	/** Nombre para mostrar de un proveedor ("Otra IA" toma el nombre que le puso el usuario). */
	public static function label( $provider ) {
		if ( 'custom' === $provider ) {
			$name = self::settings()['custom']['name'];
			return '' !== $name ? $name : self::PROVIDERS['custom']['label'];
		}
		return isset( self::PROVIDERS[ $provider ] ) ? self::PROVIDERS[ $provider ]['label'] : (string) $provider;
	}

	/** Dirección para conversar (y para listar modelos) de un proveedor. */
	private static function endpoint( $provider, $what = 'endpoint' ) {
		if ( 'custom' !== $provider ) {
			return self::PROVIDERS[ $provider ][ $what ];
		}
		$base = untrailingslashit( preg_replace( '#/(chat/completions|models)/?$#', '', self::settings()['custom']['url'] ) );
		if ( '' === $base ) {
			return '';
		}
		return $base . ( 'models' === $what ? '/models' : '/chat/completions' );
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

	/** ¿Tiene todo lo que necesita para usarse (clave, y para "Otra IA" la dirección y un modelo)? */
	public static function ready( $provider ) {
		return isset( self::PROVIDERS[ $provider ] ) && self::has_key( $provider ) && '' !== self::endpoint( $provider ) && self::models_for( $provider );
	}

	/** Últimos 4 caracteres de la clave, para reconocerla en el panel sin mostrarla. */
	public static function key_hint( $provider ) {
		$keys = self::keys();
		return empty( $keys[ $provider ] ) ? '' : substr( $keys[ $provider ], -4 );
	}

	/**
	 * Modelos de un proveedor, en orden: los que escribió el usuario; si no escribió, los que el plugin
	 * eligió de la lista de la cuenta; si no hay lista, los de fábrica.
	 *
	 * @return string[]
	 */
	public static function models_for( $provider ) {
		$settings = self::settings();
		$text     = isset( $settings['models'][ $provider ] ) ? trim( (string) $settings['models'][ $provider ] ) : '';
		if ( '' === $text ) {
			$auto = get_option( self::AUTO_OPTION );
			$text = ! empty( $auto[ $provider ]['models'] ) ? implode( ', ', (array) $auto[ $provider ]['models'] ) : ( isset( self::PROVIDERS[ $provider ] ) ? self::PROVIDERS[ $provider ]['default'] : '' );
		}
		return array_values( array_filter( array_map( 'trim', preg_split( '/[\s,]+/', (string) $text ) ) ) );
	}

	/** ¿Los modelos de este proveedor los eligió el plugin (el usuario no escribió ninguno)? */
	public static function models_auto( $provider ) {
		$settings = self::settings();
		return ! isset( $settings['models'][ $provider ] ) || '' === trim( (string) $settings['models'][ $provider ] );
	}

	/**
	 * ¿Está prendida y con al menos una IA lista?
	 */
	public static function enabled() {
		$settings = self::settings();
		if ( ! $settings['enabled'] ) {
			return false;
		}
		foreach ( $settings['order'] as $provider ) {
			if ( self::ready( $provider ) ) {
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
				$cache[ $key ]['reply'] = self::reply_text( $response );
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
			'reply'   => self::reply_text( $result['response'] ),
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
			$cache[ $key ]['reply'] = self::reply_text( $result['response'] ? $result['response'] : $response );
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
				'reply'   => self::reply_text( $result['response'] ? $result['response'] : $response ),
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
			$cache[ $key ]['reply'] = self::reply_text( $result['response'] ? $result['response'] : $response );
			update_option( self::CACHE_OPTION, $cache, false );
		} elseif ( $result['asked'] ) {
			$cache[ $key ] = array(
				'lang'    => $context['lang'],
				'message' => ASI_AI_Interpreter::scrub( $message ),
				'context' => 'opciones a la vista',
				'phrase'  => $result['phrase'],
				'changed' => null !== $result['response'],
				'reply'   => self::reply_text( $result['response'] ? $result['response'] : $response ),
				'model'   => $result['model'],
				'hits'    => 0,
				'time'    => time(),
				'last'    => time(),
			);
			self::save_cache( $cache );
		}
		return $result['response'] ? self::mark( $result['response'], $result['phrase'], null !== $cached ? $cache[ $key ]['model'] : $result['model'], null !== $cached ) : null;
	}

	/** Qué contestó el chat (para el panel): el texto y cuántas opciones mostró. */
	public static function reply_text( $response ) {
		if ( ! is_array( $response ) ) {
			return '';
		}
		$text = isset( $response['text'] ) ? ( is_array( $response['text'] ) ? implode( ' ', $response['text'] ) : (string) $response['text'] ) : '';
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > 220 ) {
			$text = rtrim( mb_substr( $text, 0, 217 ) ) . '…';
		}
		$cards = isset( $response['cards'] ) ? count( (array) $response['cards'] ) : 0;
		if ( $cards ) {
			$text .= ' [' . $cards . ( 1 === $cards ? ' opción' : ' opciones' ) . ']';
		}
		return trim( $text );
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
		if ( $settings['daily_usd'] > 0 && $usage['cost'] >= $settings['daily_usd'] ) {
			self::is_down() || self::notify( 'cap|' . $usage['day'], 'stop', sprintf( 'Se llegó al tope de hoy (US$%s). El chat funciona sin IA hasta mañana.', number_format( $settings['daily_usd'], 2 ) ) );
			self::went_down();
			return null;
		}
		$start = microtime( true );
		foreach ( $settings['order'] as $provider ) {
			if ( ! self::ready( $provider ) || get_transient( self::block_key( $provider, '' ) ) ) {
				continue;
			}
			foreach ( self::models_for( $provider ) as $model ) {
				if ( microtime( true ) - $start > self::DEADLINE ) {
					return null; // Se acabó el tiempo de este mensaje; no quiere decir que no haya IA.
				}
				if ( get_transient( self::block_key( $provider, $model ) ) ) {
					continue;
				}
				$answer = self::call( $provider, $model, $prompt );
				if ( null !== $answer ) {
					self::came_back( $provider, $model );
					return array( 'text' => $answer, 'model' => $provider . ':' . $model );
				}
				if ( get_transient( self::block_key( $provider, '' ) ) ) {
					break; // Clave mala o sin saldo: no tiene caso probar los otros modelos de este proveedor.
				}
			}
		}
		// Ninguno disponible: todos agotados, en pausa o fallando.
		$until = self::next_unblock();
		self::is_down() || self::notify( 'noai|' . time(), 'stop', 'El chat ahora funciona sin IA: los modelos conectados están agotados o fallando.' . ( $until ? ' Se vuelven a probar solos a las ' . wp_date( 'H:i', $until ) . '.' : '' ) );
		self::went_down();
		return null;
	}

	/**
	 * Una consulta. Devuelve el texto o null (y bloquea un rato lo que falló).
	 */
	private static function call( $provider, $model, $prompt ) {
		$keys      = self::keys();
		$anthropic = 'anthropic' === self::PROVIDERS[ $provider ]['format'];
		$reasoning = ( 'groq' === $provider && 0 === strpos( $model, 'openai/gpt-oss' ) ) || ( 'openai' === $provider && preg_match( '/^(gpt-[5-9]|o\d)/', $model ) );
		if ( $anthropic ) {
			$body = array(
				'model'      => $model,
				'max_tokens' => false !== strpos( $model, 'haiku' ) ? 600 : 1500,
				'messages'   => array( array( 'role' => 'user', 'content' => $prompt ) ),
			);
			// Sin razonamiento largo: Haiku lo deja apagar; los demás, en el esfuerzo más bajo.
			if ( false !== strpos( $model, 'haiku' ) ) {
				$body['thinking'] = array( 'type' => 'disabled' );
			} else {
				$body['output_config'] = array( 'effort' => 'low' );
			}
			$headers = array(
				'x-api-key'         => $keys[ $provider ],
				'anthropic-version' => '2023-06-01',
				'Content-Type'      => 'application/json',
			);
		} else {
			$body = array(
				'model'    => $model,
				'messages' => array( array( 'role' => 'user', 'content' => $prompt ) ),
			);
			// Tope de respuesta: solo se cobra lo que escribe; alto en los que razonan para que no se corte.
			if ( 'openai' === $provider ) {
				$body['max_completion_tokens'] = $reasoning ? 1500 : 500; // OpenAI ya no acepta max_tokens ni temperatura en sus modelos nuevos.
			} else {
				$body['max_tokens']  = $reasoning ? 1500 : 500;
				$body['temperature'] = 0;
			}
			if ( $reasoning ) {
				$body['reasoning_effort'] = 'low'; // Gasta menos y tarda menos.
			}
			$headers = array(
				'Authorization' => 'Bearer ' . $keys[ $provider ],
				'Content-Type'  => 'application/json',
			);
		}
		$t0       = microtime( true );
		$response = wp_remote_post(
			self::endpoint( $provider ),
			array(
				'timeout' => self::TIMEOUT,
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			)
		);
		$ms    = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		$label = self::label( $provider );

		if ( is_wp_error( $response ) ) {
			self::block( $provider, $model, 60 );
			self::note_error( $provider . ':' . $model, 'Sin conexión: ' . $response->get_error_message() );
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$text = null;
		if ( 200 === $code && $anthropic && isset( $data['content'] ) && is_array( $data['content'] ) ) {
			$text = '';
			foreach ( $data['content'] as $block ) {
				$text .= isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ? (string) $block['text'] : '';
			}
		} elseif ( 200 === $code && isset( $data['choices'][0]['message']['content'] ) ) {
			$text = (string) $data['choices'][0]['message']['content'];
		}
		if ( null !== $text ) {
			$in  = (int) ( isset( $data['usage']['input_tokens'] ) ? $data['usage']['input_tokens'] : ( isset( $data['usage']['prompt_tokens'] ) ? $data['usage']['prompt_tokens'] : 0 ) );
			$out = (int) ( isset( $data['usage']['output_tokens'] ) ? $data['usage']['output_tokens'] : ( isset( $data['usage']['completion_tokens'] ) ? $data['usage']['completion_tokens'] : 0 ) );
			self::count( $provider . ':' . $model, $ms, $in + $out, self::cost( $provider, $model, $in, $out ) );
			return $text;
		}

		$detail = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : 'HTTP ' . $code;
		$broke  = preg_match( '/credit balance|insufficient[_ ]quota|billing|payment required|saldo/i', $detail ) || 402 === $code;
		if ( $broke ) {
			// Sin saldo en la cuenta: se pausa la IA completa de ese proveedor.
			self::block( $provider, '', HOUR_IN_SECONDS );
			self::note_error( $provider, 'Sin saldo (' . $code . '). ' . $detail );
			self::notify( 'broke|' . $provider, 'warn', 'La cuenta de ' . $label . ' se quedó sin saldo. Carga saldo en su panel; mientras tanto se usa la siguiente IA conectada, si hay.', 6 * HOUR_IN_SECONDS );
		} elseif ( 429 === $code ) {
			// Límite del plan (por minuto o del día): esperar lo que diga el servicio (de 1 minuto a 6 horas).
			$wait  = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			$pause = max( 60, min( 6 * HOUR_IN_SECONDS, $wait ? $wait : 300 ) );
			self::block( $provider, $model, $pause );
			self::note_error( $provider . ':' . $model, 'Límite alcanzado (429). ' . $detail );
			// Solo se avisa cuando es el límite del día (los de por minuto se pasan solos).
			if ( $pause >= 10 * MINUTE_IN_SECONDS || preg_match( '/per day|daily|\bTPD\b|\bRPD\b|quota/i', $detail ) ) {
				self::notify( 'quota|' . $provider . ':' . $model, 'warn', 'Se agotó por hoy el modelo ' . $model . ' de ' . $label . '. El chat sigue con el siguiente de la lista.', 3 * HOUR_IN_SECONDS );
			}
		} elseif ( in_array( $code, array( 401, 403 ), true ) ) {
			self::block( $provider, '', HOUR_IN_SECONDS );
			self::note_error( $provider, 'Clave rechazada (' . $code . '). ' . $detail );
			self::notify( 'key|' . $provider, 'warn', $label . ' rechazó la clave. Revisa que esté bien copiada o crea una nueva y pégala en la pestaña IA.', 6 * HOUR_IN_SECONDS );
		} elseif ( in_array( $code, array( 400, 404 ), true ) ) {
			self::block( $provider, $model, DAY_IN_SECONDS );
			self::note_error( $provider . ':' . $model, 'Modelo no disponible (' . $code . '). ' . $detail );
			self::notify( 'model|' . $provider . ':' . $model, 'warn', $label . ' no aceptó el modelo ' . $model . ' (no existe en la cuenta o cambió de nombre). Se salta por hoy; revisa la lista de modelos.', DAY_IN_SECONDS );
		} else {
			self::block( $provider, $model, 120 );
			self::note_error( $provider . ':' . $model, 'Error ' . $code . '. ' . $detail );
		}
		return null;
	}

	/**
	 * Precio estimado de una consulta, en dólares, con el precio de lista del modelo.
	 */
	public static function cost( $provider, $model, $in, $out ) {
		$price = self::price( $provider, $model );
		return ( $in * $price[0] + $out * $price[1] ) / 1000000;
	}

	/** Precio por millón de tokens (entrada, salida) de un modelo. */
	public static function price( $provider, $model ) {
		if ( 'custom' === $provider ) {
			$custom = self::settings()['custom'];
			if ( null !== $custom['price_in'] || null !== $custom['price_out'] ) {
				return array( (float) $custom['price_in'], (float) $custom['price_out'] );
			}
		}
		$table = isset( self::PRICES[ $provider ] ) ? self::PRICES[ $provider ] : self::PRICES['custom'];
		foreach ( $table as $pattern => $price ) {
			if ( '*' !== $pattern && preg_match( $pattern, $model ) ) {
				return $price;
			}
		}
		return $table['*'];
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

	/** Cuándo se vuelve a probar el primero de los modelos en pausa (0 si no hay ninguno en pausa). */
	private static function next_unblock() {
		$times = array();
		foreach ( self::settings()['order'] as $provider ) {
			if ( ! self::ready( $provider ) ) {
				continue;
			}
			$all = (int) get_transient( self::block_key( $provider, '' ) );
			foreach ( self::models_for( $provider ) as $model ) {
				$times[] = max( $all, (int) get_transient( self::block_key( $provider, $model ) ) );
			}
		}
		$times = array_filter( $times );
		return $times ? min( $times ) : 0;
	}

	// ------------------------------------------------------------------
	// Avisos del panel
	// ------------------------------------------------------------------

	/**
	 * Deja un aviso (sin repetirlo: el mismo aviso no vuelve a salir antes de $quiet segundos).
	 *
	 * @param string $key   Qué pasó (para no repetirlo).
	 * @param string $type  warn | stop | ok.
	 * @param string $text  Lo que se muestra.
	 * @param int    $quiet Segundos sin repetir.
	 */
	public static function notify( $key, $type, $text, $quiet = DAY_IN_SECONDS ) {
		$notices = self::notices();
		foreach ( $notices as $notice ) {
			if ( $notice['key'] === $key && time() - $notice['time'] < $quiet ) {
				return;
			}
		}
		array_unshift( $notices, array( 'time' => time(), 'key' => $key, 'type' => $type, 'text' => $text ) );
		update_option( self::NOTICES_OPTION, array_slice( $notices, 0, self::NOTICES_MAX ), false );
	}

	/** @return array[] time, key, type, text (lo más nuevo primero). */
	public static function notices() {
		$notices = get_option( self::NOTICES_OPTION );
		return is_array( $notices ) ? $notices : array();
	}

	/** Avisos que este usuario todavía no ha visto en la pestaña IA. */
	public static function unseen( $user_id ) {
		$seen = (int) get_user_meta( $user_id, 'asi_ai_notices_seen', true );
		$n    = 0;
		foreach ( self::notices() as $notice ) {
			$n += $notice['time'] > $seen && 'ok' !== $notice['type'] ? 1 : 0;
		}
		return $n;
	}

	public static function mark_seen( $user_id ) {
		update_user_meta( $user_id, 'asi_ai_notices_seen', time() );
	}

	public static function clear_notices() {
		delete_option( self::NOTICES_OPTION );
	}

	/** ¿El chat está ahora sin IA (todo agotado o el tope del día)? */
	public static function is_down() {
		return (bool) get_option( self::DOWN_OPTION );
	}

	private static function went_down() {
		if ( ! get_option( self::DOWN_OPTION ) ) {
			update_option( self::DOWN_OPTION, time(), false );
		}
	}

	private static function came_back( $provider, $model ) {
		if ( get_option( self::DOWN_OPTION ) ) {
			delete_option( self::DOWN_OPTION );
			self::notify( 'back|' . time(), 'ok', 'La IA volvió a funcionar con ' . $model . ' de ' . self::label( $provider ) . '.', 0 );
		}
	}

	// ------------------------------------------------------------------
	// Uso del día y errores (para el panel)
	// ------------------------------------------------------------------

	public static function usage() {
		$usage = get_option( self::USAGE_OPTION );
		$today = wp_date( 'Y-m-d' ); // Día del sitio (zona horaria de Ajustes → Generales), con la hora del servidor.
		if ( ! is_array( $usage ) || ! isset( $usage['day'] ) || $usage['day'] !== $today ) {
			$usage = array(
				'day'    => $today,
				'calls'  => 0,
				'by'     => array(),
				'ms'     => 0,
				'tokens' => 0,
				'cost'   => 0.0,
				'errors' => is_array( $usage ) && isset( $usage['errors'] ) ? $usage['errors'] : array(),
			);
			if ( get_option( self::DOWN_OPTION ) && self::settings()['daily_usd'] > 0 ) {
				delete_option( self::DOWN_OPTION ); // Día nuevo: el tope vuelve a cero.
			}
		}
		$usage['cost'] = isset( $usage['cost'] ) ? (float) $usage['cost'] : 0.0;
		return $usage;
	}

	private static function count( $model, $ms, $tokens, $cost = 0.0 ) {
		$usage                 = self::usage();
		$usage['calls']       += 1;
		$usage['by'][ $model ] = ( isset( $usage['by'][ $model ] ) ? $usage['by'][ $model ] : 0 ) + 1;
		$usage['ms']          += $ms;
		$usage['tokens']      += $tokens;
		$usage['cost']        += $cost;
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
		$cache[ $key ]['reply']   = ''; // Con el significado nuevo cambia la respuesta: se verá la próxima vez.
		$cache[ $key ]['reply']   = ''; // La respuesta cambia con lo nuevo: se verá la próxima vez.
		$cache[ $key ]['last']    = time();
		update_option( self::CACHE_OPTION, $cache, false );
	}

	public static function forget( $key ) {
		$cache = self::cache();
		unset( $cache[ $key ] );
		update_option( self::CACHE_OPTION, $cache, false );
	}


	// ------------------------------------------------------------------
	// Modelos de la cuenta y prueba de conexión desde el panel
	// ------------------------------------------------------------------

	/**
	 * Lista los modelos que tiene la cuenta (no gasta consultas).
	 *
	 * @return string[]|null null si no se pudo leer.
	 */
	public static function account_models( $provider ) {
		$url = self::endpoint( $provider, 'models' );
		if ( '' === $url || ! self::has_key( $provider ) ) {
			return null;
		}
		$keys    = self::keys();
		$headers = 'anthropic' === self::PROVIDERS[ $provider ]['format']
			? array( 'x-api-key' => $keys[ $provider ], 'anthropic-version' => '2023-06-01' )
			: array( 'Authorization' => 'Bearer ' . $keys[ $provider ] );
		$list    = wp_remote_get( 'anthropic' === $provider ? $url . '?limit=100' : $url, array( 'timeout' => self::TIMEOUT, 'headers' => $headers ) );
		if ( is_wp_error( $list ) || 200 !== (int) wp_remote_retrieve_response_code( $list ) ) {
			return null;
		}
		$data   = json_decode( (string) wp_remote_retrieve_body( $list ), true );
		$models = array();
		foreach ( isset( $data['data'] ) ? (array) $data['data'] : array() as $row ) {
			if ( isset( $row['id'] ) ) {
				$models[] = preg_replace( '#^models/#', '', (string) $row['id'] );
			}
		}
		sort( $models );
		return $models;
	}

	/**
	 * Elige solos los modelos de la cuenta (cuando el usuario no escribió ninguno): por cada patrón de
	 * preferencia, el más nuevo que tenga la cuenta. Así no se queda con un nombre que el proveedor retiró.
	 *
	 * @param string[] $models Modelos de la cuenta.
	 * @return string[]
	 */
	public static function pick_models( $provider, array $models ) {
		$picked = array();
		foreach ( isset( self::PROVIDERS[ $provider ]['auto'] ) ? self::PROVIDERS[ $provider ]['auto'] : array() as $pattern ) {
			$matches = preg_grep( $pattern, $models );
			if ( $matches ) {
				usort( $matches, function ( $a, $b ) { return strnatcmp( $b, $a ); } );
				$picked[] = $matches[0];
			}
		}
		return array_values( array_unique( $picked ) );
	}

	/** Lee la cuenta y guarda los modelos elegidos solos (al guardar una clave y al probar). */
	public static function refresh_auto( $provider, $models = null ) {
		if ( empty( self::PROVIDERS[ $provider ]['auto'] ) ) {
			return;
		}
		$models = null === $models ? self::account_models( $provider ) : $models;
		$picked = $models ? self::pick_models( $provider, $models ) : array();
		$auto   = get_option( self::AUTO_OPTION );
		$auto   = is_array( $auto ) ? $auto : array();
		if ( $picked ) {
			$auto[ $provider ] = array( 'models' => $picked, 'time' => time() );
			update_option( self::AUTO_OPTION, $auto, false );
		}
	}

	/**
	 * Hace una consulta real de prueba y lista los modelos de la cuenta.
	 *
	 * @return array ok, text, models[], ms
	 */
	public static function test( $provider ) {
		if ( ! isset( self::PROVIDERS[ $provider ] ) || ! self::has_key( $provider ) ) {
			return array( 'ok' => false, 'text' => 'Falta la clave.', 'models' => array(), 'ms' => 0 );
		}
		if ( '' === self::endpoint( $provider ) ) {
			return array( 'ok' => false, 'text' => 'Falta la dirección de la API.', 'models' => array(), 'ms' => 0 );
		}
		self::unblock( $provider );
		$models = self::account_models( $provider );
		self::refresh_auto( $provider, $models );
		$models = (array) $models;
		if ( ! self::models_for( $provider ) ) {
			return array( 'ok' => false, 'text' => 'Escribe al menos un modelo.', 'models' => $models, 'ms' => 0 );
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
					'text'   => 'Conectada. ' . $model . ' respondió en ' . $ms . ' ms' . ( $good ? ' y entendió bien la frase de prueba (“la cuoa de cada uno” = cuotas de todas).' : ', pero no entendió bien la frase de prueba: ' . ( function_exists( 'mb_substr' ) ? mb_substr( $answer, 0, 160 ) : substr( $answer, 0, 160 ) ) ),
					'models' => $models,
					'ms'     => $ms,
				);
			}
		}
		$usage = self::usage();
		return array( 'ok' => false, 'text' => $usage['errors'] ? $usage['errors'][0]['text'] : 'No respondió.', 'models' => $models, 'ms' => 0 );
	}
}
