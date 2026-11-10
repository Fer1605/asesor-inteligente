<?php
// "Mira, busco un carro parecido al Frontier, ¿qué me recomendás?"
require_once dirname( __DIR__ ) . '/packs/autos/class-asi-pack-autos.php';
$sim = new ASI_Pack_Autos();

// Tabla de modelos.
check( 'modelo: frontier', 'Nissan Frontier', $sim->reference_model( 'parecido al frontier', 'es' )['name'] );
check( 'modelo: d-max escrito con espacio', 'Isuzu D-Max', $sim->reference_model( 'la d max', 'es' )['name'] );
check( 'modelo: "mazda 3" antes que un 3 suelto', 'Mazda 3', $sim->reference_model( 'el mazda 3', 'es' )['name'] );
check( 'modelo: desconocido', null, $sim->reference_model( 'el chunche', 'es' ) );

// Parecido.
$frontier = array( 'body' => 'pickup', 'seats' => 5, 'traction' => '4x4', 'fuel' => 'diesel', 'cc' => 2500, 'price_base' => 350000 );
$dmax     = array( 'body' => 'pickup', 'seats' => 5, 'traction' => '4x4', 'fuel' => 'diesel', 'cc' => 3000, 'price_base' => 349900 );
$picop    = array( 'body' => 'pickup', 'seats' => 2, 'traction' => '4x2', 'fuel' => 'gasoline', 'cc' => 1200, 'price_base' => 80000 );
$suv      = array( 'body' => 'suv', 'seats' => 5, 'traction' => '4x4', 'fuel' => 'diesel', 'cc' => 2500, 'price_base' => 350000 );
check( 'parecido: D-Max se parece mucho al Frontier', true, $sim->similarity( $frontier, $dmax ) > 0.85 );
check( 'parecido: una pickup chiquita de gasolina no', true, $sim->similarity( $frontier, $picop ) < 0.3 );
check( 'parecido: otra carrocería = 0', 0.0, $sim->similarity( $frontier, $suv ) );
check( 'parecido: sedán y automóvil son familia', true, $sim->similarity( array( 'body' => 'sedan' ), array( 'body' => 'car' ) ) > 0 );
check( 'describe', 'una pickup doble cabina, 4X4, diésel y motor 2.5 L', $sim->describe( $frontier, 'es' ) );
check( 'por qué se parecen', '4X4, diésel, de 5 pasajeros y de precio parecido', $sim->why_similar( $frontier, $dmax, 'es' ) );

