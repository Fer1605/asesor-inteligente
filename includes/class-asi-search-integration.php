<?php
/**
 * Conexión con el buscador del sitio.
 *
 * Funciona con cualquier formulario de búsqueda de WordPress (el del tema, el widget de búsqueda
 * de Elementor, el de WooCommerce), porque todos envían el parámetro "s":
 *  - redirect: la búsqueda se envía a la página del chat del mismo idioma, con la consulta.
 *  - choice:   al enviar el formulario aparece una pequeña elección: asistente o resultados normales.
 *
 * Solo actúa para quien puede usar el buscador según el modo de visibilidad.
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || exit;

class ASI_Search_Integration {

	/** @var ASI_Plugin */
	private $plugin;

	public function __construct( ASI_Plugin $plugin ) {
		$this->plugin = $plugin;
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_choice' ) );
	}

	/**
	 * Modo automático: la página de resultados de búsqueda pasa a ser el chat.
	 */
	public function maybe_redirect() {
		if ( 'redirect' !== ASI_Settings::search_mode() || ! is_search() || is_admin() || ! ASI_Settings::current_user_can_use() ) {
			return;
		}
		// "?asi_skip=1" deja ver los resultados normales (lo usa el modo "elegir").
		if ( isset( $_GET['asi_skip'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$url = $this->chat_url();
		if ( '' === $url ) {
			return;
		}
		wp_safe_redirect( add_query_arg( 'q', rawurlencode( get_search_query( false ) ), $url ) );
		exit;
	}

	/**
	 * Modo "que el cliente elija": script que intercepta los formularios de búsqueda.
	 */
	public function maybe_enqueue_choice() {
		if ( 'choice' !== ASI_Settings::search_mode() || ! ASI_Settings::current_user_can_use() ) {
			return;
		}
		$url = $this->chat_url();
		if ( '' === $url ) {
			return;
		}
		$context = ASI_Context::resolve( '', $this->plugin->languages );
		$ui      = ASI_Lexicon::get( $context->language )->get_list( 'ui' );

		wp_enqueue_style( 'asi-chat', ASI_URL . 'assets/chat/chat.css', array(), ASI_VERSION );
		wp_enqueue_script( 'asi-search-choice', ASI_URL . 'assets/chat/search-choice.js', array(), ASI_VERSION, true );
		wp_localize_script(
			'asi-search-choice',
			'asiSearchChoice',
			array(
				'chatUrl' => $url,
				'vars'    => ASI_Appearance::css_variables(),
				'ui'      => array(
					'title'     => $ui['choice_title'],
					'assistant' => $ui['choice_assistant'],
					'search'    => $ui['choice_search'],
				),
			)
		);
	}

	/**
	 * Página del chat del idioma actual.
	 *
	 * @return string
	 */
	private function chat_url() {
		$context = ASI_Context::resolve( '', $this->plugin->languages );
		return ASI_Chat_Page::url_for_language( $context->lang );
	}
}
