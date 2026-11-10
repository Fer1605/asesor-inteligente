<?php
/**
 * Contrato de un conector: lo específico de un sitio (cómo cotiza, cómo compara, cómo guarda un lead).
 *
 * El core nunca sabe de un sitio concreto. Un conector se activa solo si detect() encuentra en el
 * sitio las piezas que necesita; si ninguno aplica, el chat sigue recomendando y manda al
 * formulario normal del sitio.
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || defined( 'ASI_CLI_TESTS' ) || exit;

interface ASI_Connector {

	/** Identificador corto y estable (se guarda en los ajustes). */
	public function id();

	/** Nombre para el panel. */
	public function label();

	/** ¿Este sitio tiene lo que el conector necesita? Debe ser barato: se llama en cada carga. */
	public function detect();

	/**
	 * Acciones que ofrece, con los datos que hay que pedirle al cliente para cada una.
	 *
	 * @return array<string,array{label:string,fields:string[],max_items?:int}>  p. ej. 'quote' => [...]
	 */
	public function actions();

	/**
	 * Ejecuta una acción.
	 *
	 * @param string $action   Clave de actions().
	 * @param int[]  $item_ids Productos de la conversación.
	 * @param array  $customer Datos que dio el cliente (nombre, correo, teléfono…).
	 * @return array|WP_Error  { filename, binary, mime } o error.
	 */
	public function run( $action, array $item_ids, array $customer );

	/**
	 * Envía el archivo al cliente con el correo del propio sitio (su plantilla, su remitente).
	 *
	 * @param string $action   Acción.
	 * @param int[]  $item_ids Productos.
	 * @param array  $customer name, email, phone.
	 * @param array  $file     filename, binary, mime.
	 * @return true|WP_Error|null null = el conector no envía correos; lo hace el core.
	 */
	public function email_customer( $action, array $item_ids, array $customer, array $file );

	/**
	 * Registra el lead donde el sitio guarda los de su formulario (p. ej. Envíos de Elementor).
	 *
	 * @param string $action   Acción.
	 * @param int[]  $item_ids Productos.
	 * @param array  $customer name, email, phone.
	 * @param bool   $test     Modo prueba (se marca como prueba).
	 * @return array{saved:bool,notify:string[]} notify: a quién avisa el formulario del sitio.
	 */
	public function save_lead( $action, array $item_ids, array $customer, $test );

	/**
	 * Reglas de financiamiento del sitio para un producto, si las tiene.
	 *
	 * @param int $item_id Producto.
	 * @return array|null { rate (anual, 0.09), down_min, down_max, down_default (fracciones), terms (meses) }
	 */
	public function financing( $item_id );
}
