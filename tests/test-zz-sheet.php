<?php
// Ficha de la búsqueda: genérica (sitio de maquinaria sin pack) y con pack (autos).
// (Corre al final: usa los repositorios de prueba de test-autos.php.)

$phr   = (array) ASI_Lexicon::get( 'es' )->get_list( 'ai_phrases' );
$money = array( 'base' => 'GTQ', 'map' => array( '2' => 'GTQ' ) );

// --- Sitio sin pack (maquinaria): categorías y marcas del sitio.
$delta_repo = asi_test_delta_repository();
$an_m       = new ASI_Query_Analyzer( ASI_Lexicon::get( 'es' ), $delta_repo->vocabulary( 'es-sv' ), array( 'base' => 'USD' ), array() );
$sheet_of   = function ( $an, $text ) {
	return ASI_Search_Sheet::from_query( $an->analyze( $text ) );
};
$round = function ( $an, $text ) use ( $phr, $sheet_of ) {
	$a = $sheet_of( $an, $text );
	$b = $sheet_of( $an, ASI_Search_Sheet::render( $a, $phr ) );
	return $a === $b;
};
foreach ( array( 'montacargas electrico hasta 30000', 'tractores john deere', 'montacargas que no sea un forklifts', 'tractores desde 10000 hasta 50000' ) as $t ) {
	check( "ficha (maquinaria): se escribe y se vuelve a leer igual \"$t\"", true, $round( $an_m, $t ) );
}
$base = $sheet_of( $an_m, 'tractores john deere hasta 50000' );
$m    = ASI_Search_Sheet::merge( $base, $sheet_of( $an_m, 'mejor montacargas' ), array() );
check( 'ficha (maquinaria): otra categoría es tema nuevo y conserva el presupuesto', array( 'topic', array( 'category' => array( 'montacarga' ) ), 50000.0 ), array( $m['mode'], $m['sheet']['terms'], $m['sheet']['max'] ) );
$m = ASI_Search_Sheet::merge( $base, $sheet_of( $an_m, 'y hasta 20000' ), array( 'refine' => true ) );
check( 'ficha (maquinaria): un monto nuevo reemplaza al anterior y se queda lo demás', array( 'refine', 20000.0, array( 'john deere' ) ), array( $m['mode'], $m['sheet']['max'], $m['sheet']['terms']['brand'] ?? array() ) );

// --- Autos (pack con atributos).
$yard3 = new ASI_Test_Repository();
foreach ( array( array( 51, 'Toyota RAV4', 'Toyota', 'suv', 200000, 'gasoline' ), array( 52, 'Honda CR-V', 'Honda', 'suv', 210000, 'gasoline' ), array( 53, 'Honda Pilot', 'Honda', 'suv', 450000, 'gasoline' ), array( 54, 'Toyota Hilux', 'Toyota', 'pickup', 250000, 'diesel' ), array( 55, 'Mazda CX-5', 'Mazda', 'suv', 230000, 'gasoline' ), array( 56, 'Toyota Fortuner', 'Toyota', 'suv', 380000, 'diesel' ) ) as $p ) {
	$yard3->add( $p[0], 'es', 'catalog', $p[1], array( 'category' => array( 'pickup' === $p[3] ? 'Pick up' : 'SUV' ), 'brand' => array( $p[2] ) ), '.', array(), array( 'price' => (string) $p[4], 'currency_raw' => '2', 'attrs' => array( 'body' => $p[3], 'fuel' => $p[5] ) ) );
}
$an_a = new ASI_Query_Analyzer( ASI_Lexicon::get( 'es' ), $yard3->vocabulary( 'es' ), $money, array( $autos ) );
$keys = ASI_Search_Sheet::type_keys( array( $autos ) );
check( 'ficha (autos): el tipo es la carrocería', array( 'body' ), $keys );
foreach ( array( 'camioneta toyota o honda hasta 300000', 'suv gasolina hasta 230000 que no sea bmw mercedes', 'pickup 4x4 diesel desde 100000 hasta 400000', 'suv que no sea gasolina' ) as $t ) {
	check( "ficha (autos): se escribe y se vuelve a leer igual \"$t\"", true, $round( $an_a, $t ) );
}
$merge = function ( $base_text, $msg, $opts = array() ) use ( $an_a, $keys, $sheet_of ) {
	return ASI_Search_Sheet::merge( $sheet_of( $an_a, $base_text ), $sheet_of( $an_a, $msg ), array_merge( array( 'type_keys' => $keys ), $opts ) );
};
// El caso de la usuaria: camioneta → "toyota o honda hasta 300 mil" sigue siendo camioneta.
$m = $merge( 'quiero una camioneta', 'pero que sea toyota o honda que no cueste mas de 300 mil', array( 'refine' => true ) );
check( 'ficha (autos): camioneta + dos marcas + presupuesto', array( 'refine', array( 'toyota', 'honda' ), 300000.0, true ), array( $m['mode'], $m['sheet']['terms']['brand'] ?? array(), $m['sheet']['max'], isset( $m['sheet']['attrs']['body'] ) ) );
$m = $merge( 'camioneta toyota hasta 300000', 'y honda', array( 'refine' => true ) );
check( 'ficha (autos): otra marca reemplaza', array( 'honda' ), $m['sheet']['terms']['brand'] ?? array() );
$m = $merge( 'camioneta toyota hasta 300000', 'y tambien honda', array( 'refine' => true, 'also' => true ) );
check( 'ficha (autos): "también" suma la marca', array( 'toyota', 'honda' ), $m['sheet']['terms']['brand'] ?? array() );
$m = $merge( 'pickup hasta 300000 que no sea mazda', 'ahora quiero ver suv' );
check( 'ficha (autos): otro tipo es tema nuevo: se quedan presupuesto y exclusiones', array( 'topic', 300000.0, array( 'mazda' ) ), array( $m['mode'], $m['sheet']['max'], $m['sheet']['exclude'] ) );
$m = $merge( 'hasta 260000 gasolina que no sea bmw', 'quiero tipo camioneta' );
check( 'ficha (autos): poner tipo donde no había afina (no borra gasolina)', array( 'refine', true, array( 'bmw' ) ), array( $m['mode'], isset( $m['sheet']['attrs']['fuel'] ), $m['sheet']['exclude'] ) );
$m = $merge( 'camioneta toyota', 'no quiero toyota', array( 'refine' => true ) );
check( 'ficha (autos): "no quiero toyota" la quita de lo que pide', array( array(), array( 'toyota' ) ), array( $m['sheet']['terms']['brand'] ?? array(), $m['sheet']['exclude'] ) );
$m = $merge( 'camioneta que no sea honda', 'quiero honda', array( 'refine' => true ) );
check( 'ficha (autos): pedir lo que antes no quería lo saca de exclusiones', array( array( 'honda' ), array() ), array( $m['sheet']['terms']['brand'] ?? array(), $m['sheet']['exclude'] ) );

