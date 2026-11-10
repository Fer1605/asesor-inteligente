<?php
/**
 * Conector para sitios cuyo tema hijo de Hello Elementor genera cotizaciones y comparaciones en
 * PDF con las funciones hello_elementor_child_generate_vehicle_pdf() y
 * hello_elementor_child_generate_vehicle_collection_pdf().
 *
 * No hace PDFs propios: llama a los del sitio, así el documento sale idéntico al de la ficha.
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || defined( 'ASI_CLI_TESTS' ) || exit;

require_once __DIR__ . '/class-asi-connector-hello-child-pdf.php';

return new ASI_Connector_Hello_Child_Pdf();
