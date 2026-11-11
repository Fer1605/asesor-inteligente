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
					return current_user_can( 'manage_options' );
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
		$context    = ASI_Context::resolve( $request->get_param( 'lang' ), $this->plugin->languages );
		$settings   = ASI_Settings::all();
		$lexicon    = ASI_Lexicon::get( $context->language, isset( $settings['synonyms'][ $context->language ] ) ? (array) $settings['synonyms'][ $context->language ] : array() );
		$repository = new ASI_Index_Repository();
		$key        = ASI_Dialogue::content_key( new ASI_Query_Analyzer( $lexicon, $repository->vocabulary( $context->lang ) ), $lexicon, (string) $request->get_param( 'q' ) );
		$post_id = (int) $request->get_param( 'id' );
		$ip      = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$once    = 'asi_ckd_' . md5( $ip . '|' . $context->lang . '|' . $key . '|' . $post_id );

		// Solo resultados que existen en el índice de ese idioma.
		if ( '' !== $key && $post_id && ! get_transient( $once ) && ASI_Learning::click_allowed() && $repository->rows( $context->lang, array( $post_id ) ) ) {
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
		if ( $this->rate_limited() ) {
			return new WP_Error( 'asi_rate_limited', __( 'Demasiados mensajes seguidos. Espera un momento, por favor.', 'asesor-inteligente' ), array( 'status' => 429 ) );
		}

		$context = ASI_Context::resolve( $request->get_param( 'lang' ), $this->plugin->languages );
		$state   = $request->get_param( 'state' );
		$message = (string) $request->get_param( 'message' );

		$state    = is_array( $state ) ? $state : array();
		$dcontext = self::dialogue_context_for( $context, $this->plugin->languages );
		$dialogue = new ASI_Dialogue( new ASI_Index_Repository(), $dcontext );
		$response = $dialogue->handle( $message, $state );

		// Si las reglas no entendieron y la IA está prendida, ella interpreta y las reglas responden.
		// También cuando pidió cuotas con un enganche o plazo que las reglas no supieron leer.
		$fin_unread = ! empty( $response['fin_unread'] );
		if ( ( $fin_unread || ASI_AI_Interpreter::needs_help( $response ) ) && ASI_AI::enabled() ) {
			$rescued = ASI_AI::rescue( $dialogue, $message, $state, $dcontext, $fin_unread );
			if ( $rescued ) {
				// Ya entendida: no va a "preguntas sin respuesta"; queda en la lista de la IA del panel.
				$response = $rescued;
				unset( $response['learn'] );
			}
		}

		if ( ! empty( $response['learn'] ) ) {
			ASI_Learning::log_question( $message, $context->lang, isset( $response['qkey'] ) ? $response['qkey'] : '', $response['learn'] );
		}
		unset( $response['learn'], $response['qkey'], $response['fin_unread'] );

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
			'home_url'      => $context->home_url,
			'siblings'      => $siblings,
			'languages'     => ASI_Lexicon::available(),
			'synonyms'      => isset( $settings['synonyms'][ $context->language ] ) ? (array) $settings['synonyms'][ $context->language ] : array(),
			'location_types' => self::location_types(),
			'learned'        => ASI_Learning::learned_for( $context->lang ),
			'tone'           => 'usted' === $settings['tone'] ? 'usted' : 'tu',
			'emojis'         => ! empty( $settings['emojis'] ),
			'money'          => ASI_Settings::money( $context->country ),
			'packs'          => array_values( ASI_Extensions::active_packs() ),
			'actions'        => new ASI_Actions(),
			'card_flags'     => ASI_Settings::card_flags( $context->lang ),
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
	 * Límite de mensajes por minuto por visitante (IP + usuario).
	 *
	 * @return bool
	 */
	private function rate_limited() {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
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
