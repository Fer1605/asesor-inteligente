<?php
/**
 * Endpoint del chat: POST /wp-json/asi/v1/chat
 *
 * Entrada: message (texto), lang (slug del idioma de la página), state (memoria de la conversación).
 * Salida: la respuesta de ASI_Dialogue (texto, tarjetas, enlaces, sugerencias y el nuevo estado).
 *
 * Protecciones para producción:
 *  - Respeta el modo de visibilidad (apagado / solo administradores / público).
 *  - Límite de mensajes por minuto por visitante, para no cargar el servidor.
 *  - Por mensaje solo escribe el contador temporal del límite; las preguntas sin buena respuesta se
 *    registran aparte (sin datos personales) para enseñarle al chat desde el panel (Aprendizaje).
 *
 * POST /wp-json/asi/v1/click: qué resultado abrió el visitante después de una búsqueda (aprendizaje).
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || exit;

class ASI_REST_Controller {

	const NAMESPACE_V1   = 'asi/v1';
	const RATE_LIMIT     = 20;
	const RATE_WINDOW    = MINUTE_IN_SECONDS;
	const MISSES_OPTION  = 'asi_misses';
	const MISSES_MAX     = 200;

	/** @var ASI_Plugin */
	private $plugin;

	public function __construct( ASI_Plugin $plugin ) {
		$this->plugin = $plugin;
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/chat',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'chat' ),
				'permission_callback' => array( 'ASI_Settings', 'current_user_can_use' ),
				'args'                => array(
					'message' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'lang'    => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
					'state'   => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/admin/find',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'admin_find' ),
				'permission_callback' => function () {
					return current_user_can( ASI_Access::CAP );
				},
				'args'                => array(
					'q'    => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'lang' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/click',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'click' ),
				'permission_callback' => array( 'ASI_Settings', 'current_user_can_use' ),
				'args'                => array(
					'q'    => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'id'   => array(
						'type'     => 'integer',
						'required' => true,
					),
					'lang' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Panel (Aprendizaje): busca contenido indexado por su nombre para enseñar una respuesta.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response
	 */
	public function admin_find( WP_REST_Request $request ) {
		$rows = ( new ASI_Index_Repository() )->find_titles( (string) $request->get_param( 'lang' ), (string) $request->get_param( 'q' ), 10 );
		return rest_ensure_response( $rows );
	}

	/**
	 * Registra un clic en un resultado. Un visitante cuenta una vez por búsqueda y resultado al día.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response
	 */
	public function click( WP_REST_Request $request ) {
		// El límite va primero: leer la búsqueda (vocabulario del índice) es lo pesado.
		if ( ! ASI_Learning::click_allowed() ) {
			$rest = rest_ensure_response( array( 'ok' => true ) );
			$rest->header( 'Cache-Control', 'no-store' );
			return $rest;
		}
		$context    = ASI_Context::resolve( $request->get_param( 'lang' ), $this->plugin->languages );
		$settings   = ASI_Settings::all();
		$lexicon    = ASI_Lexicon::get( $context->language, isset( $settings['synonyms'][ $context->language ] ) ? (array) $settings['synonyms'][ $context->language ] : array() );
		$repository = new ASI_Index_Repository();
		$key        = ASI_Dialogue::content_key( new ASI_Query_Analyzer( $lexicon, $repository->vocabulary( $context->lang ) ), $lexicon, (string) $request->get_param( 'q' ) );
		$post_id = (int) $request->get_param( 'id' );
		$ip      = ASI_Settings::client_ip();
		$once    = 'asi_ckd_' . md5( $ip . '|' . $context->lang . '|' . $key . '|' . $post_id );

		// Solo resultados que existen en el índice de ese idioma.
		if ( '' !== $key && $post_id && ! get_transient( $once ) && $repository->rows( $context->lang, array( $post_id ) ) ) {
			set_transient( $once, 1, DAY_IN_SECONDS );
			ASI_Learning::record_click( $context->lang, $key, $post_id );
		}
		$rest = rest_ensure_response( array( 'ok' => true ) );
		$rest->header( 'Cache-Control', 'no-store' );
		return $rest;
	}

	/**
	 * Responde un mensaje.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response|WP_Error
	 */
	public function chat( WP_REST_Request $request ) {
		// Solo JSON: un formulario de otro sitio (que siempre manda otro tipo) no puede escribir en el
		// chat, ni pedir correos, desde el navegador de sus visitantes. Las llamadas internas no traen tipo.
		$type = $request->get_content_type();
		if ( ! empty( $type['value'] ) && 'application/json' !== $type['value'] ) {
			return new WP_Error( 'asi_bad_type', 'Formato no válido.', array( 'status' => 415 ) );
		}
		if ( $this->rate_limited() ) {
			return new WP_Error( 'asi_rate_limited', __( 'Demasiados mensajes seguidos. Espera un momento, por favor.', 'asesor-inteligente' ), array( 'status' => 429 ) );
		}

		$context = ASI_Context::resolve( $request->get_param( 'lang' ), $this->plugin->languages );
		$state   = $request->get_param( 'state' );
		$message = (string) $request->get_param( 'message' );
		// El mismo tope que lee el diálogo: la IA, la caché y el registro nunca reciben más.
		$message = function_exists( 'mb_substr' ) ? mb_substr( $message, 0, ASI_Dialogue::MAX_MESSAGE ) : substr( $message, 0, ASI_Dialogue::MAX_MESSAGE );

		$state    = is_array( $state ) ? $state : array();
		$ai_used  = false;
		// Cada conversación tiene su identificador (para el registro privado y el resumen de una cita).
		$cid          = isset( $state['cid'] ) && ASI_Chat_Log::valid_id( $state['cid'] ) ? $state['cid'] : ASI_Chat_Log::new_id();
		$state['cid'] = $cid;
		$dcontext = self::dialogue_context_for( $context, $this->plugin->languages );
		$dialogue = new ASI_Dialogue( new ASI_Index_Repository(), $dcontext );
		// El estado viene del navegador: se limpia una vez aquí, para todo lo que lo usa (IA, reportería, registro).
		$state        = $dialogue->sanitize_state( $state );
		$state['cid'] = $cid;
		$response = $dialogue->handle( $message, $state );

		// IA (opcional): revisa cada búsqueda y corrige solo si entendió otra cosa; además rescata lo que
		// las reglas no entendieron o un enganche que no leyeron.
		if ( ASI_AI::enabled() ) {
			$fin_unread = ! empty( $response['fin_unread'] );
			$lexicon    = ASI_Lexicon::get( $dcontext['language'] );
			$reads_all  = ! $fin_unread;
			$review     = $reads_all && ASI_AI_Interpreter::reviewable( $response, $state, $message, (array) $lexicon->get_list( 'ai_signals' ) );
			$fixed      = $review ? ASI_AI::review( $dialogue, $message, $state, $dcontext, $response ) : null;
			// Acciones con vehículos (cuotas, cotizar, comparar): la IA revisa las opciones cuando hay riesgo.
			if ( ! $fixed && $reads_all && ASI_AI_Interpreter::action_worth_asking( $message, $response, is_array( $state ) ? $state : array(), (array) $lexicon->get_list( 'quote_words' ), (array) $lexicon->get_list( 'ordinals' ) ) ) {
				$fixed = ASI_AI::review_action( $dialogue, $message, $state, $dcontext, $response );
			}
			if ( ! $fixed && ( $fin_unread || ASI_AI_Interpreter::needs_help( $response ) ) ) {
				$fixed = ASI_AI::rescue( $dialogue, $message, $state, $dcontext, $fin_unread );
			}
			if ( $fixed ) {
				// Ya entendida: no va a "preguntas sin respuesta"; queda en la lista de la IA del panel.
				$response = $fixed;
				unset( $response['learn'] );
				$ai_used = true;
			}
		}

		// Cambió de tipo de vehículo sin decir monto: se conservó el presupuesto y se pregunta si sigue.
		$response = $dialogue->budget_check( $response, $state, $message );
		// Pidió un color: primero los de ese color y una aclaración de que las fotos son de referencia.
		$response = $dialogue->color_check( $response, $state, $message );

		if ( ! empty( $response['learn'] ) ) {
			ASI_Learning::log_question( $message, $context->lang, isset( $response['qkey'] ) ? $response['qkey'] : '', $response['learn'] );
		}
		unset( $response['learn'], $response['qkey'], $response['fin_unread'], $response['guessed'], $response['over_budget'], $response['quality'], $response['dropped'] );

		$response['state']        = isset( $response['state'] ) && is_array( $response['state'] ) ? $response['state'] : array();
		$response['state']['cid'] = $cid;

		// Reportería (sin datos personales): conversación, lo que pidió de nuevo, detalle visto.
		ASI_Reports::chat_turn( $dialogue, $state, $response );

		// Registro privado de la conversación (lo que buscó, en palabras del panel: "Tipo: Pickup").
		$old_q    = isset( $state['q'] ) && is_string( $state['q'] ) ? $state['q'] : '';
		$new_q    = isset( $response['state']['q'] ) && is_string( $response['state']['q'] ) ? $response['state']['q'] : '';
		$criteria = array();
		if ( '' !== $new_q && $new_q !== $old_q ) {
			$names = ASI_Reports::dim_names();
			foreach ( $dialogue->report_criteria( $new_q ) as $c ) {
				$criteria[] = ( isset( $names[ $c['dim'] ] ) ? $names[ $c['dim'] ] : $c['dim'] ) . ': ' . $c['label'];
			}
		}
		ASI_Chat_Log::record( $message, $state, $ai_used ? array_merge( $response, array( 'ai_model' => 1 ) ) : $response, $context->lang, $criteria );

		$response = apply_filters( 'asi_chat_response', $response, $message, $context );
		$rest     = rest_ensure_response( $response );
		$rest->header( 'Cache-Control', 'no-store' );
		return $rest;
	}

	/**
	 * Contexto que necesita el diálogo (lo usan el endpoint y la página del chat).
	 *
	 * @param ASI_Context           $context  Contexto resuelto.
	 * @param ASI_Language_Registry $registry Idiomas del sitio.
	 * @return array
	 */
	public static function dialogue_context_for( ASI_Context $context, ASI_Language_Registry $registry ) {
		$settings = ASI_Settings::all();
		$siblings = array();

		// Otros idiomas del mismo país (o del sitio, si no es multipaís), para ofrecerlos si escribe en ese idioma.
		foreach ( $registry->all() as $slug => $language ) {
			if ( $slug !== $context->lang && $language['country'] === $context->country && $language['language'] !== $context->language ) {
				$siblings[ $language['language'] ] = ASI_Chat_Page::url_for_language( $slug, $language['home_url'] );
			}
		}

		return array(
			'lang'          => $context->lang,
			'language'      => $context->language,
			'site_name'     => ! empty( $settings['assistant_site_name'] ) ? $settings['assistant_site_name'] : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'country_name'  => $context->country_name,
			'multi_country' => $registry->is_multi_country(),
			'hour'          => (int) current_time( 'G' ),
			'now'           => (int) current_time( 'timestamp' ), // Hora del sitio (fechas de las citas).
			'appt_hours'    => ASI_Settings::appointment_hours(), // Cuándo se atiende (null = sin horario).
			'home_url'      => $context->home_url,
			'siblings'      => $siblings,
			'languages'     => ASI_Lexicon::available(),
			'synonyms'      => isset( $settings['synonyms'][ $context->language ] ) ? (array) $settings['synonyms'][ $context->language ] : array(),
			'location_types' => self::location_types(),
			'sparse'         => self::sparse_taxonomies(),
			'learned'        => ASI_Learning::learned_for( $context->lang ),
			'tone'           => 'usted' === $settings['tone'] ? 'usted' : 'tu',
			'emojis'         => ! empty( $settings['emojis'] ),
			'money'          => ASI_Settings::money( $context->country ),
			'packs'          => array_values( ASI_Extensions::active_packs() ),
			'actions'        => new ASI_Actions(),
			'appointments'   => new ASI_Appointments(),
			'card_flags'     => ASI_Settings::card_flags( $context->lang ),
			'whatsapp'       => ASI_WhatsApp::get()['value'],
			'contact'        => array(
				'on_closing' => ! empty( $settings['contact']['on_closing'] ),
				'items'      => ASI_Settings::contact_items( ASI_WhatsApp::get()['value'] ),
				'page'       => esc_url_raw( (string) $settings['contact']['page'] ),
			),
			'site_links'     => ASI_Site_Links::all( $context->language ),
		);
	}

	/**
	 * Tipos de contenido que son ubicaciones (sucursales, tiendas), según el esquema descubierto.
	 *
	 * @return string[]
	 */
	public static function location_types() {
		$schema = ASI_Schema_Discovery::get_schema();
		$types  = array();
		foreach ( isset( $schema['post_types'] ) ? $schema['post_types'] : array() as $post_type => $type ) {
			if ( ! empty( $type['enabled'] ) && isset( $type['kind'] ) && 'location' === $type['kind'] ) {
				$types[] = $post_type;
			}
		}
		return $types;
	}

	/**
	 * Taxonomías del catálogo que pocos productos indican y con varios valores (el color en 40 de
	 * 400 vehículos): pedir "blanco" deja fuera a los que no dicen su color, y el chat lo avisa.
	 * Se guarda junto al vocabulario (se borra con él al reindexar).
	 *
	 * @return array { terms: forma normal => taxonomía, tax: taxonomía => { label, ids } }
	 */
	public static function sparse_taxonomies() {
		$key    = ASI_Index_Repository::VOCAB_PREFIX . 'sparse2';
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$out    = array( 'terms' => array(), 'tax' => array() );
		$schema = ASI_Schema_Discovery::get_schema();
		foreach ( isset( $schema['post_types'] ) ? $schema['post_types'] : array() as $post_type => $type ) {
			if ( empty( $type['enabled'] ) || 'catalog' !== ( isset( $type['role'] ) ? $type['role'] : '' ) || empty( $type['taxonomies'] ) ) {
				continue;
			}
			$total = (int) wp_count_posts( $post_type )->publish;
			if ( $total < 20 ) {
				continue;
			}
			foreach ( $type['taxonomies'] as $taxonomy => $info ) {
				if ( 'brand' === $info['role'] ) {
					continue;
				}
				$ids = get_posts( array( 'post_type' => $post_type, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'tax_query' => array( array( 'taxonomy' => $taxonomy, 'operator' => 'EXISTS' ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
				$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => true, 'fields' => 'names' ) );
				if ( ! $ids || is_wp_error( $terms ) || count( $terms ) < 5 || count( $ids ) >= $total * 0.25 ) {
					continue;
				}
				$label = ASI_Normalizer::singular( strtolower( remove_accents( (string) $info['label'] ) ) );
				$out['tax'][ $taxonomy ] = array( 'label' => $label, 'ids' => array_map( 'intval', $ids ), 'words' => array() );
				foreach ( $terms as $name ) {
					$out['terms'][ ASI_Lexicon::normal_form( $name ) ] = $taxonomy;
					// Cada palabra del término, con sus productos: "azul" junta "Azul Atlántico" y "Azul D".
					$with = get_posts( array( 'post_type' => $post_type, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'tax_query' => array( array( 'taxonomy' => $taxonomy, 'field' => 'name', 'terms' => $name ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
					foreach ( array_unique( explode( ' ', ASI_Lexicon::normal_form( $name ) ) ) as $word ) {
						$have = isset( $out['tax'][ $taxonomy ]['words'][ $word ] ) ? $out['tax'][ $taxonomy ]['words'][ $word ] : array();
						$out['tax'][ $taxonomy ]['words'][ $word ] = array_values( array_unique( array_merge( $have, array_map( 'intval', $with ) ) ) );
					}
				}
			}
		}
		set_transient( $key, $out, ASI_Index_Repository::VOCAB_TTL );
		return $out;
	}

	/**
	 * Límite de mensajes por minuto por visitante (IP + usuario).
	 *
	 * @return bool
	 */
	private function rate_limited() {
		$ip    = ASI_Settings::client_ip();
		$key   = 'asi_rl_' . md5( $ip . '|' . get_current_user_id() );
		$count = (int) get_transient( $key );
		$limit = (int) apply_filters( 'asi_rate_limit', self::RATE_LIMIT );

		if ( $count >= $limit ) {
			return true;
		}
		set_transient( $key, $count + 1, self::RATE_WINDOW );
		return false;
	}
}
