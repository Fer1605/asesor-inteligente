<?php
require_once dirname( __DIR__ ) . '/packs/autos/class-asi-pack-autos.php';
$autos = new ASI_Pack_Autos();

// --- Ficha → atributos (forma real de una agencia: características como términos del sitio).
$hilux = array(
	'card'   => array( 'title' => 'TOYOTA HILUX 2.8 SRV', 'code' => '2026' ),
	'facets' => array( array( 'category', 'Diesel' ), array( 'category', 'Ventanas Eléctricas' ), array( 'category', 'Nuevo' ), array( 'brand', 'TOYOTA' ), array( 'category', 'PICK UP' ), array( 'category', '4x4' ), array( 'category', 'Mecánica' ), array( 'flag', 'Nuevo ingreso' ) ),
	'facts'  => array( array( 'Transmisión', 'mecanica' ), array( 'Combustible', 'DOHC,Intercooler,Turbo Diesel' ), array( 'Kilometraje', '0' ), array( 'C.C.', '2800' ), array( 'Puertas', '4' ), array( 'Tracción', '4x4' ), array( 'Capacidad de Carga', '1.0 Ton' ), array( 'Pasajeros', '5' ), array( 'Modelo', '2026' ), array( 'Alto', '1,815 mm' ) ),
	'terms'  => array(),
);
$a = $autos->attributes( $hilux, 'vehiculo' );
check( 'autos: carrocería por término exacto', 'pickup', $a['body'] );
check( 'autos: plazas', 5, $a['seats'] );
check( 'autos: tracción', '4x4', $a['traction'] );
check( 'autos: transmisión', 'manual', $a['transmission'] );
check( 'autos: combustible de un campo con datos de motor', 'diesel', $a['fuel'] );
check( 'autos: "Ventanas Eléctricas" no lo vuelve eléctrico', 'diesel', $a['fuel'] );
check( 'autos: año', 2026, $a['year'] );
check( 'autos: cilindrada', 2800, $a['cc'] );
check( 'autos: carga', 1.0, $a['cargo_t'] );
check( 'autos: nuevo por término exacto (no por "Nuevo ingreso")', 'new', $a['condition'] );
check( 'autos: no aplica a otros tipos de contenido', array(), $autos->attributes( $hilux, 'accesorio' ) );

$card = $autos->card( array( 'title' => 'TOYOTA HILUX', 'code' => '2026', 'category' => 'Diesel', 'specs' => array() ), $a );
check( 'tarjeta: la categoría es la carrocería', 'PICK UP', $card['category'] );
check( 'tarjeta: el año no se muestra como código', '', $card['code'] );
check( 'tarjeta: especificaciones clave', array( 'Año', 'Pasajeros', 'Tracción', 'Transmisión' ), array_column( $card['specs'], 'label' ) );

$used = $autos->attributes( array( 'card' => array( 'title' => 'MAZDA CX5 2019' ), 'facets' => array( array( 'category', 'SUV' ), array( 'category', 'Usado' ) ), 'facts' => array( array( 'Kilometraje', '45,000' ), array( 'Transmisión', 'Automática' ) ) ), 'vehiculo' );
check( 'autos: usado, km, año del título', array( 'used', 45000, 2019 ), array( $used['condition'], $used['km'], $used['year'] ) );
check( 'autos: plazas estimadas por carrocería', array( 5, true ), array( $used['seats'], $used['seats_est'] ) );
$single = $autos->attributes( array( 'card' => array( 'title' => 'NISSAN FRONTIER CABINA SENCILLA' ), 'facets' => array( array( 'category', 'Pick-up' ) ), 'facts' => array() ), 'vehiculo' );
check( 'autos: pickup cabina sencilla → 3 plazas estimadas', 3, $single['seats'] );
$hybrid = $autos->attributes( array( 'card' => array( 'title' => 'BYD SONG PLUS DM-I' ), 'facets' => array( array( 'category', 'SUV' ), array( 'flag', 'Híbrido' ) ), 'facts' => array() ), 'vehiculo' );
check( 'autos: distintivo Híbrido', 'hybrid', $hybrid['fuel'] );

