<?php
// Moneda: el caso que vio el jefe (rango en quetzales → vehículos en dólares que valen el triple).
$cfg   = array( 'base' => 'GTQ', 'map' => array( '1' => 'USD', '2' => 'GTQ' ), 'rates' => array( 'USD' => 7.7 ) );
$words = ( require dirname( __DIR__ ) . '/includes/lang/es.php' )['money_words'];
$b     = function ( $text ) use ( $words, $cfg ) {
	$r = ASI_Money::extract_budget( $text, $words, $cfg );
	return array( $r['min'], $r['max'], $r['currency'] );
};

// Lectura de montos.
check( 'rango con mil al final', array( 100000.0, 200000.0, '' ), $b( 'busco un carro entre 100 y 200 mil' ) );
check( 'rango con Q y comas', array( 100000.0, 150000.0, 'GTQ' ), $b( 'de Q100,000 a Q150,000' ) );
check( 'dólares con $', array( null, 30000.0, 'USD' ), $b( 'algo de $30,000' ) );
check( 'quetzales en palabras', array( null, 300000.0, 'GTQ' ), $b( 'hasta 300 mil quetzales' ) );
check( 'decimales Q.250,000.00', array( null, 250000.0, 'GTQ' ), $b( 'pickup hasta Q.250,000.00' ) );
check( 'millones', array( null, 1500000.0, '' ), $b( 'algo de 1.5 millones' ) );
check( 'k', array( null, 300000.0, '' ), $b( 'suv de 300k' ) );
check( 'presupuesto', array( null, 250000.0, '' ), $b( 'mi presupuesto es de 250 mil' ) );
check( 'alrededor de ±15 %', array( 34000.0, 46000.0, 'USD' ), $b( 'alrededor de 40 mil dólares' ) );
check( 'moneda dicha aparte', array( null, 40000.0, 'USD' ), $b( 'precio en dolares hasta 40 mil' ) );
check( 'tienda: menos de 200', array( null, 200.0, '' ), $b( 'camisas de menos de 200' ) );

// Lo que no es dinero.
check( 'km no es precio', array( null, null, '' ), $b( 'menos de 50 mil km' ) );
check( '15000 km no es 1500', array( null, null, '' ), $b( 'hasta 15000 km' ) );
check( 'pasajeros no es precio', array( null, null, '' ), $b( 'más de 5 pasajeros' ) );
check( 'rango de pasajeros no es precio', array( null, null, '' ), $b( 'de 5 a 7 pasajeros' ) );
check( 'años no son precio', array( null, null, '' ), $b( 'un modelo de 2020 a 2022' ) );
check( 'hijos no son presupuesto', array( null, null, '' ), $b( 'tengo 3 hijos' ) );

// Conversión.
check( 'USD → GTQ', 476630.0, ASI_Money::to_base( 61900, 'USD', $cfg ) );
check( 'sin tipo de cambio usa referencia', 7.7, ASI_Money::rate( 'USD', array( 'base' => 'GTQ' ) ) );
check( 'código numérico sin mapa no se adivina', '', ASI_Money::currency_from_value( '1', array( 'base' => 'GTQ' ) ) );
check( 'código ISO', 'USD', ASI_Money::currency_from_value( 'usd', array() ) );
check( 'símbolo en el precio', 'GTQ', ASI_Money::currency_in_text( 'Q 12,500.00', array() ) );
check( '$ es dólar en Guatemala', 'USD', ASI_Money::currency_in_text( '$ 95', array( 'base' => 'GTQ' ) ) );
check( '$ es peso en México', 'MXN', ASI_Money::currency_in_text( '$ 95', array( 'base' => 'MXN' ) ) );
check( 'base por país', 'GTQ', ASI_Money::resolve_base( '', 'gt' ) );
check( 'formato', '$ 61,900', ASI_Money::format( 61900, 'USD' ) );

