<?php
/**
 * WhatsApp de ventas: se saca del sitio, no se pide.
 *
 * No es un ajuste del plugin: se busca en el sitio, en este orden:
 * 1. Un campo del tema o de opciones con "whatsapp" en el nombre (muchos temas hijo lo traen).
 * 2. Los enlaces de WhatsApp de Elementor: primero el botón flotante (posición fija), luego el
 *    que más se repite; en plantillas (encabezado/pie) pesa más que en una página suelta.
 *
 * Lo detectado se guarda un día; al reindexar el sitio se vuelve a buscar.
 *
 * @package AsesorInteligente
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'ASI_CLI_TESTS' ) ) {
	exit;
}

class ASI_WhatsApp {

	const TRANSIENT = 'asi_whatsapp_found';

	/**
	 * El WhatsApp que usa el chat.
	 *
	 * @return array{value:string,source:string} value = "+50232699880" o un enlace; '' si no hay.
	 */
	public static function get() {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['value'] ) ) {
			return $cached;
		}
		$found = self::detect();
		set_transient( self::TRANSIENT, $found, DAY_IN_SECONDS );
		return $found;
	}

	public static function forget() {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Busca en el sitio (solo lectura).
	 */
	public static function detect() {
		global $wpdb;

		// 1. Campos del tema / opciones: "whatsapp", "numero_whatsapp", "options_whatsapp" (ACF)…
		$options = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '%whatsapp%' AND option_name NOT LIKE '\\_transient%' AND option_name NOT LIKE '\\_site\\_transient%' AND option_name <> '" . esc_sql( self::TRANSIENT ) . "' LIMIT 50", ARRAY_A );
		foreach ( (array) $options as $row ) {
			$number = self::number_from( maybe_unserialize( $row['option_value'] ) );
			if ( '' !== $number ) {
				return array( 'value' => $number, 'source' => 'Campo del sitio: ' . $row['option_name'] );
			}
		}
		foreach ( (array) get_theme_mods() as $key => $value ) {
			if ( false !== stripos( (string) $key, 'whatsapp' ) ) {
				$number = self::number_from( $value );
				if ( '' !== $number ) {
					return array( 'value' => $number, 'source' => 'Campo del tema: ' . $key );
				}
			}
		}

		// 2. Botones y enlaces de Elementor.
		$rows = $wpdb->get_results(
			"SELECT p.post_type, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = '_elementor_data' AND p.post_status = 'publish'
			AND ( pm.meta_value LIKE '%wa.me%' OR pm.meta_value LIKE '%api.whatsapp.com%' OR pm.meta_value LIKE '%whatsapp://%' ) LIMIT 200",
			ARRAY_A
		);
		$scores = array();
		foreach ( (array) $rows as $row ) {
			$data = json_decode( (string) $row['meta_value'], true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$template = 'elementor_library' === $row['post_type'];
			foreach ( self::links_in_elementor( $data ) as $link ) {
				$scores[ $link['number'] ] = ( isset( $scores[ $link['number'] ] ) ? $scores[ $link['number'] ] : 0 ) + ( $link['fixed'] ? 100 : 1 ) + ( $template ? 2 : 0 );
			}
		}
		$best = self::best( $scores );
		return array( 'value' => $best, 'source' => '' === $best ? '' : 'Botón de WhatsApp del sitio (Elementor)' );
	}

	/**
	 * Enlaces de WhatsApp en un árbol de Elementor, con si el elemento flota (posición fija).
	 *
	 * @return array[] { number, fixed }
	 */
	public static function links_in_elementor( array $elements ) {
		$out  = array();
		$walk = function ( $nodes ) use ( &$walk, &$out ) {
			foreach ( (array) $nodes as $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}
				$settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
				$fixed    = isset( $settings['_position'] ) && 'fixed' === $settings['_position'];
				array_walk_recursive(
					$settings,
					function ( $value ) use ( &$out, $fixed ) {
						if ( is_string( $value ) && preg_match( '#(wa\.me|api\.whatsapp\.com|whatsapp://)#i', $value ) ) {
							$number = ASI_WhatsApp::number_from( $value );
							if ( '' !== $number ) {
								$out[] = array( 'number' => $number, 'fixed' => $fixed );
							}
						}
					}
				);
				if ( isset( $node['elements'] ) ) {
					$walk( $node['elements'] );
				}
			}
		};
		$walk( $elements );
		return $out;
	}

	/**
	 * "+502 3269-9880", "https://wa.me/+50232699880?text=…", "api.whatsapp.com/send?phone=502…" → "+50232699880".
	 * Un enlace sin número (wa.me/message/…) o un valor sin 8 dígitos → ''.
	 */
	public static function number_from( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				$number = self::number_from( $item );
				if ( '' !== $number ) {
					return $number;
				}
			}
			return '';
		}
		$value = trim( (string) $value );
		if ( preg_match( '#wa\.me/\+?([0-9]{10,15})#i', $value, $m ) || preg_match( '#[?&]phone=\+?([0-9]{10,15})#i', $value, $m ) ) {
			return '+' . $m[1];
		}
		if ( preg_match( '#(wa\.me|whatsapp)#i', $value ) ) {
			return '';
		}
		// Un número suelto solo si trae código de país (10+ dígitos): "3269-9880" no dice de qué país es.
		$digits = preg_replace( '/[^0-9]/', '', $value );
		return strlen( $digits ) >= 10 && strlen( $digits ) <= 15 && preg_match( '/^\+?[0-9\s\-().]+$/', $value ) ? '+' . $digits : '';
	}

	/** El número con más puntos; '' si ninguno. */
	public static function best( array $scores ) {
		if ( ! $scores ) {
			return '';
		}
		arsort( $scores );
		return (string) key( $scores );
	}
}
