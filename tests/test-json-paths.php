<?php
// Forma del json_vehiculo de una agencia de autos.
$json = '{"detalle":{"codigoPrincipal":"GM-1234","marca":"Toyota","linea":"Hilux","oferta":true,"blindado":false},"caracteristicas":[{"detalle":[{"nombre":"Motor","valor":"2.8"},{"nombre":"Transmisión","valor":"Automática"}]}]}';
$decoded = ASI_Json_Paths::decode( $json );
check( 'json: aplanado', array( array( 'detalle.codigoPrincipal', 'GM-1234' ), array( 'detalle.marca', 'Toyota' ), array( 'detalle.linea', 'Hilux' ), array( 'detalle.oferta', true ), array( 'detalle.blindado', false ), array( 'caracteristicas.detalle.nombre', 'Motor' ), array( 'caracteristicas.detalle.valor', '2.8' ), array( 'caracteristicas.detalle.nombre', 'Transmisión' ), array( 'caracteristicas.detalle.valor', 'Automática' ) ), ASI_Json_Paths::flatten( $decoded ) );
$paths = ASI_Json_Paths::describe( array( $decoded ) );
$c     = 'ASI_Field_Classifier';
$role  = function ( $path ) use ( $paths, $c ) {
	return $c::classify_field( substr( strrchr( '.' . $path, '.' ), 1 ), ASI_Json_Paths::label( $path ), '', $paths[ $path ] );
};
check( 'json: código', 'code', $role( 'detalle.codigoPrincipal' ) );
check( 'json: marca', 'brand', $role( 'detalle.marca' ) );
check( 'json: distintivo', 'flag', $role( 'detalle.oferta' ) );
check( 'json: dato', 'attribute', $role( 'caracteristicas.detalle.valor' ) );
check( 'json: etiqueta', 'Codigo principal', ASI_Json_Paths::label( 'detalle.codigoPrincipal' ) );
check( 'json: serializado', array( 'a' => 1 ), ASI_Json_Paths::decode( serialize( array( 'a' => 1 ) ) ) );
check( 'json: no es json', null, ASI_Json_Paths::decode( 'hola' ) );