// El buscador completo: una agencia con precios en dos monedas.
$agency = new ASI_Test_Repository();
$agency->add( 1, 'es', 'catalog', 'BMW X2', array( 'category' => array( 'SUV' ), 'brand' => array( 'BMW' ) ), 'SUV.', array(), array( 'price' => '61900', 'currency_raw' => '1' ) );
$agency->add( 2, 'es', 'catalog', 'Mazda CX5 Touring', array( 'category' => array( 'SUV' ), 'brand' => array( 'Mazda' ) ), 'SUV.', array(), array( 'price' => '169900', 'currency_raw' => '2' ) );
$agency->add( 3, 'es', 'catalog', 'Mitsubishi Outlander GLX', array( 'category' => array( 'SUV' ), 'brand' => array( 'Mitsubishi' ) ), 'SUV.', array(), array( 'price' => '159900', 'currency_raw' => '2' ) );
$agency->add( 4, 'es', 'catalog', 'Toyota Hilux SRV', array( 'category' => array( 'Pick-up' ), 'brand' => array( 'Toyota' ) ), 'Pickup.', array(), array( 'price' => '384990', 'currency_raw' => '2' ) );
$agency->add( 5, 'es', 'catalog', 'Kia Sportage', array( 'category' => array( 'SUV' ), 'brand' => array( 'Kia' ) ), 'SUV.', array(), array( 'price' => '45000', 'currency_raw' => '9' ) );
$agency_bot = function () use ( $agency, $cfg ) {
	return new ASI_Dialogue( $agency, array( 'lang' => 'es', 'language' => 'es', 'site_name' => 'Agencia', 'money' => $cfg ), function () {
		return 0;
	} );
};
$sorted = function ( array $ids ) {
	sort( $ids );
	return $ids;
};

$r = $agency_bot()->handle( 'suv entre 50 y 70 mil' );
check( 'jefe: rango en Q no trae el BMW de $61,900 (≈Q476 mil)', array(), $sorted( asi_card_ids( $r ) ) );
$r = $agency_bot()->handle( 'suv entre 150 y 200 mil' );
check( 'rango en Q: solo SUV en ese rango', array( 2, 3 ), $sorted( asi_card_ids( $r ) ) );
$r = $agency_bot()->handle( 'suv de menos de 70 mil dólares' );
check( 'tope en USD: entran los de Q convertidos', array( 1, 2, 3 ), $sorted( asi_card_ids( $r ) ) );
check( 'moneda sin definir no entra en presupuesto', false, in_array( 5, asi_card_ids( $r ), true ) );
$bmw = array_values( array_filter( $r['cards'], function ( $c ) { return 1 === $c['id']; } ) )[0];
check( 'tarjeta con símbolo', '$ 61,900', $bmw['price'] );
check( 'tarjeta con equivalente', '≈ Q 476,630', $bmw['price_alt'] );
check( 'no manda el código interno de moneda', false, isset( $bmw['currency_raw'] ) );
$r = $agency_bot()->handle( 'cuál es la suv más cara' );
check( 'el más caro compara en la misma moneda', 1, isset( $r['cards'][0] ) ? $r['cards'][0]['id'] : null );
$agency->add( 6, 'es', 'catalog', 'Estribos para SUV', array( 'category' => array( 'SUV' ) ), 'Accesorio.' );
$r = $agency_bot()->handle( 'suv entre 150 y 200 mil' );
check( 'con presupuesto no salen productos sin precio', array( 2, 3 ), $sorted( asi_card_ids( $r ) ) );
$r = $agency_bot()->handle( 'algo de 60 a 70 mil dolares' );
check( 'solo presupuesto: busca en todo el catálogo ($60–70 mil = Q462–539 mil)', array( 1 ), $sorted( asi_card_ids( $r ) ) );
check( 'solo presupuesto: primero lo más cercano al tope', 1, $r['cards'][0]['id'] );
check( 'solo presupuesto: lo dice en la moneda del cliente', true, false !== strpos( $r['text'], 'entre $ 60,000 y $ 70,000' ) );
$r = $agency_bot()->handle( 'tengo como 200 mil' );
check( 'presupuesto en Q sin tipo: lo que cabe, del más cercano al tope', array( 2, 3 ), asi_card_ids( $r ) );

// Negación antes del monto (lo que probó el jefe): invierte el sentido.
check( 'que no sean mayor a 100 mil = tope', array( null, 100000.0, '' ), $b( 'pero que no sean mayor a 100 mil' ) );
check( 'que no sea de más de 200 mil = tope', array( null, 200000.0, '' ), $b( 'que no sea de mas de 200 mil' ) );
check( 'que no cueste más de Q150,000 = tope', array( null, 150000.0, 'GTQ' ), $b( 'que no cueste más de Q150,000' ) );
check( 'que no sea menos de 50 mil = piso', array( 50000.0, null, '' ), $b( 'que no sea menos de 50 mil' ) );
check( 'no mayor a = tope', array( null, 80000.0, '' ), $b( 'no mayor a 80 mil' ) );
check( 'no menos de = piso', array( 60000.0, null, '' ), $b( 'no menos de 60 mil' ) );
check( 'mayor a sin negación sigue siendo piso', array( 100000.0, null, '' ), $b( 'mayor a 100 mil' ) );
$neg = ASI_Money::extract_budget( 'pero que no sean mayor a 100 mil', $words, $cfg );
check( 'la negación sale junto con el monto', true, false !== strpos( $neg['spans'][0], 'que no sean' ) );
