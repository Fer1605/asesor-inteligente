<?php
/**
 * Ubicación marcada en la página del mapa.
 *
 * Las ubicaciones (sucursales, tiendas) casi nunca tienen una página propia con diseño: viven en una
 * página con el mapa y la lista de todas. El chat enlaza a esa página con ?asi_place={id} y aquí se carga
 * un script pequeño que busca esa ubicación en la lista, la resalta, la lleva a la vista y la activa
 * (el mapa se centra y abre su globo). No depende del plugin de mapas: busca por el ID y por el nombre.
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || exit;

class ASI_Place_Focus {

	const PARAM = 'asi_place';

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Carga el script solo si la URL trae una ubicación válida.
	 */
	public function enqueue() {
		if ( empty( $_GET[ self::PARAM ] ) || 'off' === ASI_Settings::mode() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$post = get_post( absint( $_GET[ self::PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $post || 'publish' !== $post->post_status || ! in_array( $post->post_type, ASI_REST_Controller::location_types(), true ) ) {
			return;
		}
		$palette = ASI_Appearance::palette();

		wp_enqueue_script( 'asi-place-focus', ASI_URL . 'assets/chat/place-focus.js', array(), ASI_VERSION, true );
		wp_localize_script(
			'asi-place-focus',
			'asiPlaceFocus',
			array(
				'id'    => (int) $post->ID,
				'title' => wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES ),
				'color' => $palette['primary']['value'],
			)
		);
	}
}
