<?php
/**
 * Extrae el texto visible de un documento de Elementor (_elementor_data).
 *
 * Clase pura: recibe el JSON y devuelve texto plano. Recorre todos los elementos y toma
 * de sus ajustes los valores que son texto para el visitante (títulos, editores,
 * descripciones, pestañas, listas), no los de estilo ni los enlaces.
 *
 * @package AsesorInteligente
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'ASI_CLI_TESTS' ) ) {
	exit;
}

class ASI_Elementor_Text {

	/** Claves de ajustes que llevan texto visible. */
	const TEXT_KEY = '/(^|_)(title|text|editor|description|content|heading|caption|subtitle|label|prefix|suffix|question|answer|name|testimonial_content|html)$/';

	/** Claves que nunca son texto visible aunque terminen igual. */
	const SKIP_KEY = '/(color|colour|size|typography|font|url|link|href|id|css|class|align|tag|icon|animation|position|width|height|margin|padding|border|shadow|image|background|_type|view|layout|selected|justify|direction|flex|wrap|gap|overflow|display|style|separator|divider|content_width|motion|hover|transition|_mode)/';

	/** Valores que son ajustes de diseño aunque estén en una clave de texto. */
	const SKIP_VALUE = '/^(center|left|right|top|bottom|middle|start|end|space-between|space-around|space-evenly|flex-start|flex-end|stretch|row|column|row-reverse|column-reverse|boxed|full|full_width|none|default|inherit|initial|yes|no|true|false|auto|solid|dashed|dotted|custom|separador|separator|divider|wireframe|placeholder)$/i';

	const MAX_LENGTH = 20000;

	/**
	 * Texto plano del documento.
	 *
	 * @param string|array $data JSON de _elementor_data o su versión decodificada.
	 * @return string
	 */
	public static function extract( $data ) {
		if ( is_string( $data ) ) {
			$data = json_decode( $data, true );
		}
		if ( ! is_array( $data ) ) {
			return '';
		}

		$parts = array();
		self::walk_elements( $data, $parts );

		$text = trim( preg_replace( '/\s+/u', ' ', implode( ' . ', $parts ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_LENGTH ) : substr( $text, 0, self::MAX_LENGTH );
	}

	/**
	 * Recorre elementos (secciones, columnas, contenedores, widgets).
	 *
	 * @param array $elements Elementos.
	 * @param array $parts    Acumulado.
	 */
	private static function walk_elements( array $elements, array &$parts ) {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( ! empty( $element['settings'] ) && is_array( $element['settings'] ) ) {
				self::walk_settings( $element['settings'], $parts );
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::walk_elements( $element['elements'], $parts );
			}
		}
	}

	/**
	 * Recorre los ajustes, incluidos los repetidores (pestañas, listas, acordeones).
	 *
	 * @param array $settings Ajustes.
	 * @param array $parts    Acumulado.
	 */
	private static function walk_settings( array $settings, array &$parts ) {
		foreach ( $settings as $key => $value ) {
			if ( '__dynamic__' === $key || '__globals__' === $key ) {
				continue;
			}
			if ( is_array( $value ) ) {
				// Repetidor: lista de filas con sus propios ajustes.
				if ( isset( $value[0] ) && is_array( $value[0] ) ) {
					foreach ( $value as $row ) {
						if ( is_array( $row ) ) {
							self::walk_settings( $row, $parts );
						}
					}
				}
				continue;
			}
			if ( ! is_string( $value ) || '' === trim( $value ) || ! is_string( $key ) ) {
				continue;
			}
			if ( ! preg_match( self::TEXT_KEY, $key ) || preg_match( self::SKIP_KEY, $key ) ) {
				continue;
			}
			$plain = trim( html_entity_decode( strip_tags( str_replace( array( '<br>', '<br/>', '<br />', '</p>', '</li>' ), ' ', $value ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( '' !== $plain && ! preg_match( '/^[\d\s.,%px-]+$/', $plain ) && ! preg_match( self::SKIP_VALUE, $plain ) ) {
				$parts[] = $plain;
			}
		}
	}
}