check( 'rol: "modelo" con años es atributo', 'attribute', $autos->field_role( 'modelo', 'Modelo', array( '2024', '2026' ), 'code' ) );
check( 'rol: "modelo" con nombres sigue siendo código', 'code', $autos->field_role( 'modelo', 'Modelo', array( 'XR-150', 'GLX' ), 'code' ) );

// --- Frase → restricciones.
$q = function ( $text ) use ( $autos ) {
	return $autos->parse_query( $text, 'es' )['constraints'];
};
check( 'frase: 7 pasajeros', array( 'min' => 7 ), $q( 'busco algo de 7 pasajeros' )['seats'] );
check( 'frase: 4x4', array( 'in' => array( '4x4' ) ), $q( 'una palangana 4x4' )['traction'] );
check( 'frase: doble tracción', array( 'in' => array( '4x4' ) ), $q( 'con doble tracción' )['traction'] );
check( 'frase: automática', array( 'in' => array( 'auto' ) ), $q( 'suv automática' )['transmission'] );
check( 'frase: diésel', array( 'in' => array( 'diesel' ) ), $q( 'pickup diésel' )['fuel'] );
check( 'frase: rango de años', array( 'min' => 2020, 'max' => 2022 ), $q( 'un modelo de 2020 a 2022' )['year'] );
check( 'frase: del 2021 en adelante', array( 'min' => 2021 ), $q( 'del 2021 en adelante' )['year'] );
check( 'frase: km', array( 'max' => 50000 ), $q( 'con menos de 50 mil km' )['km'] );
check( 'frase: usado', array( 'in' => array( 'used' ) ), $q( 'un carro usado' )['condition'] );
check( 'frase: "nuevo ingreso" no es nuevo', false, isset( $q( 'lo de nuevo ingreso' )['condition'] ) );
check( 'frase: sedán acepta automóviles', array( 'in' => array( 'sedan', 'car' ) ), $q( 'quiero un sedán' )['body'] );
check( 'frase: "puertas eléctricas" no pide puertas', false, isset( $q( 'con ventanas eléctricas' )['doors'] ) );
check( 'frase: precio no es año', false, isset( $q( 'hasta 300 mil' )['year'] ) );

// --- Restricciones genéricas.
check( 'atributos: cumple', true, ASI_Attributes::accepts( array( 'seats' => 7, 'traction' => '4x4' ), array( 'seats' => array( 'min' => 7 ), 'traction' => array( 'in' => array( '4x4' ) ) ) ) );
check( 'atributos: no cumple', false, ASI_Attributes::accepts( array( 'seats' => 5 ), array( 'seats' => array( 'min' => 7 ) ) ) );
check( 'atributos: no se sabe', null, ASI_Attributes::accepts( array( 'seats' => 7 ), array( 'traction' => array( 'in' => array( '4x4' ) ) ) ) );
check( 'atributos: sin atributos (accesorio) no se sabe', null, ASI_Attributes::accepts( array(), array( 'seats' => array( 'min' => 7 ) ) ) );