// Aflojar: palabras, luego atributos, luego marcas; el tipo nunca.
$steps = ASI_Search_Sheet::relaxations( $sheet_of( $an_a, 'camioneta toyota gasolina hasta 300000' ), $keys );
$last  = end( $steps );
check( 'ficha (autos): al aflojar el tipo se queda', true, isset( $last['sheet']['attrs']['body'] ) && 300000.0 === $last['sheet']['max'] );
check( 'ficha (autos): orden de aflojar (gasolina, luego la marca)', array( array( 'gasolina' ), array( 'gasolina', 'toyota' ) ), array_column( $steps, 'dropped' ) );
$m = $merge( 'suv toyota hasta 300000', 'y camionetas honda', array( 'refine' => true ) );
check( 'ficha (autos): "camioneta" y "suv" son el mismo tipo (no es tema nuevo)', array( 'refine', 300000.0 ), array( $m['mode'], $m['sheet']['max'] ) );

// --- Conversaciones completas.
// Sitio de maquinaria (sin pack): la ficha funciona igual con categorías y marcas del sitio.
$mq = asi_test_dialogue( asi_test_delta_repository() );
$s1 = $mq->handle( 'busco tractores', array() );
$s2 = $mq->handle( 'y john deere', $s1['state'] );
check( 'conv (maquinaria): "y john deere" sigue con tractores', array( 3, 4 ), ( function ( $a ) { sort( $a ); return $a; } )( asi_card_ids( $s2 ) ) );
$s3 = $mq->handle( 'mejor montacargas', $s2['state'] );
check( 'conv (maquinaria): otra categoría es búsqueda nueva (sin John Deere)', true, (bool) array_intersect( array( 1, 2, 6 ), asi_card_ids( $s3 ) ) && ! array_intersect( array( 3, 4, 7 ), asi_card_ids( $s3 ) ) );
$s4 = $mq->handle( 'que no sea un forklifts', $s3['state'] );
check( 'conv (maquinaria): "que no sea UN Forklifts" sigue con montacargas', array( 6 ), asi_card_ids( $s4 ) );
$s5 = $mq->handle( 'empecemos de nuevo, busco excavadoras', $s4['state'] );
check( 'conv (maquinaria): "empecemos de nuevo" borra lo anterior', array( 5 ), asi_card_ids( $s5 ) );

// Autos: aflojar lo de antes, no lo que acaba de pedir, y decir qué se quitó.
$bot3 = new ASI_Dialogue( $yard3, array( 'lang' => 'es', 'language' => 'es', 'site_name' => 'Agencia', 'money' => $money, 'packs' => array( $autos ) ), function () {
	return 0;
} );
$r = $bot3->handle( 'y toyota', array( 'q' => 'suv diesel hasta 300000', 'turns' => 2 ) );
check( 'conv (autos): sin Toyota SUV diésel en 300 mil, suelta "diésel" (lo de antes) y no Toyota', array( 51 ), asi_card_ids( $r ) );
check( 'conv (autos): dice qué quitó', true, false !== strpos( $r['text'], 'Con diesel no tengo opciones' ) );
$r = $bot3->handle( 'que sea de gasolina', array( 'q' => 'camioneta toyota hasta 300000 que no sea mazda', 'turns' => 2 ) );
check( 'conv (autos): una característica nueva conserva tipo, marca, presupuesto y exclusiones', array( 51 ), asi_card_ids( $r ) );
check( 'conv (autos): la ficha guardada tiene todo', true, (bool) preg_match( '/suv|camioneta/', $r['state']['q'] ) && false !== strpos( $r['state']['q'], 'toyota' ) && false !== strpos( $r['state']['q'], '300000' ) && false !== strpos( $r['state']['q'], 'mazda' ) );
check( 'ficha (maquinaria): varias marcas que no quiere se guardan todas', 2, count( $sheet_of( $an_m, 'maquinaria que no sea un forklifts ni john deere' )['exclude'] ) );
