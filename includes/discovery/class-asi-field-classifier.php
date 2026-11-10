<?php
/**
 * Clasifica campos y taxonomías según su rol en la búsqueda.
 *
 * Clase pura (sin WordPress): decide por nombre, etiqueta, tipo ACF y valores de muestra.
 * No conoce ningún sitio concreto; reconoce convenciones comunes en español e inglés.
 *
 * Roles de campo:
 *  - code:      código, SKU, modelo, referencia. Búsqueda exacta y máxima prioridad.
 *  - brand:     marca o fabricante.
 *  - price:     precio numérico.
 *  - image:     imagen o galería (solo tarjeta).
 *  - document:  archivo o PDF (ficha técnica, solo enlace).
 *  - link:      URL (no se indexa como texto).
 *  - summary:   descripción larga (texto buscable, resumen de la tarjeta).
 *  - flag:      verdadero/falso ("Oferta", "Nuevo"); si es verdadero aporta su etiqueta como palabra.
 *  - attribute: dato corto buscable (potencia, capacidad, tipo…), se puede mostrar como especificación.
 *  - related:   relación con otros posts (se indexan sus títulos).
 *  - ignore:    técnico o sin valor para buscar.
 *
 * @package AsesorInteligente
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'ASI_CLI_TESTS' ) ) {
	exit;
}

class ASI_Field_Classifier {

	const PATTERNS = array(
		'code'     => '/(^|[^a-z])(cod|codigo|code|sku|modelo|model|referencia|ref|part[ _-]?(no|number|num)|numero[ _-]?de[ _-]?parte|no[ _-]?parte|serie|serial|item[ _-]?(no|number))([^a-z]|$)/',
		'brand'    => '/(^|[^a-z])(marca|marcas|brand|brands|fabricante|manufacturer|make)([^a-z]|$)/',
		'price'    => '/(^|[^a-z])(precio|price|costo|cost|valor|tarifa)([^a-z]|$)/',
		'document' => '/(^|[^a-z])(pdf|ficha|brochure|catalogo|catalog|datasheet|manual|documento|document|archivo|file|descarga|download)([^a-z]|$)/',
		'image'    => '/(^|[^a-z])(imagen|imagenes|image|images|foto|fotos|photo|gallery|galeria|logo|thumbnail|icono|icon)([^a-z]|$)/',
		'link'     => '/(^|[^a-z])(url|link|enlace|video|youtube|href|boton|button|cta)([^a-z]|$)/',
		'summary'  => '/(^|[^a-z])(descripcion|description|desc|resumen|summary|extracto|excerpt|intro|introduccion|detalle|detalles|details|caracteristicas|features|beneficios|benefits|contenido|content|texto|text)([^a-z]|$)/',
		'ignore'   => '/(^|[^a-z])(color[ _-]?(fondo|background)|css|clase|class|orden|order|layout|template|plantilla|id[ _-]?externo|hash|token|json[ _-]?raw|seo|schema|total[ _-]?sales|views?[ _-]?count|review[ _-]?count|rating[ _-]?count|average[ _-]?rating|lat|lon|lng|latitude|longitude|latitud|longitud|zoom)([^a-z]|$)/',
	);

	/**
	 * Tipos ACF y su rol directo.
	 */
	const ACF_TYPES = array(
		'image'        => 'image',
		'gallery'      => 'image',
		'file'         => 'document',
		'url'          => 'link',
		'link'         => 'link',
		'oembed'       => 'link',
		'email'        => 'ignore',
		'password'     => 'ignore',
		'color_picker' => 'ignore',
		'google_map'   => 'ignore',
		'date_picker'  => 'ignore',
		'time_picker'  => 'ignore',
		'date_time_picker' => 'ignore',
		'true_false'   => 'flag',
		'post_object'  => 'related',
		'relationship' => 'related',
		'page_link'    => 'link',
		'taxonomy'     => 'attribute',
		'user'         => 'ignore',
		'wysiwyg'      => 'summary',
		'textarea'     => 'summary',
		'message'      => 'ignore',
		'tab'          => 'ignore',
		'accordion'    => 'ignore',
	);

	/**
	 * Rol de un campo.
	 *
	 * @param string $name    Nombre (meta key).
	 * @param string $label   Etiqueta legible.
	 * @param string $type    Tipo ACF ('' si es meta suelta).
	 * @param array  $samples Valores de muestra (escalares).
	 * @return string
	 */
	public static function classify_field( $name, $label = '', $type = '', array $samples = array() ) {
		$haystack = self::haystack( $name . ' ' . $label );

		if ( preg_match( self::PATTERNS['ignore'], $haystack ) ) {
			return 'ignore';
		}

		// Los tipos ACF que no son texto mandan sobre el nombre.
		if ( '' !== $type && isset( self::ACF_TYPES[ $type ] ) && ! in_array( self::ACF_TYPES[ $type ], array( 'summary', 'attribute' ), true ) ) {
			return self::ACF_TYPES[ $type ];
		}

		foreach ( array( 'code', 'brand', 'price', 'document', 'image', 'link' ) as $role ) {
			if ( preg_match( self::PATTERNS[ $role ], $haystack ) ) {
				if ( 'price' === $role && ! self::samples_are_numeric( $samples ) && ! empty( $samples ) ) {
					continue;
				}
				return $role;
			}
		}

		if ( preg_match( self::PATTERNS['summary'], $haystack ) ) {
			return 'summary';
		}
		if ( '' !== $type && isset( self::ACF_TYPES[ $type ] ) ) {
			return self::ACF_TYPES[ $type ];
		}

		return self::classify_by_samples( $samples );
	}

	/**
	 * Rol de una taxonomía: category (jerárquica), brand o tag.
	 *
	 * @param string $name         Slug de la taxonomía.
	 * @param string $label        Etiqueta.
	 * @param bool   $hierarchical Si es jerárquica.
	 * @return string
	 */
	public static function classify_taxonomy( $name, $label, $hierarchical ) {
		$haystack = self::haystack( $name . ' ' . $label );
		if ( preg_match( self::PATTERNS['brand'], $haystack ) ) {
			return 'brand';
		}
		return $hierarchical ? 'category' : 'tag';
	}

	/**
	 * Sin pistas por nombre: decide por los valores.
	 *
	 * @param array $samples Valores.
	 * @return string
	 */
	private static function classify_by_samples( array $samples ) {
		$samples = array_values(
			array_filter(
				$samples,
				function ( $value ) {
					return is_scalar( $value ) && '' !== trim( (string) $value );
				}
			)
		);
		if ( empty( $samples ) ) {
			return 'attribute';
		}

		$booleans = array_filter(
			$samples,
			function ( $value ) {
				return in_array( strtolower( trim( (string) $value ) ), array( 'true', 'false', '0', '1', 'si', 'sí', 'no', 'yes' ), true );
			}
		);
		if ( count( $booleans ) === count( $samples ) ) {
			return 'flag';
		}

		$urls = 0;
		$long = 0;
		$json = 0;
		foreach ( $samples as $value ) {
			$value = trim( (string) $value );
			if ( preg_match( '#^https?://#i', $value ) ) {
				$urls++;
			}
			if ( strlen( $value ) > 160 ) {
				$long++;
			}
			if ( preg_match( '/^[\[{]/', $value ) || preg_match( '/^[aOs]:\d+:/', $value ) ) {
				$json++;
			}
		}
		$count = count( $samples );

		if ( $urls / $count > 0.5 ) {
			return preg_match( '/\.pdf(\?|$)/i', $samples[0] ) ? 'document' : 'link';
		}
		if ( $json / $count > 0.5 ) {
			// JSON o serializado: se indexa su texto (caso "json_vehiculo" de la v1).
			return 'summary';
		}
		if ( $long / $count > 0.3 ) {
			return 'summary';
		}
		return 'attribute';
	}

	/**
	 * ¿Todos los valores de muestra son números (con o sin símbolo de moneda)?
	 *
	 * @param array $samples Valores.
	 * @return bool
	 */
	private static function samples_are_numeric( array $samples ) {
		foreach ( $samples as $value ) {
			if ( '' === trim( (string) $value ) ) {
				continue;
			}
			if ( ! preg_match( '/^[^0-9]{0,4}\s?[0-9][0-9.,\s]*$/', trim( (string) $value ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Texto comparable: minúsculas, sin acentos, separadores como espacio.
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	private static function haystack( $text ) {
		// camelCase → palabras, antes de pasar a minúsculas.
		$text = preg_replace( '/([a-z])([A-Z])/', '$1 $2', (string) $text );
		$text = strtolower( strtr( $text, array( 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'ñ' => 'n', 'Ñ' => 'n' ) ) );
		return ' ' . preg_replace( '/[^a-z0-9]+/', ' ', $text ) . ' ';
	}
}