// Conversación.
$lot2 = new ASI_Test_Repository();
$veh  = function ( $id, $title, $brand, $cat, $price, $attrs ) use ( $lot2 ) {
	$lot2->add( $id, 'es', 'catalog', $title, array( 'category' => array( $cat ), 'brand' => array( $brand ) ), $cat . '.', array(), array( 'price' => (string) $price, 'currency_raw' => '2', 'brand' => $brand, 'attrs' => $attrs ) );
};
$pk = array( 'body' => 'pickup', 'seats' => 5, 'traction' => '4x4', 'fuel' => 'diesel' );
$veh( 1, 'NISSAN FRONTIER DC', 'NISSAN', 'Pick up', 349900, $pk + array( 'cc' => 2500, 'year' => 2027 ) );
$veh( 2, 'NISSAN FRONTIER LE', 'NISSAN', 'Pick up', 389900, $pk + array( 'cc' => 2500, 'year' => 2027 ) );
$veh( 3, 'ISUZU D-MAX DC 3.0', 'ISUZU', 'Pick up', 349900, $pk + array( 'cc' => 3000, 'year' => 2027 ) );
$veh( 4, 'MAZDA BT-50 3.0L', 'MAZDA', 'Pick up', 344900, $pk + array( 'cc' => 3000, 'year' => 2027 ) );
$veh( 5, 'MAZDA BT-50 1.9L', 'MAZDA', 'Pick up', 239900, $pk + array( 'cc' => 1900, 'year' => 2027 ) );
$veh( 6, 'TOYOTA HILUX SRV', 'TOYOTA', 'Pick up', 384990, $pk + array( 'cc' => 2800, 'year' => 2027 ) );
$veh( 7, 'DFSK K01S', 'DFSK', 'Pick up', 79900, array( 'body' => 'pickup', 'seats' => 2, 'traction' => '4x2', 'fuel' => 'gasoline', 'cc' => 1200 ) );
$veh( 8, 'TOYOTA FORTUNER', 'TOYOTA', 'SUV', 499990, array( 'body' => 'suv', 'seats' => 7, 'traction' => '4x4', 'fuel' => 'diesel', 'cc' => 2800 ) );
$sbot = function () use ( $lot2, $sim ) {
	return new ASI_Dialogue( $lot2, array( 'lang' => 'es', 'language' => 'es', 'site_name' => 'Agencia', 'money' => array( 'base' => 'GTQ', 'map' => array( '2' => 'GTQ' ) ), 'packs' => array( $sim ) ), function () {
		return 0;
	} );
};
$r   = $sbot()->handle( 'Mira, busco un carro parecido al Frontier, ¿qué me recomendás?' );
$ids = asi_card_ids( $r );
check( 'similar: describe el Frontier', true, false !== strpos( $r['text'], 'El Nissan Frontier' ) && false !== strpos( $r['text'], 'diésel' ) );
check( 'similar: recomienda otras marcas parecidas', array( 3, 4 ), array_slice( $ids, 0, 2 ) );
check( 'similar: una sola por marca', 1, count( array_intersect( $ids, array( 4, 5 ) ) ) );
check( 'similar: no recomienda la pickup chiquita', false, in_array( 7, $ids, true ) );
check( 'similar: no mezcla SUV', false, in_array( 8, $ids, true ) );
check( 'similar: dice los precios', true, false !== strpos( $r['text'], 'desde Q 349,900' ) );
check( 'similar: ofrece el Frontier que sí tienen', 1, end( $ids ) );
check( 'similar: ofrece comparar', true, false !== strpos( $r['followup'], 'compar' ) );
$r2 = $sbot()->handle( 'compáralas', $r['state'] );
check( 'similar: "compáralas" compara las dos recomendadas', array( 3, 4 ), array_column( $r2['cards'], 'id' ) );

$r = $sbot()->handle( 'algo parecido a la hilux pero más barato' );
check( 'similar: más barato que la referencia', true, ! array_filter( $r['cards'], function ( $c ) { return 6 !== $c['id'] && ASI_Money::parse_display_price( $c['price'] ) >= 384990; } ) );

$r = $sbot()->handle( 'quiero algo parecido al chunche 3000' );
check( 'similar: no conoce el modelo → pregunta el tipo', 'smalltalk', $r['kind'] );
check( 'similar: "como el más barato" no es semejanza', false, false !== strpos( $sbot()->handle( 'quiero uno como el más barato' )['text'], 'se caracteriza' ) );
$veh( 9, 'NUEVA MAZDA CX5 TOURING', 'MAZDA', 'SUV', 259900, array( 'body' => 'suv', 'seats' => 5, 'traction' => 'awd', 'fuel' => 'gasoline', 'cc' => 2500 ) );
$veh( 10, 'KIA SPORTAGE', 'KIA', 'SUV', 269900, array( 'body' => 'suv', 'seats' => 5, 'traction' => '4x2', 'fuel' => 'gasoline', 'cc' => 2000 ) );
$veh( 11, 'BMW X3', 'BMW', 'SUV', 560000, array( 'body' => 'suv', 'seats' => 5, 'traction' => '4x2', 'fuel' => 'gasoline', 'cc' => 2000 ) );
$r = $sbot()->handle( 'busco algo como la CX-5' );
check( 'similar: encuentra "CX5" aunque escriba "CX-5"', 9, end( $r['cards'] )['id'] );
check( 'similar: con precio real, lo parecido es de precio parecido', 10, $r['cards'][0]['id'] );
