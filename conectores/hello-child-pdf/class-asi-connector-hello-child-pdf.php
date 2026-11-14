<?php
/**
 * Conector para sitios cuyo tema hijo de Hello Elementor genera cotizaciones y comparaciones en
 * PDF (módulo inc/pdf del tema): usa sus mismas funciones para el PDF, el correo y la calculadora,
 * y guarda el lead en los Envíos de Elementor del mismo formulario que usa la ficha.
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || defined( 'ASI_CLI_TESTS' ) || exit;

final class ASI_Connector_Hello_Child_Pdf implements ASI_Connector {

	const MAX_COMPARE = 4;

	/** Formularios del tema: clase CSS del formulario de Elementor por acción. */
	const FORMS = array(
		'quote'   => 'gm-pdf-single-form',
		'compare' => 'gm-pdf-compare-form',
	);

	/** Formularios del tema que cuentan en la reportería: clase CSS → acción. */
	const REPORT_FORMS = array(
		'gm-pdf-single-form'    => 'quote',
		'gm-pdf-favorites-form' => 'quote',
		'gm-pdf-compare-form'   => 'compare',
	);

	public function id() {
		return 'hello-child-pdf';
	}

	public function label() {
		return 'Cotización y comparación en PDF del tema';
	}

	public function detect() {
		return function_exists( 'hello_elementor_child_generate_vehicle_pdf' );
	}

	public function actions() {
		$actions = array(
			'quote' => array(
				'label'     => 'Cotización',
				'fields'    => array( 'name', 'email', 'phone' ),
				// Varios a la vez: el PDF de cotización de varios vehículos del tema (el de favoritos).
				'max_items' => function_exists( 'hello_elementor_child_generate_vehicle_collection_pdf' ) ? 8 : 1,
			),
		);
		if ( function_exists( 'hello_elementor_child_generate_vehicle_collection_pdf' ) ) {
			$actions['compare'] = array(
				'label'     => 'Comparación',
				'fields'    => array( 'name', 'email', 'phone' ),
				'max_items' => self::MAX_COMPARE,
			);
		}
		return $actions;
	}

	public function run( $action, array $item_ids, array $customer ) {
		$item_ids = array_values( array_filter( array_map( 'absint', $item_ids ) ) );
		if ( ! $item_ids ) {
			return new WP_Error( 'asi_no_items', 'No hay vehículos para el PDF.' );
		}

		if ( 'quote' === $action && count( $item_ids ) > 1 && function_exists( 'hello_elementor_child_generate_vehicle_collection_pdf' ) ) {
			$ids      = array_slice( $item_ids, 0, 8 );
			$binary   = hello_elementor_child_generate_vehicle_collection_pdf( 'favorites', $ids );
			$filename = function_exists( 'hello_elementor_child_pdf_get_collection_filename' )
				? hello_elementor_child_pdf_get_collection_filename( 'favorites' )
				: 'cotizacion-vehiculos.pdf';
		} elseif ( 'quote' === $action ) {
			$binary   = hello_elementor_child_generate_vehicle_pdf( $item_ids[0] );
			$filename = function_exists( 'hello_elementor_child_pdf_get_filename' )
				? hello_elementor_child_pdf_get_filename( get_post( $item_ids[0] ) )
				: 'cotizacion-' . $item_ids[0] . '.pdf';
		} elseif ( 'compare' === $action && isset( $this->actions()['compare'] ) ) {
			$ids      = array_slice( $item_ids, 0, self::MAX_COMPARE );
			$binary   = hello_elementor_child_generate_vehicle_collection_pdf( 'compare', $ids );
			$filename = function_exists( 'hello_elementor_child_pdf_get_collection_filename' )
				? hello_elementor_child_pdf_get_collection_filename( 'compare' )
				: 'comparacion-vehiculos.pdf';
		} else {
			return new WP_Error( 'asi_unknown_action', 'Acción no disponible.' );
		}

		if ( is_wp_error( $binary ) ) {
			return $binary;
		}
		if ( ! is_string( $binary ) || '' === $binary ) {
			return new WP_Error( 'asi_empty_pdf', 'El PDF salió vacío.' );
		}
		return array(
			'filename' => sanitize_file_name( (string) $filename ),
			'binary'   => $binary,
			'mime'     => 'application/pdf',
		);
	}

	public function email_customer( $action, array $item_ids, array $customer, array $file ) {
		if ( ! function_exists( 'hello_elementor_child_pdf_send_email_attachment' ) ) {
			return null;
		}
		$ids  = array_values( array_map( 'absint', $item_ids ) );
		if ( 'compare' === $action ) {
			$args = array( 'context' => 'compare', 'vehicle_ids' => $ids );
		} elseif ( count( $ids ) > 1 ) {
			$args = array( 'context' => 'favorites', 'vehicle_ids' => $ids );
		} else {
			$args = array( 'context' => 'single', 'vehicle' => get_post( $ids[0] ), 'vehicle_id' => $ids[0] );
		}
		return hello_elementor_child_pdf_send_email_attachment( $customer['email'], $file['filename'], $file['binary'], $args );
	}

	public function save_lead( $action, array $item_ids, array $customer, $test ) {
		$form = $this->find_form( isset( self::FORMS[ $action ] ) ? self::FORMS[ $action ] : '' );
		$out  = array( 'saved' => false, 'notify' => $form ? $form['notify'] : array() );
		if ( ! class_exists( '\ElementorPro\Modules\Forms\Submissions\Database\Query' ) ) {
			return $out;
		}
		$ids    = array_values( array_map( 'absint', $item_ids ) );
		$titles = array_map(
			static function ( $id ) {
				return wp_strip_all_tags( get_the_title( $id ) );
			},
			$ids
		);
		$fields = array(
			array( 'id' => 'nombre', 'type' => 'text', 'value' => $customer['name'] ),
			array( 'id' => 'correo', 'type' => 'email', 'value' => $customer['email'] ),
			array( 'id' => 'telefono', 'type' => 'number', 'value' => isset( $customer['phone'] ) ? $customer['phone'] : '' ),
			array( 'id' => 'tipo_consulta', 'type' => 'hidden', 'value' => ( 'compare' === $action ? 'Comparación' : 'Cotización' ) . ' (chat)' ),
			array( 'id' => 'vehiculos_consultados', 'type' => 'hidden', 'value' => implode( ', ', $titles ) ),
			array( 'id' => 'vehiculos_ids', 'type' => 'hidden', 'value' => implode( ',', $ids ) ),
		);
		try {
			$query = \ElementorPro\Modules\Forms\Submissions\Database\Query::get_instance();
			$id    = $query->add_submission(
				array(
					'main_meta_id'  => 0,
					'post_id'       => $form ? $form['post_id'] : 0,
					'element_id'    => $form ? $form['element_id'] : '',
					'form_name'     => ( $form ? $form['name'] : 'Asesor' ) . ' · chat' . ( $test ? ' (prueba)' : '' ),
					'referer'       => get_permalink( $ids[0] ),
					'referer_title' => $titles[0],
					'campaign_id'   => 0,
					'user_id'       => get_current_user_id(),
					'user_ip'       => '',
					'user_agent'    => 'Asesor Inteligente (chat)',
					'is_read'       => 0,
					'actions_count' => 1,
					'actions_succeeded_count' => 1,
					'meta'          => wp_json_encode( array( 'source' => 'asesor-inteligente' ) ),
				),
				$fields
			);
			$out['saved'] = (bool) $id;
		} catch ( \Throwable $e ) {
			$out['saved'] = false;
		}
		return $out;
	}

	/**
	 * Reportería: las cotizaciones (ficha y favoritos) y comparaciones guardadas en los Envíos de
	 * los formularios del tema, hechas en el sitio o en el chat. El chat las marca con su navegador
	 * ("Asesor Inteligente (chat)") y las de prueba con "(prueba)" en el nombre del formulario.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d (incluido).
	 * @return array[] { action, source, test, items: { id, title }[] }
	 */
	public function report_leads( $from, $to ) {
		global $wpdb;
		$forms = array();
		foreach ( self::REPORT_FORMS as $css_class => $action ) {
			$form = $this->find_form( $css_class );
			if ( $form && '' !== (string) $form['element_id'] ) {
				$forms[ (string) $form['element_id'] ] = $action;
			}
		}
		if ( ! $forms ) {
			return array();
		}
		$subs   = $wpdb->prefix . 'e_submissions';
		$values = $wpdb->prefix . 'e_submissions_values';
		$in     = implode( ',', array_fill( 0, count( $forms ), '%s' ) );
		$rows   = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT s.id, s.element_id, s.form_name, s.user_agent, s.created_at, v.`key` AS k, v.value FROM {$subs} s LEFT JOIN {$values} v ON v.submission_id = s.id AND v.`key` IN ('vehiculos_ids', 'vehiculos_consultados') WHERE s.status <> 'trash' AND s.element_id IN ({$in}) AND s.created_at >= %s AND s.created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array_keys( $forms ), array( $from . ' 00:00:00', gmdate( 'Y-m-d', strtotime( $to . ' +1 day' ) ) . ' 00:00:00' ) )
			),
			ARRAY_A
		);
		$leads = array();
		foreach ( $rows as $row ) {
			$id = (int) $row['id'];
			if ( ! isset( $leads[ $id ] ) ) {
				$leads[ $id ] = array(
					'action' => $forms[ (string) $row['element_id'] ],
					'source' => false !== strpos( (string) $row['user_agent'], 'Asesor Inteligente' ) ? 'chat' : 'site',
					'test'   => false !== strpos( (string) $row['form_name'], '(prueba)' ),
					'day'    => substr( (string) $row['created_at'], 0, 10 ),
					'ids'    => '',
					'names'  => '',
				);
			}
			if ( 'vehiculos_ids' === $row['k'] ) {
				$leads[ $id ]['ids'] = (string) $row['value'];
			} elseif ( 'vehiculos_consultados' === $row['k'] ) {
				$leads[ $id ]['names'] = (string) $row['value'];
			}
		}
		$out = array();
		foreach ( $leads as $lead ) {
			// El chat guarda los IDs; los formularios del tema guardan los nombres en el mismo campo.
			$raw   = '' !== trim( $lead['ids'] ) ? $lead['ids'] : $lead['names'];
			$items = array();
			if ( preg_match( '/^[\d,\s]+$/', $raw ) ) {
				foreach ( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) as $post_id ) {
					$items[] = array( 'id' => $post_id, 'title' => '' );
				}
			} else {
				foreach ( array_filter( array_map( 'trim', explode( ',', $raw ) ), 'strlen' ) as $title ) {
					$items[] = array( 'id' => 0, 'title' => wp_strip_all_tags( $title ) );
				}
			}
			$out[] = array( 'action' => $lead['action'], 'source' => $lead['source'], 'test' => $lead['test'], 'day' => $lead['day'], 'items' => $items );
		}
		return $out;
	}

	public function financing( $item_id ) {
		if ( ! function_exists( 'hello_elementor_child_vehicle_calculator_get_annual_rate' ) ) {
			return null;
		}
		$price = function_exists( 'hello_elementor_child_vehicle_calculator_get_price' ) ? (float) hello_elementor_child_vehicle_calculator_get_price( $item_id ) : 0.0;
		$pct   = static function ( $fn, $fallback ) use ( $price ) {
			return ( $price > 0 && function_exists( $fn ) ) ? round( (float) call_user_func( $fn, $price ) / $price, 4 ) : $fallback;
		};
		return array(
			'rate'         => (float) hello_elementor_child_vehicle_calculator_get_annual_rate( $item_id ),
			'down_min'     => $pct( 'hello_elementor_child_vehicle_calculator_get_min_down_payment', 0.2 ),
			'down_max'     => $pct( 'hello_elementor_child_vehicle_calculator_get_max_down_payment', 0.7 ),
			'down_default' => $pct( 'hello_elementor_child_vehicle_calculator_get_initial_down_payment', 0.2 ),
			'terms'        => array( 12, 24, 36, 48, 60, 72 ),
		);
	}

	/**
	 * El formulario de Elementor del tema para esa acción (por su clase CSS): dónde guarda y a
	 * quién avisa. Se busca una vez y se guarda un día.
	 *
	 * @param string $css_class Clase.
	 * @return array|null { post_id, element_id, name, notify }
	 */
	private function find_form( $css_class ) {
		if ( '' === $css_class ) {
			return null;
		}
		$key    = 'asi_form_' . md5( $css_class );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached ? $cached : null;
		}
		global $wpdb;
		$found = array();
		$posts = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value LIKE %s LIMIT 10", '%' . $wpdb->esc_like( $css_class ) . '%' ) );
		foreach ( (array) $posts as $post ) {
			$walk = static function ( $elements ) use ( &$walk, $css_class, $post, &$found ) {
				foreach ( (array) $elements as $element ) {
					if ( $found ) {
						return;
					}
					$settings = isset( $element['settings'] ) ? (array) $element['settings'] : array();
					if ( isset( $element['widgetType'] ) && 'form' === $element['widgetType'] && false !== strpos( ' ' . ( isset( $settings['_css_classes'] ) ? $settings['_css_classes'] : '' ) . ' ', ' ' . $css_class . ' ' ) ) {
						$notify = array();
						foreach ( array( 'email_to', 'email_to_2' ) as $field ) {
							foreach ( preg_split( '/[\s,;]+/', isset( $settings[ $field ] ) ? (string) $settings[ $field ] : '' ) as $address ) {
								if ( is_email( $address ) ) {
									$notify[] = $address;
								}
							}
						}
						$found = array(
							'post_id'    => (int) $post->post_id,
							'element_id' => (string) $element['id'],
							'name'       => isset( $settings['form_name'] ) ? (string) $settings['form_name'] : 'Formulario',
							'notify'     => array_values( array_unique( $notify ) ),
						);
						return;
					}
					if ( ! empty( $element['elements'] ) ) {
						$walk( $element['elements'] );
					}
				}
			};
			$walk( json_decode( (string) $post->meta_value, true ) );
			if ( $found ) {
				break;
			}
		}
		set_transient( $key, $found, DAY_IN_SECONDS );
		return $found ? $found : null;
	}
}
