<?php
/**
 * Lo que el diálogo necesita para cerrar la venta (cotizar, comparar en PDF, cuotas). El diálogo
 * es puro; la implementación de WordPress es ASI_Actions (conectores, archivos, correo, leads).
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || defined( 'ASI_CLI_TESTS' ) || exit;

interface ASI_Action_Runner {

	/**
	 * Acciones disponibles en este sitio.
	 *
	 * @return array<string,array{label:string,fields:string[],max_items?:int}>
	 */
	public function available();

	/**
	 * Ejecuta la acción: genera el archivo, lo deja disponible con un enlace temporal, lo envía al
	 * cliente y registra el lead.
	 *
	 * @param string $action   quote, compare.
	 * @param int[]  $item_ids Productos.
	 * @param array  $customer name, email, phone.
	 * @return array|WP_Error { url, filename, emailed (bool), test (bool) }
	 */
	public function run( $action, array $item_ids, array $customer );

	/**
	 * Reglas de financiamiento para un producto (las del sitio o las del panel).
	 *
	 * @param int $item_id Producto.
	 * @return array|null { rate, down_min, down_max, down_default, terms }
	 */
	public function financing( $item_id );
}
