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
$neg = $lot_bot()->handle( 'suv que no sea gasolina' );
check( 'conv: "suv que no sea gasolina" deja fuera la gasolina', array( 3 ), $ids( $neg ) );
check( 'conv: y lo dice', true, false !== strpos( implode( ' ', (array) $neg['text'] ), 'gasolina' ) && false === strpos( implode( ' ', (array) $neg['text'] ), 'de gasolina' ) );
check( 'conv: "no quiero diesel" con suv', array( 4, 5 ), $ids( $lot_bot()->handle( 'suv, no quiero diesel' ) ) );
$no_match = $lot_bot()->handle( 'suv que no sea familiares' );
check( 'conv: excluir algo que no existe no dice "dejé fuera"', false, (bool) preg_match( '/(Dejé fuera|Ya quité|Sin incluir)/u', implode( ' ', (array) $no_match['text'] ) ) );
$st = array();
$b  = $lot_bot();
$r  = $b->handle( 'algo hasta 250 mil', $st );
$st = $r['state'];
$r  = $b->handle( 'que sea de gasolina', $st );
check( 'conv: "que sea de gasolina" afina y conserva el presupuesto', array( 4, 5 ), $ids( $r ) );
check( 'conv: la búsqueda guardada sigue con el monto', true, false !== strpos( $r['state']['q'], '250' ) );
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
check( 'conv: "camioneta" es SUV y no exige la palabra si la carrocería cumple', array( 3 ), $ids( $r ) );
check( 'frase: "camioneta" es SUV, no pickup', array( 'suv' ), $autos->parse_query( 'quiero camionetas', 'es' )['constraints']['body']['in'] );
check( 'frase: "camioneta para la finca" también pickup', array( 'suv', 'pickup' ), $autos->parse_query( 'camioneta para la finca', 'es' )['constraints']['body']['in'] );
check( 'frase: "camioneta para ir al trabajo" es SUV', array( 'suv' ), $autos->parse_query( 'camioneta para ir al trabajo', 'es' )['constraints']['body']['in'] );
// "suv pickup" guardado + marca: una Hilux cumple "SUV o pickup" sin decir "suv".
$r = $lot_bot()->handle( 'solo toyota', array( 'q' => 'suv pickup hasta 400000', 'turns' => 2 ) );
check( 'refinar: marca sobre "suv pickup" con presupuesto', array( 1 ), $ids( $r ) );
// Sumado no da nada: lo nuevo se busca dentro del presupuesto, no se suelta en silencio.
$r = $lot_bot()->handle( 'solo toyota', array( 'q' => 'suv hasta 400000', 'turns' => 2 ) );
check( 'refinar: sin Toyota SUV en 400 mil, Toyota dentro del presupuesto', array( 1 ), $ids( $r ) );
check( 'refinar: lo dice', true, false !== strpos( $r['text'], 'Dentro de tu presupuesto de hasta Q 400,000' ) );
check( 'refinar: conserva el presupuesto', true, false !== strpos( $r['state']['q'], '400' ) );
$r = $lot_bot()->handle( 'solo toyota', array( 'q' => 'suv hasta 200000', 'turns' => 2 ) );
check( 'refinar: si no alcanza, lo dice', true, false !== strpos( $r['text'], 'no tengo nada dentro de tu presupuesto de hasta Q 200,000' ) );
// Cambio de tipo sin monto: se conserva el presupuesto y se pregunta si sigue.
$bot   = $lot_bot();
$prev  = array( 'q' => 'pickup hasta 400000', 'turns' => 2 );
$r     = $bot->handle( 'ahora quiero ver suv', $prev );
check( 'tipo nuevo: conserva el presupuesto', array( 4, 5 ), array_values( array_intersect( array( 3, 4, 5 ), $ids( $r ) ) ) );
$r     = $bot->budget_check( $r, $prev, 'ahora quiero ver suv' );
check( 'tipo nuevo: pregunta si el presupuesto sigue', true, false !== strpos( $r['followup'], 'sigue siendo el mismo' ) && 'budget' === $r['state']['awaiting'] );
check( 'tipo nuevo: botones', array( 'Sí, el mismo', 'Sin presupuesto' ), $r['suggestions'] );
$r2 = $bot->handle( 'si, el mismo', $r['state'] );
check( 'presupuesto: "sí, el mismo" lo confirma', true, false !== strpos( $r2['text'], 'sigo con tu presupuesto de hasta Q 400,000' ) );
$r3 = $bot->handle( 'Sin presupuesto', $r['state'] );
check( 'presupuesto: "sin presupuesto" lo quita', array( true, true ), array( in_array( 3, $ids( $r3 ), true ), false === strpos( $r3['state']['q'], '400' ) ) );
$r4 = $bot->handle( 'hasta 200 mil', $r['state'] );
check( 'presupuesto: un monto nuevo lo cambia', array( 4 ), array_values( array_intersect( array( 3, 4, 5 ), $ids( $r4 ) ) ) );
$same = $bot->budget_check( $bot->handle( 'que sea 4x4', $prev ), $prev, 'que sea 4x4' );
check( 'mismo tipo: no pregunta por el presupuesto', '', $same['state']['awaiting'] ?? '' );
$prev = array( 'q' => 'pickup hasta 100000', 'turns' => 2 );
$r    = $bot->handle( 'quiero ver suv', $prev );
check( 'tipo nuevo sin nada en el presupuesto: lo dice', true, false !== strpos( $r['text'], 'Dentro de tu presupuesto de hasta Q 100,000 no tengo' ) );
$r    = $bot->budget_check( $r, $prev, 'quiero ver suv' );
check( 'tipo nuevo sin nada en el presupuesto: pregunta por otro', true, false !== strpos( $r['followup'], 'otro presupuesto' ) );
$r = $bot->handle( 'quiero ver suv', array( 'q' => 'pickup que no sea mazda', 'turns' => 2 ) );
check( 'tipo nuevo: sigue sin lo que no quiere', true, false !== strpos( $r['state']['q'], 'que no sea mazda' ) );
// "¿Y Honda tienes?" tras Toyota: cambia la marca y se queda el tipo y el presupuesto.
$lot2 = new ASI_Test_Repository();
foreach ( array( array( 41, 'Toyota RAV4', 'Toyota', 'suv', 200000 ), array( 42, 'Honda CR-V', 'Honda', 'suv', 210000 ), array( 43, 'Honda Pilot', 'Honda', 'suv', 450000 ), array( 44, 'Toyota Hilux', 'Toyota', 'pickup', 250000 ) ) as $p ) {
	$lot2->add( $p[0], 'es', 'catalog', $p[1], array( 'category' => array( 'pickup' === $p[3] ? 'Pick up' : 'SUV' ), 'brand' => array( $p[2] ) ), '.', array(), array( 'price' => (string) $p[4], 'currency_raw' => '2', 'attrs' => array( 'body' => $p[3] ) ) );
}
$bot2 = new ASI_Dialogue( $lot2, array( 'lang' => 'es', 'language' => 'es', 'site_name' => 'Agencia', 'money' => array( 'base' => 'GTQ', 'map' => array( '2' => 'GTQ' ) ), 'packs' => array( $autos ) ), function () {
	return 0;
} );
$r = $bot2->handle( 'y hondas tienes', array( 'q' => 'camionetas toyota hasta 300000', 'turns' => 2 ) );
check( 'marca: "¿y hondas tienes?" cambia de Toyota a Honda', array( 42 ), $ids( $r ) );
check( 'marca: lo dice sin "no me quedó ninguna"', array( true, false ), array( 0 === strpos( $r['text'], 'Sí, de' ) || 0 === strpos( $r['text'], 'Claro, de' ), false !== strpos( $r['text'], 'ninguna' ) ) );
check( 'marca: no corrige "tienes"', false, false !== strpos( $r['text'], 'Supongo' ) );
check( 'marca: conserva tipo y presupuesto', true, false !== strpos( $r['state']['q'], '300000' ) && false === strpos( $r['state']['q'], 'toyota' ) );
// Frase real: "quiero una camioneta" y luego "que sea toyota o honda que no cueste más de 300 mil":
// SUV de cualquiera de las dos marcas dentro del presupuesto (no la pickup, no la de 450 mil).
$s = $bot2->handle( 'quiero una camioneta', array() );
$r = $bot2->handle( 'pero que sea toyota o honda que no cueste mas de 300 mil', $s['state'] );
check( 'marcas: "toyota o honda" con camioneta y presupuesto', array( 41, 42 ), ( function ( $a ) { sort( $a ); return $a; } )( $ids( $r ) ) );
check( 'marcas: sin decir "no tengo"', false, false !== strpos( $r['text'], 'No tengo' ) );
check( 'marcas: nombra las dos', true, false !== strpos( $r['text'], 'toyota o honda' ) );
$r = $bot2->handle( 'suv toyota honda hasta 300000', array() );
check( 'marcas: la frase de la IA "suv toyota honda" son las dos', array( 41, 42 ), ( function ( $a ) { sort( $a ); return $a; } )( $ids( $r ) ) );
$spec = ASI_AI_Interpreter::switch_brands( array( 'brands' => array( 'toyota', 'honda' ) ), 'y hondas tienes' );
check( 'IA: "y hondas tienes" deja solo Honda', array( 'honda' ), $spec['brands'] );
$spec = ASI_AI_Interpreter::switch_brands( array( 'brands' => array( 'toyota', 'honda' ) ), 'tambien quiero ver honda' );
check( 'IA: "también honda" deja las dos', array( 'toyota', 'honda' ), $spec['brands'] );
check( 'conv: frase de solo atributos sin "con de"', false, strpos( $lot_bot()->handle( 'algo para 7 personas' )['text'], 'con de' ) );
// Frase real: palabras de cortesía no se buscan; solo híbridos, sin "no encontré algo exacto".
$v( 7, 'BYD Song Plus DM-i', 'SUV', 249900, array( 'body' => 'suv', 'seats' => 5, 'fuel' => 'hybrid', 'year' => 2027 ) );
$v( 8, 'Toyota RAV4 Hybrid', 'SUV', 329900, array( 'body' => 'suv', 'seats' => 5, 'fuel' => 'hybrid', 'year' => 2026 ) );
$s = array();
$r = $lot_bot()->handle( 'puedes mostrarme opciones híbridas porfavor', $s );
check( 'real: "opciones híbridas" trae solo híbridos', array( 7, 8 ), $ids( $r ) );
check( 'real: y no dice "no encontré algo exacto"', false, false !== strpos( $r['text'], 'exacto' ) );
$s = $r['state'];
$r = $lot_bot()->handle( 'puedes mostrarme más opciones porfavor', $s );
check( 'real: "puedes mostrarme más opciones porfavor" es pedir más', true, in_array( $r['kind'], array( 'more', 'no_more' ), true ) );
foreach ( array( 'híbridas' => 'hybrid', 'híbridos' => 'hybrid', 'eléctricas' => 'electric', 'eléctricos' => 'electric', 'diésel' => 'diesel' ) as $word => $fuel ) {
	check( "frase: \"{$word}\"", array( 'in' => array( $fuel ) ), $autos->parse_query( 'opciones ' . $word, 'es' )['constraints']['fuel'] ?? null );
}
