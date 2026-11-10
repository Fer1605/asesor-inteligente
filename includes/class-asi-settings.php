<?php
/**
 * Ajustes del plugin.
 *
 * Modo de visibilidad (protege el sitio en producción):
 *  - off:    el chat no se muestra a nadie y la búsqueda del sitio no se toca.
 *  - admin:  solo los administradores lo ven y lo usan (modo de prueba en producción).
 *  - public: todos los visitantes.
 *
 * Conexión con el buscador del sitio (search_mode):
 *  - none:     no se toca.
 *  - redirect: toda búsqueda va a la página del chat del mismo idioma.
 *  - choice:   al buscar, el visitante elige entre la búsqueda normal y el asistente.
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || exit;

class ASI_Settings {

	const OPTION = 'asi_settings';

	const MODES        = array( 'off', 'admin', 'public' );
	const SEARCH_MODES = array( 'none', 'redirect', 'choice' );

	/** Colores configurables del chat. Vacío = se toma del Kit de Elementor o del valor por defecto. */
	const COLOR_KEYS = array( 'primary', 'accent', 'text', 'background', 'surface', 'button', 'button_hover', 'reset_bg', 'reset_text', 'reset_hover' );

	const RADII = array(
		'square'  => 2,
		'soft'    => 8,
		'rounded' => 16,
		'pill'    => 24,
	);

	/**
	 * Valores por defecto.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'mode'                => 'admin',
			'search_mode'         => 'none',
			'chat_pages'          => array(),
			'colors'              => array_fill_keys( self::COLOR_KEYS, '' ),
			'radius'              => 'rounded',
			'assistant_name'      => '',
			'assistant_avatar'    => 0,
			'assistant_site_name' => '',
			'welcome'             => array(),
			'tone'                => 'tu',   // Tratamiento en español: 'tu' o 'usted'.
			'emojis'              => false,
			'synonyms_text'       => array(),
			'synonyms'            => array(),
		);
	}

	/**
	 * Al activar: modo solo administradores.
	 */
	public static function install_defaults() {
		if ( false === get_option( self::OPTION ) ) {
			add_option( self::OPTION, self::defaults(), '', false );
		}
	}

	/**
	 * Todos los ajustes, con los valores por defecto para lo que falte.
	 *
	 * @return array
	 */
	public static function all() {
		$settings = get_option( self::OPTION );
		$settings = wp_parse_args( is_array( $settings ) ? $settings : array(), self::defaults() );
		$settings['colors'] = wp_parse_args( (array) $settings['colors'], array_fill_keys( self::COLOR_KEYS, '' ) );
		return $settings;
	}

	/**
	 * Guarda un grupo de valores (se mezclan con los actuales).
	 *
	 * @param array $values Valores.
	 */
	public static function update( array $values ) {
		update_option( self::OPTION, array_merge( self::all(), $values ), false );
	}

	public static function mode() {
		$mode = self::all()['mode'];
		return in_array( $mode, self::MODES, true ) ? $mode : 'admin';
	}

	public static function set_mode( $mode ) {
		if ( in_array( $mode, self::MODES, true ) ) {
			self::update( array( 'mode' => $mode ) );
			do_action( 'asi_mode_changed', $mode );
		}
	}

	public static function search_mode() {
		$mode = self::all()['search_mode'];
		return in_array( $mode, self::SEARCH_MODES, true ) ? $mode : 'none';
	}

	/**
	 * ¿El usuario actual puede ver y usar el buscador?
	 *
	 * @return bool
	 */
	public static function current_user_can_use() {
		$mode = self::mode();
		if ( 'public' === $mode ) {
			return true;
		}
		return 'admin' === $mode && current_user_can( 'manage_options' );
	}

	/**
	 * Convierte el texto del panel ("playera, camiseta, remera" por línea) en grupos de sinónimos.
	 *
	 * @param string $text Texto.
	 * @return array[]
	 */
	public static function parse_synonyms( $text ) {
		$groups = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$words = array_values( array_filter( array_map( 'trim', explode( ',', $line ) ) ) );
			if ( count( $words ) >= 2 ) {
				$groups[] = array_slice( $words, 0, 20 );
			}
		}
		return array_slice( $groups, 0, 300 );
	}
}
