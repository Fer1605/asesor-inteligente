<?php
/**
 * Apariencia del chat: colores, esquinas y asistente.
 *
 * Cada color sale, en este orden, de: el ajuste del panel → el Kit de Elementor del sitio → un valor neutro.
 * Así el chat se ve como el sitio desde que se instala, y se puede cambiar cuando el Kit no
 * está bien configurado o se quieren colores especiales para el chat.
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || exit;

class ASI_Appearance {

	/**
	 * Colores de botones: si están vacíos, se derivan de los colores base para que siempre combinen.
	 * valor => color base del que sale ('on-primary' = el texto que se lee sobre el principal).
	 */
	const DERIVED = array(
		'button'       => 'primary',
		'button_hover' => 'accent',
		'reset_bg'     => 'background',
		'reset_text'   => 'primary',
		'reset_hover'  => 'surface',
	);

	/** Valores neutros si no hay Kit ni ajuste. */
	const FALLBACK = array(
		'primary'    => '#1f2937',
		'accent'     => '#2563eb',
		'text'       => '#1f2937',
		'background' => '#ffffff',
		'surface'    => '#f3f4f6',
	);

	/**
	 * Qué color global del Kit alimenta cada color del chat. El texto no se toma del Kit: el de
	 * fábrica de Elementor es un gris claro que cuesta leer; va oscuro salvo que se elija otro.
	 */
	const KIT_MAP = array(
		'primary' => 'primary',
		'accent'  => 'accent',
	);

	/**
	 * Colores globales del Kit de Elementor activo (primary, secondary, text, accent).
	 *
	 * @return array<string,string>
	 */
	public static function kit_colors() {
		$kit_id = (int) get_option( 'elementor_active_kit' );
		if ( ! $kit_id ) {
			return array();
		}
		$settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
		$colors   = array();
		foreach ( isset( $settings['system_colors'] ) ? (array) $settings['system_colors'] : array() as $color ) {
			if ( isset( $color['_id'], $color['color'] ) && self::is_color( $color['color'] ) ) {
				$colors[ $color['_id'] ] = $color['color'];
			}
		}
		return $colors;
	}

	/**
	 * Colores efectivos del chat, con el origen de cada uno (panel, kit o neutro).
	 *
	 * @return array<string,array{value:string,source:string}>
	 */
	public static function palette() {
		$settings = ASI_Settings::all();
		$kit      = self::kit_colors();
		$palette  = array();

		foreach ( ASI_Settings::COLOR_KEYS as $key ) {
			$custom = isset( $settings['colors'][ $key ] ) ? $settings['colors'][ $key ] : '';
			if ( isset( self::DERIVED[ $key ] ) ) {
				continue;
			}
			if ( self::is_color( $custom ) ) {
				$palette[ $key ] = array( 'value' => $custom, 'source' => 'custom' );
			} elseif ( isset( self::KIT_MAP[ $key ], $kit[ self::KIT_MAP[ $key ] ] ) ) {
				$palette[ $key ] = array( 'value' => $kit[ self::KIT_MAP[ $key ] ], 'source' => 'kit' );
			} else {
				$palette[ $key ] = array( 'value' => self::FALLBACK[ $key ], 'source' => 'default' );
			}
		}

		foreach ( self::DERIVED as $key => $base ) {
			$custom = isset( $settings['colors'][ $key ] ) ? $settings['colors'][ $key ] : '';
			if ( self::is_color( $custom ) ) {
				$palette[ $key ] = array( 'value' => $custom, 'source' => 'custom' );
				continue;
			}
			$value = $palette[ $base ]['value'];
			// Texto del botón blanco "Nueva conversación": el principal si es oscuro; si es claro, el texto.
			if ( 'reset_text' === $key && '#ffffff' !== self::contrast( $value ) ) {
				$value = $palette['text']['value'];
				$base  = 'text';
			}
			$palette[ $key ] = array( 'value' => $value, 'source' => 'derived', 'from' => $base );
		}
		return $palette;
	}

	/**
	 * Variables CSS del chat (se imprimen en el contenedor).
	 *
	 * @return string
	 */
	public static function css_variables() {
		$settings = ASI_Settings::all();
		$vars     = array();
		foreach ( self::palette() as $key => $color ) {
			$vars[ '--asi-' . $key ] = $color['value'];
		}
		$vars['--asi-on-primary']      = self::contrast( $vars['--asi-primary'] );
		$vars['--asi-on-accent']       = self::contrast( $vars['--asi-accent'] );
		$vars['--asi-on-button']       = self::contrast( $vars['--asi-button'] );
		$vars['--asi-on-button_hover'] = self::contrast( $vars['--asi-button_hover'] );
		$radius                  = isset( ASI_Settings::RADII[ $settings['radius'] ] ) ? ASI_Settings::RADII[ $settings['radius'] ] : ASI_Settings::RADII['rounded'];
		$vars['--asi-radius']     = $radius . 'px';

		$css = '';
		foreach ( $vars as $name => $value ) {
			$css .= $name . ':' . $value . ';';
		}
		return $css;
	}

	/**
	 * Blanco o casi negro, según qué se lea mejor sobre el color.
	 *
	 * @param string $hex Color.
	 * @return string
	 */
	public static function contrast( $hex ) {
		$rgb = self::rgb( $hex );
		if ( ! $rgb ) {
			return '#ffffff';
		}
		$luminance = 0;
		foreach ( array( 0.2126, 0.7152, 0.0722 ) as $i => $weight ) {
			$channel    = $rgb[ $i ] / 255;
			$channel    = $channel <= 0.03928 ? $channel / 12.92 : pow( ( $channel + 0.055 ) / 1.055, 2.4 );
			$luminance += $weight * $channel;
		}
		return $luminance > 0.45 ? '#111827' : '#ffffff';
	}

	/**
	 * ¿Es un color hexadecimal válido?
	 *
	 * @param string $value Valor.
	 * @return bool
	 */
	public static function is_color( $value ) {
		return is_string( $value ) && (bool) preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value );
	}

	/**
	 * Avatar del asistente (URL) o ''.
	 *
	 * @return string
	 */
	public static function avatar_url() {
		$id = (int) ASI_Settings::all()['assistant_avatar'];
		return $id ? (string) wp_get_attachment_image_url( $id, 'thumbnail' ) : '';
	}

	private static function rgb( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( strlen( $hex ) < 6 ) {
			return null;
		}
		return array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
	}
}
