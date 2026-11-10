<?php
/**
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || defined( 'ASI_CLI_TESTS' ) || exit;

final class ASI_Connector_Hello_Child_Pdf implements ASI_Connector {

	const MAX_COMPARE = 4;

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
				'label'  => 'Cotización',
				'fields' => array( 'name', 'email', 'phone' ),
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

	/**
	 * Genera el PDF. El envío por correo y el registro del lead se suman en la fase 7.
	 */
	public function run( $action, array $item_ids, array $customer ) {
		$item_ids = array_values( array_filter( array_map( 'absint', $item_ids ) ) );
		if ( ! $item_ids ) {
			return new WP_Error( 'asi_no_items', 'No hay vehículos para el PDF.' );
		}

		if ( 'quote' === $action ) {
			$binary   = hello_elementor_child_generate_vehicle_pdf( $item_ids[0] );
			$filename = function_exists( 'hello_elementor_child_pdf_get_filename' )
				? hello_elementor_child_pdf_get_filename( get_post( $item_ids[0] ) )
				: 'cotizacion-' . $item_ids[0] . '.pdf';
		} elseif ( 'compare' === $action && isset( $this->actions()['compare'] ) ) {
			$binary   = hello_elementor_child_generate_vehicle_collection_pdf( 'compare', array_slice( $item_ids, 0, self::MAX_COMPARE ) );
			$filename = 'comparacion.pdf';
		} else {
			return new WP_Error( 'asi_unknown_action', 'Acción no disponible.' );
		}

		if ( is_wp_error( $binary ) ) {
			return $binary;
		}
		return array(
			'filename' => $filename,
			'binary'   => $binary,
			'mime'     => 'application/pdf',
		);
	}
}
