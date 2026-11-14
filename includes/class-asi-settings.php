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
			'currency_base'       => '',      // ISO; vacío = WooCommerce o el país del sitio.
			'currency_map'        => array(), // valor guardado en el sitio => ISO ('1' => 'USD').
			'currency_rates'      => array(), // ISO => unidades de la base por 1 unidad.
			'card_flags'          => array(), // Sellos de las tarjetas: etiqueta => array( show, as ). Vacío = automático.
			'actions_mode'        => 'test',  // Cotizar/comparar desde el chat: off, test (aviso a ventas solo al correo de prueba), live.
			'test_email'          => '',
			'finance_rate'        => '',      // Tasa anual en % si el sitio no trae calculadora propia.
			'finance_down_min'    => 20,      // Enganche mínimo en %.
			// IA que lee cada búsqueda (opcional). Las claves van en ASI_AI::KEYS_OPTION.
			'ai_enabled'          => false,
			'ai_order'            => array( 'groq', 'google' ), // La primera es la elegida; las demás con clave, de respaldo.
			'ai_models'           => array(),  // proveedor => "modelo1, modelo2" (vacío = automático).
			'ai_daily_usd'        => 1.0,      // Tope de gasto estimado por día, en dólares (0 = sin tope).
			'ai_custom'           => array(),  // "Otra IA": name, url, price_in, price_out.
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

	/**
	 * Configuración de moneda para ASI_Money: la base configurada; si no, la de WooCommerce; si no,
	 * la del país del contexto o del idioma del sitio (es_GT → GTQ).
	 *
	 * @param string $country País del contexto (ISO-2), si se conoce.
	 * @return array base, map, rates
	 */
	public static function money( $country = '' ) {
		$settings = self::all();
		$base     = ASI_Money::resolve_base( $settings['currency_base'] );
		if ( '' === $base && function_exists( 'get_woocommerce_currency' ) ) {
			$base = ASI_Money::resolve_base( get_woocommerce_currency() );
		}
		if ( '' === $base ) {
			$base = ASI_Money::resolve_base( '', $country );
		}
		if ( '' === $base && preg_match( '/_([A-Z]{2})/', (string) get_locale(), $m ) ) {
			$base = ASI_Money::resolve_base( '', $m[1] );
		}
		return array(
			'base'  => $base,
			'map'   => (array) $settings['currency_map'],
			'rates' => (array) $settings['currency_rates'],
		);
	}

	/**
	 * Sellos que se muestran en las tarjetas: etiqueta del sitio => cómo se muestra.
	 * Automático: solo los que distinguen (los tiene la mitad o menos del catálogo); un sello que
	 * tienen casi todos ("Nuevo", "Garantía activa") no le dice nada al cliente.
	 *
	 * @param string $lang Idioma del contexto.
	 * @return array<string,string>
	 */
	public static function card_flags( $lang ) {
		$shares = ASI_Index_Repository::flag_shares( $lang );
		$custom = (array) self::all()['card_flags'];
		$out    = array();
		foreach ( $shares as $label => $share ) {
			$rule = isset( $custom[ $label ] ) ? (array) $custom[ $label ] : array();
			$show = isset( $rule['show'] ) ? (bool) $rule['show'] : $share <= 0.5;
			if ( $show ) {
				$out[ $label ] = ! empty( $rule['as'] ) ? (string) $rule['as'] : $label;
			}
		}
		return $out;
	}

	/**
	 * Correos válidos de un texto ("a@x.com, b@y.com; c@z.com"), sin repetir.
	 *
	 * @param string $text Texto.
	 * @return string[]
	 */
	public static function email_list( $text ) {
		$out = array();
		foreach ( preg_split( '/[\s,;]+/', (string) $text ) as $email ) {
			$email = sanitize_email( $email );
			if ( is_email( $email ) && ! in_array( strtolower( $email ), array_map( 'strtolower', $out ), true ) ) {
				$out[] = $email;
			}
		}
		return array_slice( $out, 0, 10 );
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