// --- Conversación con una agencia.
$lot = new ASI_Test_Repository();
$v   = function ( $id, $title, $cat, $price, $attrs ) use ( $lot ) {
	$lot->add( $id, 'es', 'catalog', $title, array( 'category' => array( $cat ) ), $cat . '.', array(), array( 'price' => (string) $price, 'currency_raw' => '2', 'attrs' => $attrs ) );
};
$v( 1, 'Toyota Hilux 4x4', 'Pick up', 384990, array( 'body' => 'pickup', 'seats' => 5, 'traction' => '4x4', 'fuel' => 'diesel', 'transmission' => 'manual', 'year' => 2026 ) );
$v( 2, 'Mazda BT-50 4x2', 'Pick up', 249900, array( 'body' => 'pickup', 'seats' => 5, 'traction' => '4x2', 'fuel' => 'diesel', 'transmission' => 'manual', 'year' => 2025 ) );
$v( 3, 'Toyota Fortuner', 'SUV', 499990, array( 'body' => 'suv', 'seats' => 7, 'traction' => '4x4', 'fuel' => 'diesel', 'transmission' => 'auto', 'year' => 2026 ) );
$v( 4, 'Mitsubishi Outlander', 'SUV', 169900, array( 'body' => 'suv', 'seats' => 7, 'traction' => '4x2', 'fuel' => 'gasoline', 'transmission' => 'auto', 'year' => 2023, 'km' => 30000, 'condition' => 'used' ) );
$v( 5, 'Hyundai Tucson', 'SUV', 229900, array( 'body' => 'suv', 'seats' => 5, 'traction' => '4x2', 'fuel' => 'gasoline', 'transmission' => 'auto', 'year' => 2019, 'km' => 90000, 'condition' => 'used' ) );
$lot->add( 6, 'es', 'catalog', 'Estribos para pick up', array( 'category' => array( 'Pick up' ) ), 'Accesorio.', array(), array( 'price' => '2500', 'currency_raw' => '2' ) );
$lot_bot = function () use ( $lot, $autos ) {
	return new ASI_Dialogue( $lot, array( 'lang' => 'es', 'language' => 'es', 'site_name' => 'Agencia', 'money' => array( 'base' => 'GTQ', 'map' => array( '2' => 'GTQ' ) ), 'packs' => array( $autos ) ), function () {
		return 0;
	} );
};
$ids = function ( $r ) {
	$x = asi_card_ids( $r );
	sort( $x );
	return $x;
};
check( 'conv: pick up 4x4', array( 1 ), $ids( $lot_bot()->handle( 'pick up 4x4' ) ) );
check( 'conv: suv de 7 pasajeros', array( 3, 4 ), $ids( $lot_bot()->handle( 'suv de 7 pasajeros' ) ) );
check( 'conv: solo atributos, sin tipo', array( 3, 4 ), $ids( $lot_bot()->handle( 'algo para 7 personas' ) ) );
check( 'conv: automática y usada con menos de 50 mil km', array( 4 ), $ids( $lot_bot()->handle( 'suv automática usada con menos de 50 mil km' ) ) );
check( 'conv: del 2025 en adelante', array( 1, 2, 3 ), $ids( $lot_bot()->handle( 'algo del 2025 en adelante' ) ) );
check( 'conv: "pick up" solo trae vehículos', array( 1, 2 ), $ids( $lot_bot()->handle( 'pick up' ) ) );
check( 'conv: "estribos para pick up" trae el accesorio', array( 6 ), asi_card_ids( $lot_bot()->handle( 'estribos para pick up' ) ) );
check( 'conv: 7 pasajeros 4x4 con presupuesto', array( 3 ), $ids( $lot_bot()->handle( '7 pasajeros 4x4 hasta 500 mil' ) ) );
// "Camioneta agrícola" (categoría fiscal) → la carrocería sale del modelo.
$agri = function ( $title ) use ( $autos ) {
	return $autos->attributes( array( 'card' => array( 'title' => $title ), 'facets' => array( array( 'category', 'CAMIONETA AGRICOLA' ) ), 'facts' => array() ), 'vehiculo' )['body'];
};
check( 'agrícola: BMW X5 es SUV', 'suv', $agri( 'BMW X5 XDRIVE40i 2024' ) );
check( 'agrícola: Hilux es pickup', 'pickup', $agri( 'TOYOTA HILUX 2019' ) );
check( 'frase: etiqueta en femenino plural', array( 'automáticas', 'usadas' ), $autos->parse_query( 'automático usado', 'es' )['labels'] );
check( 'frase: "camioneta" queda como palabra blanda', array( 'camioneta' ), $autos->parse_query( 'camioneta del 2024', 'es' )['soft'] );
$r = $lot_bot()->handle( 'camioneta del 2026' );
check( 'conv: "camioneta" (SUV, pickup o van) no exige la palabra si la carrocería cumple', array( 1, 3 ), $ids( $r ) );
check( 'conv: frase de solo atributos sin "con de"', false, strpos( $lot_bot()->handle( 'algo para 7 personas' )['text'], 'con de' ) );
