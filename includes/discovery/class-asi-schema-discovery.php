<?php
/**
 * Autodescubrimiento de la estructura del sitio.
 *
 * Al activar el plugin (y cuando se pide desde el admin) recorre lo que el sitio tiene
 * —tipos de contenido, taxonomías, campos ACF, meta suelta, WooCommerce, Elementor— y
 * guarda un "esquema" con el rol de cada pieza. El motor de búsqueda solo lee este esquema;
 * nunca conoce nombres de un sitio concreto.
 *
 * Solo lectura: no modifica nada del sitio. Las consultas están acotadas con LIMIT para
 * no cargar servidores lentos.
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || exit;

class ASI_Schema_Discovery {

	const OPTION        = 'asi_schema';
	const SCHEMA_VERSION = 1;

	/**
	 * Tipos que nunca son contenido para el visitante.
	 */
	const EXCLUDED_POST_TYPES = array(
		'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request',
		'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face',
		'elementor_library', 'elementor_snippet', 'elementor_font', 'elementor_icons', 'e-landing-page', 'e-floating-buttons',
		'product_variation', 'shop_order', 'shop_coupon', 'shop_order_refund', 'acf-field-group', 'acf-field', 'acf-post-type', 'acf-taxonomy',
		'um_form', 'um_directory', 'wpcf7_contact_form', 'wpforms', 'jet-engine', 'jet-smart-filters',
	);

	/**
	 * Taxonomías internas de plugins multilingües, WooCommerce y del núcleo.
	 */
	const EXCLUDED_TAXONOMIES = array(
		'language', 'post_translations', 'term_language', 'term_translations', 'post_format', 'nav_menu', 'link_category',
		'product_type', 'product_visibility', 'product_shipping_class', 'translation_priority', 'elementor_library_type', 'elementor_library_category',
	);

	/** @var ASI_Language_Adapter */
	private $languages;

	public function __construct( ASI_Language_Adapter $languages ) {
		$this->languages = $languages;
	}

	/**
	 * Esquema guardado (o vacío si aún no se descubrió).
	 *
	 * @return array
	 */
	public static function get_schema() {
		$schema = get_option( self::OPTION );
		return is_array( $schema ) ? $schema : array();
	}

	/**
	 * Descubre y guarda el esquema. Conserva los ajustes manuales del admin (enabled/role).
	 *
	 * @return array
	 */
	public function run() {
		$previous = self::get_schema();

		$schema = array(
			'version'       => self::SCHEMA_VERSION,
			'discovered_at' => time(),
			'multilingual'  => $this->languages->get_id(),
			'woocommerce'   => class_exists( 'WooCommerce' ),
			'acf'           => function_exists( 'acf_get_field_groups' ),
			'elementor'     => defined( 'ELEMENTOR_VERSION' ),
			'post_types'    => array(),
		);

		foreach ( $this->candidate_post_types() as $post_type => $object ) {
			$count = $this->count_published( $post_type );
			if ( $count < 1 ) {
				continue;
			}

			$record = array(
				'label'      => $object->labels->name,
				'singular'   => $object->labels->singular_name,
				'count'      => $count,
				'role'       => $this->guess_post_type_role( $post_type, $object, $count ),
				'enabled'    => true,
				'translated' => $this->languages->is_translated_post_type( $post_type ),
				'taxonomies' => $this->discover_taxonomies( $post_type ),
				'fields'     => $this->discover_fields( $post_type ),
				'elementor'  => $schema['elementor'] && $this->uses_elementor( $post_type ),
			);
			$record = $this->review_plugin_config( $post_type, $record );

			$schema['post_types'][ $post_type ] = $this->keep_manual_overrides( $record, $previous, $post_type );
		}

		$schema = apply_filters( 'asi_discovered_schema', $schema );
		update_option( self::OPTION, $schema, false );

		return $schema;
	}

	/**
	 * Tipos de contenido públicos que un visitante puede ver.
	 *
	 * @return WP_Post_Type[]
	 */
	private function candidate_post_types() {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		$types = array_merge( $types, get_post_types( array( 'publicly_queryable' => true ), 'objects' ) );

		foreach ( array_keys( $types ) as $post_type ) {
			if ( in_array( $post_type, self::EXCLUDED_POST_TYPES, true ) ) {
				unset( $types[ $post_type ] );
			}
		}
		return $types;
	}

	/**
	 * Rol del tipo de contenido:
	 *  - info:    páginas institucionales (page).
	 *  - article: noticias, blog, boletines (post o tipos sin taxonomía de catálogo y con fecha como eje).
	 *  - catalog: productos, servicios, insumos… (lo que se muestra como tarjeta).
	 *
	 * @param string       $post_type Tipo.
	 * @param WP_Post_Type $object    Objeto.
	 * @param int          $count     Publicados.
	 * @return string
	 */
	private function guess_post_type_role( $post_type, $object, $count ) {
		if ( 'page' === $post_type ) {
			return 'info';
		}
		if ( 'product' === $post_type && class_exists( 'WooCommerce' ) ) {
			return 'catalog';
		}
		if ( 'post' === $post_type || preg_match( '/(news|noticia|blog|boletin|newsletter|articulo|article|evento|event|prensa|press)/', $post_type . ' ' . strtolower( remove_accents( $object->labels->name ) ) ) ) {
			return 'article';
		}
		return 'catalog';
	}

	/**
	 * Detecta contenido interno de otros plugins y lo aparta:
	 *  - Meta con un prefijo común de plugin ("locate-anything-…") en la mayoría de las claves: es configuración.
	 *  - Un tipo que, sin esa configuración, no tiene taxonomías ni campos útiles y casi no tiene posts
	 *    (p. ej. la configuración de un mapa) queda desactivado.
	 *  - Tipos de ubicaciones (sucursales, tiendas, marcadores de mapa) son información, no catálogo.
	 *
	 * @param string $post_type Tipo.
	 * @param array  $record    Registro descubierto.
	 * @return array
	 */
	private function review_plugin_config( $post_type, array $record ) {
		$meta_keys = array();
		foreach ( $record['fields'] as $name => $field ) {
			if ( 'meta' === $field['source'] ) {
				$meta_keys[] = $name;
			}
		}
		$prefix = self::common_prefix( $meta_keys );
		if ( '' !== $prefix ) {
			foreach ( $meta_keys as $name ) {
				if ( 0 === strpos( $name, $prefix ) ) {
					$record['fields'][ $name ]['role'] = 'ignore';
				}
			}
		}

		$useful_fields = array_filter(
			$record['fields'],
			function ( $field ) {
				return 'ignore' !== $field['role'];
			}
		);
		if ( ! in_array( $post_type, array( 'page', 'post', 'product' ), true ) && ! $record['taxonomies'] && ! $useful_fields && $record['count'] <= 3 ) {
			$record['enabled']       = false;
			$record['auto_disabled'] = 'plugin_config'; // Se explica en el panel.
		}

		if ( 'catalog' === $record['role'] && preg_match( '/(marker|location|ubicacion|sucursal|store|tienda|branch|agencia|punto|office|oficina|map)/', $post_type . ' ' . strtolower( remove_accents( $record['label'] ) ) ) ) {
			$record['role'] = 'info';
			$record['kind'] = 'location'; // Sucursales o tiendas: se listan al preguntar "¿dónde están?".
		}
		return $record;
	}

	/**
	 * Prefijo de plugin que comparten al menos el 70 % de las claves (mínimo 5 claves, 5 caracteres).
	 *
	 * @param string[] $keys Claves.
	 * @return string
	 */
	public static function common_prefix( array $keys ) {
		if ( count( $keys ) < 5 ) {
			return '';
		}
		$counts = array();
		foreach ( $keys as $key ) {
			if ( preg_match( '/^([a-z0-9]+[-_][a-z0-9]+[-_])/i', $key, $m ) || preg_match( '/^([a-z0-9]{5,}[-_])/i', $key, $m ) ) {
				$counts[ $m[1] ] = isset( $counts[ $m[1] ] ) ? $counts[ $m[1] ] + 1 : 1;
			}
		}
		if ( ! $counts ) {
			return '';
		}
		arsort( $counts );
		$prefix = key( $counts );
		return ( strlen( $prefix ) >= 5 && $counts[ $prefix ] / count( $keys ) >= 0.7 ) ? $prefix : '';
	}

	/**
	 * Taxonomías públicas del tipo con su rol.
	 *
	 * @param string $post_type Tipo.
	 * @return array
	 */
	private function discover_taxonomies( $post_type ) {
		$result = array();
		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy => $object ) {
			if ( in_array( $taxonomy, self::EXCLUDED_TAXONOMIES, true ) || ( empty( $object->public ) && empty( $object->publicly_queryable ) ) ) {
				continue;
			}
			$role = ASI_Field_Classifier::classify_taxonomy( $taxonomy, $object->labels->name, (bool) $object->hierarchical );
			// WooCommerce: los atributos globales (pa_*) son especificaciones.
			if ( 0 === strpos( $taxonomy, 'pa_' ) ) {
				$role = 'brand' === $role ? 'brand' : 'attribute';
			}
			$result[ $taxonomy ] = array(
				'label'        => $object->labels->name,
				'hierarchical' => (bool) $object->hierarchical,
				'role'         => $role,
			);
		}
		return $result;
	}

	/**
	 * Campos del tipo: primero los grupos ACF asignados; luego la meta pública que ACF no declara.
	 *
	 * @param string $post_type Tipo.
	 * @return array nombre => label, type, source, role.
	 */
	private function discover_fields( $post_type ) {
		$fields = array();

		if ( function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) ) {
			foreach ( (array) acf_get_field_groups( array( 'post_type' => $post_type ) ) as $group ) {
				foreach ( (array) acf_get_fields( $group ) as $field ) {
					$this->add_acf_field( $fields, $field, $post_type );
				}
			}
		}

		if ( 'product' === $post_type && class_exists( 'WooCommerce' ) ) {
			$fields['_sku']   = array( 'label' => 'SKU', 'type' => 'wc', 'source' => 'woocommerce', 'role' => 'code' );
			$fields['_price'] = array( 'label' => 'Precio', 'type' => 'wc', 'source' => 'woocommerce', 'role' => 'price' );
		}

		foreach ( $this->sample_public_meta( $post_type ) as $meta_key => $samples ) {
			if ( isset( $fields[ $meta_key ] ) ) {
				continue;
			}
			$json = $this->describe_json( $samples );
			if ( $json ) {
				$fields[ $meta_key ] = array(
					'label'    => $meta_key,
					'type'     => 'json',
					'source'   => 'meta',
					'role'     => 'container',
					'children' => $json,
				);
				continue;
			}
			$fields[ $meta_key ] = array(
				'label'  => $meta_key,
				'type'   => '',
				'source' => 'meta',
				'role'   => ASI_Field_Classifier::classify_field( $meta_key, '', '', $samples ),
			);
		}

		return $fields;
	}

	/**
	 * Agrega un campo ACF; los contenedores (repeater, group, flexible) se guardan con sus subcampos
	 * porque su valor se lee entero con get_field() y se recorre al indexar.
	 *
	 * @param array  $fields    Acumulado.
	 * @param array  $field     Campo ACF.
	 * @param string $post_type Tipo.
	 */
	private function add_acf_field( array &$fields, array $field, $post_type ) {
		$name = isset( $field['name'] ) ? (string) $field['name'] : '';
		$type = isset( $field['type'] ) ? (string) $field['type'] : '';
		if ( '' === $name ) {
			return;
		}

		if ( in_array( $type, array( 'repeater', 'group', 'flexible_content' ), true ) ) {
			$sub_fields = isset( $field['sub_fields'] ) ? (array) $field['sub_fields'] : array();
			if ( 'flexible_content' === $type && ! empty( $field['layouts'] ) ) {
				foreach ( (array) $field['layouts'] as $layout ) {
					$sub_fields = array_merge( $sub_fields, isset( $layout['sub_fields'] ) ? (array) $layout['sub_fields'] : array() );
				}
			}
			$children = array();
			foreach ( $sub_fields as $sub_field ) {
				$this->add_acf_field( $children, $sub_field, $post_type );
			}
			$fields[ $name ] = array(
				'label'    => isset( $field['label'] ) ? (string) $field['label'] : $name,
				'type'     => $type,
				'source'   => 'acf',
				'role'     => 'container',
				'children' => $children,
			);
			return;
		}

		$label           = isset( $field['label'] ) ? (string) $field['label'] : $name;
		$samples         = in_array( $type, array( 'text', 'number', 'select', 'radio', '' ), true ) ? $this->sample_meta_values( $post_type, $name ) : array();
		$fields[ $name ] = array(
			'label'  => $label,
			'type'   => $type,
			'source' => 'acf',
			'role'   => ASI_Field_Classifier::classify_field( $name, $label, $type, $samples ),
		);
	}

	/**
	 * Meta pública (sin guion bajo inicial) usada por el tipo, con valores de muestra.
	 * Una sola consulta agregada, acotada.
	 *
	 * @param string $post_type Tipo.
	 * @return array meta_key => muestras.
	 */
	private function sample_public_meta( $post_type ) {
		global $wpdb;

		$keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm.meta_key
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type = %s AND p.post_status = 'publish'
					AND pm.meta_key NOT LIKE %s
					AND pm.meta_value <> ''
				GROUP BY pm.meta_key
				ORDER BY COUNT(*) DESC
				LIMIT 60",
				$post_type,
				$wpdb->esc_like( '_' ) . '%'
			)
		);

		$result = array();
		foreach ( (array) $keys as $key ) {
			// Referencias internas de ACF ("field_abc123") no son datos.
			// Filas de repeater ACF ("specs_0_valor") ya van dentro de su contenedor.
			if ( preg_match( '/^(field_|rank_math|yoast|wpseo|ekit|pll_)/', $key ) || preg_match( '/_\d+_/', $key ) ) {
				continue;
			}
			$result[ $key ] = $this->sample_meta_values( $post_type, $key );
		}
		return $result;
	}

	/**
	 * Si las muestras son JSON, describe cada ruta interna con su rol (código, marca, distintivo…).
	 *
	 * @param string[] $samples Valores de muestra (recortados a 400 caracteres).
	 * @return array Subcampos por ruta, o vacío si no es JSON.
	 */
	private function describe_json( array $samples ) {
		$decoded = array();
		foreach ( $samples as $sample ) {
			$value = ASI_Json_Paths::decode( $sample );
			if ( null !== $value ) {
				$decoded[] = $value;
			}
		}
		if ( ! $decoded ) {
			return array();
		}

		$children = array();
		foreach ( ASI_Json_Paths::describe( $decoded ) as $path => $values ) {
			$label             = ASI_Json_Paths::label( $path );
			$children[ $path ] = array(
				'label'  => $label,
				'type'   => '',
				'source' => 'json',
				// Solo la última parte de la ruta: "detalle.color" es un color, no una descripción.
				'role'   => ASI_Field_Classifier::classify_field( substr( strrchr( '.' . $path, '.' ), 1 ), $label, '', $values ),
			);
		}
		return $children;
	}

	/**
	 * Hasta 8 valores de muestra de una meta.
	 *
	 * @param string $post_type Tipo.
	 * @param string $meta_key  Meta.
	 * @return string[]
	 */
	private function sample_meta_values( $post_type, $meta_key ) {
		global $wpdb;

		return (array) $wpdb->get_col(
			$wpdb->prepare(
				// Suficiente para que un JSON de muestra llegue entero y se pueda recorrer.
				"SELECT LEFT(pm.meta_value, 20000)
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type = %s AND p.post_status = 'publish' AND pm.meta_key = %s AND pm.meta_value <> ''
				LIMIT 8",
				$post_type,
				$meta_key
			)
		);
	}

	/**
	 * ¿Los posts de este tipo guardan su contenido en Elementor?
	 *
	 * @param string $post_type Tipo.
	 * @return bool
	 */
	private function uses_elementor( $post_type ) {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type = %s AND p.post_status = 'publish' AND pm.meta_key = '_elementor_data' AND pm.meta_value <> '' AND pm.meta_value <> '[]'
				LIMIT 1",
				$post_type
			)
		);
	}

	/**
	 * Publicados de un tipo.
	 *
	 * @param string $post_type Tipo.
	 * @return int
	 */
	private function count_published( $post_type ) {
		$counts = wp_count_posts( $post_type );
		return isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	/**
	 * Mantiene lo que el administrador cambió a mano en un descubrimiento anterior.
	 *
	 * @param array  $record    Registro recién descubierto.
	 * @param array  $previous  Esquema anterior.
	 * @param string $post_type Tipo.
	 * @return array
	 */
	private function keep_manual_overrides( array $record, array $previous, $post_type ) {
		if ( empty( $previous['post_types'][ $post_type ]['manual'] ) ) {
			return $record;
		}
		$old = $previous['post_types'][ $post_type ];

		$record['manual']  = $old['manual'];
		$record['enabled'] = isset( $old['enabled'] ) ? (bool) $old['enabled'] : true;
		if ( ! empty( $old['manual']['role'] ) ) {
			$record['role'] = $old['role'];
		}
		foreach ( (array) ( isset( $old['manual']['fields'] ) ? $old['manual']['fields'] : array() ) as $field_name ) {
			if ( isset( $record['fields'][ $field_name ], $old['fields'][ $field_name ]['role'] ) ) {
				$record['fields'][ $field_name ]['role'] = $old['fields'][ $field_name ]['role'];
			}
		}
		return $record;
	}
}
