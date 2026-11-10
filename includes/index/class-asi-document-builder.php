<?php
/**
 * Construye la fila del índice de un post a partir del esquema descubierto.
 *
 * No conoce ningún campo por nombre: recorre las taxonomías y campos del esquema y usa
 * el rol de cada uno (code, brand, summary, attribute, flag, image, document, price…).
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || exit;

class ASI_Document_Builder {

	/** Subir cuando cambie la forma de construir documentos: fuerza el reindexado. */
	const DOC_VERSION = 7;

	const MAX_BODY  = 20000;
	const MAX_SPECS = 4;
	/** Datos de la ficha para responder preguntas ("¿cuánto pesa?"): más que las especificaciones de la tarjeta. */
	const MAX_FACTS = 40;
	/** Texto original (con acentos y signos) para citar la frase que responde. */
	const MAX_TEXT = 12000;

	/** @var ASI_Language_Registry */
	private $registry;

	public function __construct( ASI_Language_Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Firma que cambia cuando el post o la lógica de indexado cambian.
	 *
	 * @param WP_Post $post   Post.
	 * @param array   $schema Esquema.
	 * @return string
	 */
	public function signature( WP_Post $post, array $schema ) {
		return md5( self::DOC_VERSION . '|' . $post->post_modified_gmt . '|' . ( isset( $schema['discovered_at'] ) ? $schema['discovered_at'] : 0 ) . '|' . $this->registry->adapter()->get_post_language( $post->ID ) );
	}

	/**
	 * ¿Este post debe estar en el índice?
	 *
	 * @param WP_Post $post   Post.
	 * @param array   $schema Esquema.
	 * @return bool
	 */
	public function is_indexable( WP_Post $post, array $schema ) {
		if ( 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return false;
		}
		if ( empty( $schema['post_types'][ $post->post_type ]['enabled'] ) ) {
			return false;
		}
		// La página del chat tampoco es una respuesta.
		if ( ASI_Chat_Page::is_chat_page( $post ) ) {
			return false;
		}
		// Portada y página del blog agrupan contenido: no responden nada por sí mismas.
		if ( 'page' === $post->post_type && in_array( (int) $post->ID, array( (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ), true ) ) {
			return false;
		}
		// Páginas del sistema de WooCommerce (tienda, carrito, checkout, mi cuenta) tampoco.
		if ( 'page' === $post->post_type && function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'shop', 'cart', 'checkout', 'myaccount' ) as $page ) {
				if ( (int) wc_get_page_id( $page ) === (int) $post->ID ) {
					return false;
				}
			}
		}
		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post->ID );
			if ( ! $product || in_array( $product->get_catalog_visibility(), array( 'hidden', 'catalog' ), true ) ) {
				return false;
			}
			// Respeta el ajuste de WooCommerce "Ocultar productos agotados".
			if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) && ! $product->is_in_stock() ) {
				return false;
			}
		}
		return (bool) apply_filters( 'asi_is_indexable', true, $post );
	}

	/**
	 * Fila completa para ASI_Index_Table::upsert().
	 *
	 * @param WP_Post $post   Post.
	 * @param array   $schema Esquema.
	 * @return array
	 */
	public function build( WP_Post $post, array $schema ) {
		$type      = $schema['post_types'][ $post->post_type ];
		$languages = $this->registry->all();
		$lang      = $this->registry->adapter()->get_post_language( $post->ID );
		$country   = isset( $languages[ $lang ]['country'] ) ? $languages[ $lang ]['country'] : '';

		$doc = array(
			'terms'    => array(),
			'facets'   => array(), // [kind, label]: términos del sitio que el usuario puede nombrar.
			'term_ids' => array(),
			'codes'    => array(),
			'body'     => array(),
			'facts'    => array(), // [etiqueta, valor]: todos los datos de la ficha.
			'card'     => array(
				'title'    => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'url'      => get_permalink( $post ),
				'type'     => $type['singular'],
				'image'    => get_the_post_thumbnail_url( $post, 'medium_large' ) ?: '',
				'summary'  => '',
				'category'   => '',
				'categories' => array(),
				'brand'      => '',
				'code'     => '',
				'price'    => '',
				'price_old' => '',
				'currency_raw' => '',
				'specs'    => array(),
				'docs'     => array(),
				'flags'    => array(),
			),
		);

		$this->collect_taxonomies( $post, $type, $doc );
		$this->collect_fields( $post, $type, $doc );
		$this->collect_content( $post, $type, $doc );
		$this->collect_woocommerce( $post, $doc );

		if ( '' === $doc['card']['summary'] ) {
			$readable = array_filter(
				$doc['body'],
				static function ( $text ) {
					return ! preg_match( '/^\s*[\[{]/', (string) $text );
				}
			);
			$doc['card']['summary'] = $this->trim_words( implode( ' ', $readable ), 28 );
		}

		$doc = apply_filters( 'asi_document', $doc, $post, $type );

		// Datos de la ficha: los de los campos, más marca, categoría y código si los hay.
		foreach ( array( 'brand', 'category', 'code' ) as $key ) {
			if ( '' !== $doc['card'][ $key ] ) {
				$doc['facts'][] = array( $key, $doc['card'][ $key ] );
			}
		}
		foreach ( $doc['facts'] as $fact ) {
			if ( ! in_array( $fact[0], array( 'brand', 'category', 'code' ), true ) ) {
				$doc['facets'][] = array( 'fact', $fact[0] );
			}
		}

		// Atributos del giro (plazas, tracción, año…): los arma cada pack activo con la ficha completa.
		foreach ( ASI_Extensions::active_packs() as $pack ) {
			$attrs = $pack->attributes( $doc, $post->post_type );
			if ( $attrs ) {
				$doc['card']['attrs'] = array_merge( isset( $doc['card']['attrs'] ) ? $doc['card']['attrs'] : array(), $attrs );
				$doc['card']          = $pack->card( $doc['card'], $doc['card']['attrs'] );
			}
		}

		// Forma normal (singular, sin acentos): es la misma que usa el analizador de consultas.
		$body  = ASI_Lexicon::normal_form( implode( ' ', $doc['body'] ) );
		$terms = array();
		foreach ( array_unique( $doc['terms'] ) as $term ) {
			$normal = ASI_Lexicon::normal_form( $term );
			if ( '' !== $normal ) {
				$terms[ $normal ] = true;
			}
		}

		return array(
			'post_id'    => $post->ID,
			'post_type'  => $post->post_type,
			'role'       => $type['role'],
			'lang'       => $lang,
			'country'    => $country,
			'title'      => $doc['card']['title'],
			'title_norm' => substr( ASI_Lexicon::normal_form( $doc['card']['title'] ), 0, 500 ),
			'terms_norm' => implode( ' | ', array_keys( $terms ) ),
			'facets'     => $this->facet_lines( $doc['facets'] ),
			'codes'      => substr( implode( ' ', array_unique( array_filter( array_map( array( 'ASI_Normalizer', 'compact_code' ), $doc['codes'] ) ) ) ), 0, 500 ),
			'body_norm'  => substr( $body, 0, self::MAX_BODY ),
			'term_ids'   => implode( ',', array_unique( array_map( 'intval', $doc['term_ids'] ) ) ),
			'card'       => wp_json_encode( $doc['card'] ),
			'details'    => wp_json_encode(
				array(
					'facts' => $this->unique_facts( $doc['facts'] ),
					'text'  => $this->original_text( $doc['body'] ),
				)
			),
			'signature'  => $this->signature( $post, $schema ),
			'indexed_at' => current_time( 'mysql', true ),
		);
	}

	/**
	 * Términos de cada taxonomía, con sus ancestros ("Tractores" también aporta "Maquinaria agrícola").
	 *
	 * @param WP_Post $post Post.
	 * @param array   $type Registro del tipo en el esquema.
	 * @param array   $doc  Documento en construcción.
	 */
	private function collect_taxonomies( WP_Post $post, array $type, array &$doc ) {
		foreach ( $type['taxonomies'] as $taxonomy => $info ) {
			$terms = get_the_terms( $post, $taxonomy );
			if ( empty( $terms ) || is_wp_error( $terms ) ) {
				continue;
			}

			$deepest       = null;
			$deepest_depth = -1;

			foreach ( $terms as $term ) {
				$doc['terms'][]    = $term->name;
				$doc['facets'][]   = array( $info['role'], $term->name );
				$doc['term_ids'][] = $term->term_id;

				$ancestors = $info['hierarchical'] ? get_ancestors( $term->term_id, $taxonomy, 'taxonomy' ) : array();
				foreach ( $ancestors as $ancestor_id ) {
					$ancestor = get_term( $ancestor_id, $taxonomy );
					if ( $ancestor && ! is_wp_error( $ancestor ) ) {
						$doc['terms'][]    = $ancestor->name;
						$doc['facets'][]   = array( $info['role'], $ancestor->name );
						$doc['term_ids'][] = $ancestor->term_id;
					}
				}
				if ( count( $ancestors ) > $deepest_depth ) {
					$deepest       = $term;
					$deepest_depth = count( $ancestors );
				}
			}

			if ( 'brand' === $info['role'] && '' === $doc['card']['brand'] ) {
				$doc['card']['brand'] = $terms[0]->name;
			} elseif ( 'category' === $info['role'] && '' === $doc['card']['category'] && $deepest ) {
				$doc['card']['category']   = $deepest->name;
				$doc['card']['categories'] = array_values( array_unique( wp_list_pluck( $terms, 'name' ) ) );
			} elseif ( 'attribute' === $info['role'] ) {
				$value = implode( ', ', wp_list_pluck( $terms, 'name' ) );
				$this->add_fact( $doc, $info['label'], $value );
				if ( count( $doc['card']['specs'] ) < self::MAX_SPECS ) {
					$doc['card']['specs'][] = array( 'label' => $info['label'], 'value' => $value );
				}
			}
		}
	}

	/**
	 * Campos ACF y meta, según su rol.
	 *
	 * @param WP_Post $post Post.
	 * @param array   $type Registro del tipo.
	 * @param array   $doc  Documento.
	 */
	private function collect_fields( WP_Post $post, array $type, array &$doc ) {
		foreach ( $type['fields'] as $name => $field ) {
			if ( 'ignore' === $field['role'] || 'woocommerce' === $field['source'] ) {
				continue;
			}
			$value = $this->read_field( $post->ID, $name, $field );
			$this->apply_field_value( $field, $value, $doc );
		}
	}

	/**
	 * Lee un campo: con ACF (valor formateado) si es suyo; si no, meta cruda.
	 *
	 * @param int    $post_id Post.
	 * @param string $name    Nombre.
	 * @param array  $field   Definición.
	 * @return mixed
	 */
	private function read_field( $post_id, $name, array $field ) {
		if ( 'acf' === $field['source'] && function_exists( 'get_field' ) ) {
			return get_field( $name, $post_id, true );
		}
		$value   = get_post_meta( $post_id, $name, true );
		$decoded = ASI_Json_Paths::decode( $value );
		return null !== $decoded ? $decoded : $value;
	}

	/**
	 * Aplica un valor al documento según el rol del campo.
	 *
	 * @param array $field Definición (label, type, role, children).
	 * @param mixed $value Valor.
	 * @param array $doc   Documento.
	 */
	private function apply_field_value( array $field, $value, array &$doc ) {
		$is_empty = null === $value || '' === $value || array() === $value;
		if ( $is_empty || ( false === $value && 'flag' !== $field['role'] ) ) {
			return;
		}

		switch ( $field['role'] ) {
			case 'container':
				if ( 'json' === $field['type'] ) {
					// JSON guardado en meta: cada hoja se aplica con el rol de su ruta ("detalle.codigoPrincipal").
					foreach ( ASI_Json_Paths::flatten( $value ) as $pair ) {
						list( $path, $leaf ) = $pair;
						if ( isset( $field['children'][ $path ] ) ) {
							$this->apply_field_value( $field['children'][ $path ], $leaf, $doc );
						}
					}
					return;
				}
				$rows = isset( $value[0] ) ? $value : array( $value );
				foreach ( $rows as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					foreach ( $row as $sub_name => $sub_value ) {
						if ( isset( $field['children'][ $sub_name ] ) ) {
							$this->apply_field_value( $field['children'][ $sub_name ], $sub_value, $doc );
						}
					}
				}
				// Repeater tipo "especificación: valor" → también como especificación de la tarjeta.
				$this->maybe_add_spec_rows( $field, $rows, $doc );
				return;

			case 'code':
				$text             = $this->to_text( $value );
				$doc['codes'][]   = $text;
				$doc['terms'][]   = $text;
				$doc['card']['code'] = $doc['card']['code'] ? $doc['card']['code'] : $text;
				return;

			case 'brand':
				$text                 = $this->to_text( $value );
				$doc['terms'][]       = $text;
				$doc['facets'][]      = array( 'brand', $text );
				$doc['card']['brand'] = $doc['card']['brand'] ? $doc['card']['brand'] : $text;
				return;

			case 'price':
			case 'price_old':
				// Un precio en 0 o vacío no cuenta: no tapa al de otro campo.
				$text = $this->to_text( $value );
				if ( '' === $doc['card'][ $field['role'] ] && (float) preg_replace( '/[^0-9.]/', '', $text ) > 0 ) {
					$doc['card'][ $field['role'] ] = $text;
				}
				return;

			case 'currency':
				if ( '' === $doc['card']['currency_raw'] && is_scalar( $value ) ) {
					$doc['card']['currency_raw'] = trim( (string) $value );
				}
				return;

			case 'image':
				if ( '' === $doc['card']['image'] ) {
					$doc['card']['image'] = $this->image_url( $value );
				}
				return;

			case 'document':
				$url = $this->file_url( $value );
				if ( $url && count( $doc['card']['docs'] ) < 2 ) {
					$doc['card']['docs'][] = array( 'label' => $field['label'], 'url' => $url );
				}
				return;

			case 'flag':
				if ( $value && ! in_array( strtolower( trim( (string) $value ) ), array( 'false', '0', 'no', '' ), true ) ) {
					$doc['terms'][]         = $field['label'];
					$doc['facets'][]        = array( 'flag', $field['label'] );
					$doc['card']['flags'][] = $field['label'];
				}
				return;

			case 'related':
				foreach ( (array) $value as $related ) {
					$related_id = is_object( $related ) ? $related->ID : (int) $related;
					if ( $related_id ) {
						$doc['body'][] = get_the_title( $related_id );
					}
				}
				return;

			case 'summary':
				$text          = $this->to_text( $value );
				$doc['body'][] = $text;
				// Un JSON guardado en un campo de texto no es un resumen para mostrar.
				if ( '' === $doc['card']['summary'] && ! preg_match( '/^\s*[\[{]/', $text ) ) {
					$doc['card']['summary'] = $this->trim_words( $text, 28 );
				}
				return;

			case 'link':
				return;

			default: // attribute
				$text          = $this->to_text( $value );
				$doc['body'][] = $text;
				$this->add_fact( $doc, $field['label'], $text );
				if ( strlen( $text ) <= 60 && count( $doc['card']['specs'] ) < self::MAX_SPECS ) {
					$doc['card']['specs'][] = array( 'label' => $field['label'], 'value' => $text );
				}
		}
	}

	/**
	 * Un repeater con dos subcampos cortos (nombre + valor) se muestra como especificaciones.
	 *
	 * @param array $field Contenedor.
	 * @param array $rows  Filas.
	 * @param array $doc   Documento.
	 */
	private function maybe_add_spec_rows( array $field, array $rows, array &$doc ) {
		if ( 'repeater' !== $field['type'] || 2 !== count( $field['children'] ) ) {
			return;
		}
		$keys = array_keys( $field['children'] );
		foreach ( $rows as $row ) {
			$label = isset( $row[ $keys[0] ] ) ? $this->to_text( $row[ $keys[0] ] ) : '';
			$value = isset( $row[ $keys[1] ] ) ? $this->to_text( $row[ $keys[1] ] ) : '';
			if ( '' === $label || '' === $value || strlen( $label ) > 40 ) {
				continue;
			}
			$this->add_fact( $doc, $label, $value );
			if ( strlen( $value ) <= 60 && count( $doc['card']['specs'] ) < self::MAX_SPECS ) {
				$doc['card']['specs'][] = array( 'label' => $label, 'value' => $value );
			}
		}
	}

	/**
	 * Contenido del post: editor clásico/bloques, extracto y, si aplica, Elementor.
	 *
	 * @param WP_Post $post Post.
	 * @param array   $type Registro del tipo.
	 * @param array   $doc  Documento.
	 */
	private function collect_content( WP_Post $post, array $type, array &$doc ) {
		if ( '' !== $post->post_excerpt ) {
			$doc['body'][] = $post->post_excerpt;
			if ( '' === $doc['card']['summary'] ) {
				$doc['card']['summary'] = $this->trim_words( $post->post_excerpt, 28 );
			}
		}

		// Quita las etiquetas de shortcode pero conserva su texto: en WPBakery o Divi todo el
		// contenido va dentro de shortcodes y strip_shortcodes() lo borraría entero.
		$content = preg_replace( '/\[\/?[a-zA-Z0-9_-]+(?:\s[^\]]*)?\]/', ' ', $post->post_content );
		$content = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $content ) ) );

		if ( ! empty( $type['elementor'] ) ) {
			$elementor = ASI_Elementor_Text::extract( (string) get_post_meta( $post->ID, '_elementor_data', true ) );
			// Elementor guarda una copia en post_content; si existe su versión estructurada, esa manda.
			if ( '' !== $elementor ) {
				$content = $elementor;
			}
		}

		if ( '' !== $content ) {
			$doc['body'][] = $content;
		}
	}

	/**
	 * WooCommerce: SKU, precio, atributos locales y descripción corta.
	 *
	 * @param WP_Post $post Post.
	 * @param array   $doc  Documento.
	 */
	private function collect_woocommerce( WP_Post $post, array &$doc ) {
		if ( 'product' !== $post->post_type || ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return;
		}

		if ( $product->get_sku() ) {
			$doc['codes'][]      = $product->get_sku();
			$doc['terms'][]      = $product->get_sku();
			$doc['card']['code'] = $product->get_sku();
		}
		if ( '' !== $product->get_price() ) {
			$doc['card']['price'] = wp_strip_all_tags( wc_price( wc_get_price_to_display( $product ) ) );
		}
		if ( ! $product->is_in_stock() ) {
			$doc['card']['flags'][] = __( 'Agotado', 'asesor-inteligente' );
		}
		if ( $product->is_on_sale() ) {
			$doc['card']['flags'][] = __( 'Oferta', 'asesor-inteligente' );
			$doc['terms'][]         = __( 'Oferta', 'asesor-inteligente' );
			$doc['facets'][]        = array( 'flag', __( 'Oferta', 'asesor-inteligente' ) );
		}
		// Variaciones: su SKU y sus atributos ("rojo", "XL") también encuentran al producto.
		if ( $product->is_type( 'variable' ) ) {
			foreach ( array_slice( $product->get_children(), 0, 100 ) as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( ! $variation ) {
					continue;
				}
				if ( $variation->get_sku() ) {
					$doc['codes'][] = $variation->get_sku();
				}
				foreach ( $variation->get_variation_attributes() as $attribute_value ) {
					if ( '' !== $attribute_value ) {
						$doc['terms'][] = str_replace( '-', ' ', (string) $attribute_value );
					}
				}
			}
		}
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! is_object( $attribute ) ) {
				continue;
			}
			if ( $attribute->is_taxonomy() ) {
				$names = wc_get_product_terms( $post->ID, $attribute->get_name(), array( 'fields' => 'names' ) );
				$this->add_fact( $doc, wc_attribute_label( $attribute->get_name(), $product ), implode( ', ', (array) $names ) );
				continue;
			}
			$doc['body'][] = $attribute->get_name() . ' ' . implode( ' ', $attribute->get_options() );
			$this->add_fact( $doc, $attribute->get_name(), implode( ', ', $attribute->get_options() ) );
		}
		if ( $product->get_weight() ) {
			$this->add_fact( $doc, __( 'Peso', 'asesor-inteligente' ), wc_format_weight( $product->get_weight() ) );
		}
		if ( $product->has_dimensions() ) {
			$this->add_fact( $doc, __( 'Dimensiones', 'asesor-inteligente' ), wp_strip_all_tags( wc_format_dimensions( $product->get_dimensions( false ) ) ) );
		}
	}

	/**
	 * Suma un dato de la ficha (etiqueta y valor cortos y legibles).
	 *
	 * @param array  $doc   Documento.
	 * @param string $label Etiqueta.
	 * @param string $value Valor.
	 */
	private function add_fact( array &$doc, $label, $value ) {
		$label = trim( wp_strip_all_tags( (string) $label ) );
		$value = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $value ) ) );
		if ( '' === $label || '' === $value || strlen( $label ) > 60 || strlen( $value ) > 240 || count( $doc['facts'] ) >= self::MAX_FACTS ) {
			return;
		}
		$doc['facts'][] = array( $label, $value );
	}

	/**
	 * Datos sin repetir (la misma etiqueta queda una vez).
	 *
	 * @param array $facts Lista de [etiqueta, valor].
	 * @return array
	 */
	private function unique_facts( array $facts ) {
		$unique   = array();
		$by_value = array();
		foreach ( $facts as $fact ) {
			$key   = ASI_Lexicon::normal_form( $fact[0] );
			$value = ASI_Lexicon::normal_form( $fact[1] );
			if ( '' === $key || isset( $unique[ $key ] ) ) {
				continue;
			}
			// El mismo dato con dos nombres ("Color" y "Color del producto"): queda el nombre más corto.
			if ( isset( $by_value[ $value ] ) && ! in_array( $key, array( 'brand', 'category', 'code' ), true ) ) {
				$previous = $by_value[ $value ];
				if ( strlen( $fact[0] ) < strlen( $unique[ $previous ][0] ) ) {
					$unique[ $previous ][0] = $fact[0];
				}
				continue;
			}
			$unique[ $key ]     = array( $fact[0], $fact[1] );
			$by_value[ $value ] = $key;
		}
		return array_values( $unique );
	}

	/**
	 * Texto original del documento, en frases separadas, para citar la que responde.
	 *
	 * @param string[] $parts Partes del cuerpo.
	 * @return string
	 */
	private function original_text( array $parts ) {
		$text = array();
		foreach ( $parts as $part ) {
			$part = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $part ) ) );
			if ( '' !== $part ) {
				$text[] = $part;
			}
		}
		$text = implode( "\n", array_unique( $text ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_TEXT ) : substr( $text, 0, self::MAX_TEXT );
	}

	/**
	 * Términos del sitio como líneas "tipo:Nombre" (el vocabulario los lee sin cargar WordPress).
	 *
	 * @param array $facets Lista de [tipo, nombre].
	 * @return string
	 */
	private function facet_lines( array $facets ) {
		$lines = array();
		foreach ( $facets as $facet ) {
			$label = trim( str_replace( array( "\n", "\r", ':' ), ' ', (string) $facet[1] ) );
			if ( '' !== $label && strlen( $label ) <= 80 ) {
				$lines[ $facet[0] . ':' . $label ] = true;
			}
		}
		return implode( "\n", array_keys( $lines ) );
	}

	/**
	 * Convierte cualquier valor de campo en texto plano.
	 *
	 * @param mixed $value Valor.
	 * @return string
	 */
	private function to_text( $value ) {
		if ( is_scalar( $value ) ) {
			return trim( wp_strip_all_tags( (string) $value ) );
		}
		if ( $value instanceof WP_Post ) {
			return get_the_title( $value );
		}
		if ( $value instanceof WP_Term ) {
			return $value->name;
		}
		if ( is_array( $value ) ) {
			// Campos de selección de ACF con "value/label".
			if ( isset( $value['label'] ) && is_scalar( $value['label'] ) ) {
				return trim( (string) $value['label'] );
			}
			$parts = array();
			array_walk_recursive(
				$value,
				function ( $item ) use ( &$parts ) {
					if ( is_scalar( $item ) && ! is_bool( $item ) && ! preg_match( '#^https?://#', (string) $item ) ) {
						$parts[] = wp_strip_all_tags( (string) $item );
					}
				}
			);
			return trim( implode( ' ', $parts ) );
		}
		if ( is_object( $value ) && isset( $value->name ) ) {
			return (string) $value->name;
		}
		return '';
	}

	/**
	 * URL de imagen desde un valor ACF (ID, array o URL).
	 *
	 * @param mixed $value Valor.
	 * @return string
	 */
	private function image_url( $value ) {
		if ( is_array( $value ) && isset( $value[0] ) ) {
			$value = $value[0]; // Galería: la primera.
		}
		if ( is_numeric( $value ) ) {
			return (string) wp_get_attachment_image_url( (int) $value, 'medium_large' );
		}
		if ( is_array( $value ) ) {
			if ( isset( $value['sizes']['medium_large'] ) ) {
				return (string) $value['sizes']['medium_large'];
			}
			return isset( $value['url'] ) ? (string) $value['url'] : '';
		}
		return is_string( $value ) && preg_match( '#^https?://#', $value ) ? $value : '';
	}

	/**
	 * URL de archivo desde un valor ACF (ID, array o URL).
	 *
	 * @param mixed $value Valor.
	 * @return string
	 */
	private function file_url( $value ) {
		if ( is_numeric( $value ) ) {
			return (string) wp_get_attachment_url( (int) $value );
		}
		if ( is_array( $value ) && isset( $value['url'] ) ) {
			return (string) $value['url'];
		}
		return is_string( $value ) && preg_match( '#^https?://#', $value ) ? $value : '';
	}

	/**
	 * Recorta un texto a N palabras.
	 *
	 * @param string $text  Texto.
	 * @param int    $words Palabras.
	 * @return string
	 */
	private function trim_words( $text, $words ) {
		// Elementor separa sus bloques con " . ": en un resumen se lee como texto normal.
		$text = preg_replace( '/\s+\.(\s+\.)*\s+/u', '. ', wp_strip_all_tags( (string) $text ) );
		return wp_trim_words( $text, $words, '…' );
	}
}
