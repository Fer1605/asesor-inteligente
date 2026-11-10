<?php
/**
 * Página del chat: shortcode [asesor_inteligente], una página por idioma y sus recursos.
 *
 * - Las páginas se crean desde el panel, una por idioma, enlazadas como traducciones
 *   (Polylang o WPML). Mientras el modo no sea público quedan privadas: solo los administradores
 *   las ven. Al pasar a público se publican solas.
 * - El chat solo se muestra a quien puede usarlo según el modo de visibilidad.
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || exit;

class ASI_Chat_Page {

	const SHORTCODE = 'asesor_inteligente';

	/** @var ASI_Plugin */
	private $plugin;

	public function __construct( ASI_Plugin $plugin ) {
		$this->plugin = $plugin;
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		add_action( 'asi_mode_changed', array( $this, 'sync_status' ) );
		add_action( 'asi_discovered', array( $this, 'create_pages_once' ) );
	}

	/**
	 * La primera vez que el plugin descubre el sitio crea las páginas del chat (privadas hasta el
	 * modo público). Solo esa vez: si luego alguien borra una, se respeta y se ofrece recrearla en el panel.
	 */
	public function create_pages_once() {
		if ( get_option( 'asi_pages_created' ) ) {
			return;
		}
		$this->create_pages();
		update_option( 'asi_pages_created', 1, false );
	}

	/**
	 * Contenido del shortcode.
	 *
	 * @return string
	 */
	public function render() {
		if ( ! ASI_Settings::current_user_can_use() ) {
			return current_user_can( 'manage_options' )
				? '<p><em>' . esc_html__( 'El Asesor inteligente está apagado. Actívalo en Asesor inteligente → Inicio.', 'asesor-inteligente' ) . '</em></p>'
				: '';
		}

		$lang    = $this->page_language();
		$context = ASI_Context::resolve( $lang, $this->plugin->languages );
		$lexicon = ASI_Lexicon::get( $context->language );
		$settings = ASI_Settings::all();
		$ui       = ( new ASI_Tone( $lexicon, $settings['tone'] ) )->ui( $lexicon->get_list( 'ui' ) );

		$initial  = ( new ASI_Dialogue( new ASI_Index_Repository(), ASI_REST_Controller::dialogue_context_for( $context, $this->plugin->languages ) ) )->handle( '' );
		if ( ! empty( $settings['welcome'][ $context->lang ] ) ) {
			$initial['text'] = $settings['welcome'][ $context->lang ];
		}
		unset( $initial['state'] );

		$this->enqueue_assets();
		wp_localize_script(
			'asi-chat',
			'asiChatConfig',
			array(
				'endpoint'   => rest_url( ASI_REST_Controller::NAMESPACE_V1 . '/chat' ),
				'clickEndpoint' => rest_url( ASI_REST_Controller::NAMESPACE_V1 . '/click' ),
				'nonce'      => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
				'lang'       => $context->lang,
				'storageKey' => 'asi_chat_v2_' . $context->key(),
				'initial'    => $initial,
				'query'      => isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'assistant'  => array(
					'name'   => (string) $settings['assistant_name'],
					'avatar' => ASI_Appearance::avatar_url(),
				),
				'ui'         => $ui,
			)
		);

		return '<div class="asi-chat" id="asi-chat" style="' . esc_attr( ASI_Appearance::css_variables() ) . '" data-lang="' . esc_attr( $context->lang ) . '"><noscript>' . esc_html( isset( $ui['noscript'] ) ? $ui['noscript'] : '' ) . '</noscript></div>';
	}

	/**
	 * Registra y encola CSS y JS del chat.
	 */
	public function enqueue_assets() {
		wp_enqueue_style( 'asi-chat', ASI_URL . 'assets/chat/chat.css', array(), ASI_VERSION );
		wp_enqueue_script( 'asi-chat', ASI_URL . 'assets/chat/chat.js', array(), ASI_VERSION, true );
	}

	/**
	 * Idioma de la página que se está viendo.
	 *
	 * @return string
	 */
	private function page_language() {
		$post_id = get_the_ID();
		$lang    = $post_id ? $this->plugin->languages->adapter()->get_post_language( $post_id ) : '';
		return $lang ? $lang : $this->plugin->languages->adapter()->get_current_language();
	}

	/**
	 * Crea las páginas del chat que falten, una por idioma, enlazadas como traducciones.
	 *
	 * @return int Páginas creadas.
	 */
	public function create_pages() {
		$settings = ASI_Settings::all();
		$pages    = (array) $settings['chat_pages'];
		$adapter  = $this->plugin->languages->adapter();
		$status   = 'public' === ASI_Settings::mode() ? 'publish' : 'private';
		$created  = 0;

		foreach ( $this->plugin->languages->all() as $slug => $language ) {
			if ( ! empty( $pages[ $slug ] ) && get_post( $pages[ $slug ] ) && 'trash' !== get_post_status( $pages[ $slug ] ) ) {
				continue;
			}
			$ui      = ASI_Lexicon::get( $language['language'] )->get_list( 'ui' );
			$page_id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => $status,
					'post_title'   => isset( $ui['page_title'] ) ? $ui['page_title'] : 'Asistente',
					'post_name'    => isset( $ui['page_slug'] ) ? $ui['page_slug'] : 'asistente',
					'post_content' => '<!-- wp:shortcode -->[' . self::SHORTCODE . ']<!-- /wp:shortcode -->',
				),
				true
			);
			if ( is_wp_error( $page_id ) ) {
				continue;
			}
			$this->set_language( $adapter, $page_id, $slug );
			$pages[ $slug ] = $page_id;
			$created++;
		}

		if ( 'polylang' === $adapter->get_id() && function_exists( 'pll_save_post_translations' ) && count( $pages ) > 1 ) {
			pll_save_post_translations( array_map( 'intval', $pages ) );
		}

		ASI_Settings::update( array( 'chat_pages' => $pages ) );
		return $created;
	}

	/**
	 * Publica las páginas del chat en modo público y las deja privadas en los demás modos.
	 *
	 * @param string $mode Modo nuevo.
	 */
	public function sync_status( $mode ) {
		$status = 'public' === $mode ? 'publish' : 'private';
		foreach ( (array) ASI_Settings::all()['chat_pages'] as $page_id ) {
			if ( get_post( $page_id ) && ! in_array( get_post_status( $page_id ), array( $status, 'trash' ), true ) ) {
				wp_update_post( array( 'ID' => (int) $page_id, 'post_status' => $status ) );
			}
		}
	}

	/**
	 * Asigna el idioma a una página nueva.
	 */
	private function set_language( ASI_Language_Adapter $adapter, $page_id, $slug ) {
		if ( 'polylang' === $adapter->get_id() && function_exists( 'pll_set_post_language' ) ) {
			pll_set_post_language( $page_id, $slug );
		} elseif ( 'wpml' === $adapter->get_id() ) {
			do_action(
				'wpml_set_element_language_details',
				array(
					'element_id'    => $page_id,
					'element_type'  => 'post_page',
					'trid'          => false,
					'language_code' => $slug,
				)
			);
		}
	}

	/**
	 * URL de la página del chat en un idioma; si no existe, $fallback.
	 *
	 * @param string $lang     Slug del idioma.
	 * @param string $fallback URL alternativa.
	 * @return string
	 */
	public static function url_for_language( $lang, $fallback = '' ) {
		$settings = ASI_Settings::all();
		$page_id  = isset( $settings['chat_pages'][ $lang ] ) ? (int) $settings['chat_pages'][ $lang ] : 0;
		if ( $page_id && in_array( get_post_status( $page_id ), array( 'publish', 'private' ), true ) ) {
			return (string) get_permalink( $page_id );
		}
		return (string) $fallback;
	}

	/**
	 * ¿El post es una página del chat? (no se indexa: no responde nada por sí misma).
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public static function is_chat_page( WP_Post $post ) {
		return 'page' === $post->post_type && has_shortcode( $post->post_content, self::SHORTCODE );
	}
}
