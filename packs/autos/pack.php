<?php
/**
 * Pack "autos": criterio de asesor para agencias de vehículos.
 *
 * Por ahora solo declara cuándo aplica. Las tablas se llenan en las fases siguientes:
 * - Fase 3: atributos del vehículo (carrocería, plazas, tracción, carga, motor) y reglas para
 *   rellenar huecos.
 * - Fase 4: perfil del cliente → carrocerías recomendadas.
 * - Fase 6: modelos de referencia para "parecido a…".
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || defined( 'ASI_CLI_TESTS' ) || exit;

return array(
	'label'  => 'Vehículos',
	/*
	 * Aplica si el sitio tiene un tipo de contenido de vehículos. Se decide por el nombre del tipo,
	 * nunca por el sitio.
	 */
	'detect' => static function () {
		if ( ! function_exists( 'post_type_exists' ) ) {
			return false;
		}
		foreach ( array( 'vehiculo', 'vehiculos', 'vehicle', 'vehicles', 'auto', 'autos', 'car', 'cars' ) as $type ) {
			if ( post_type_exists( $type ) ) {
				return true;
			}
		}
		return false;
	},
);
