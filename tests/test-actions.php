<?php
// Cerrar la venta en el chat: cuotas, cotización y comparación en PDF.
require_once dirname( __DIR__ ) . '/packs/autos/class-asi-pack-autos.php';

final class ASI_Test_Runner implements ASI_Action_Runner {
	public $calls = array();
	public $fail  = false;
	public function available() {
		return array(
			'quote'   => array( 'label' => 'Cotización', 'fields' => array( 'name', 'email', 'phone' ), 'max_items' => 8 ),
			'compare' => array( 'label' => 'Comparación', 'fields' => array( 'name', 'email', 'phone' ), 'max_items' => 4 ),
		);
	}
	public function run( $action, array $item_ids, array $customer ) {
		$this->calls[] = array( $action, $item_ids, $customer );
		return $this->fail ? array() : array( 'url' => 'https://example.com/wp-json/asi/v1/file?t=abc', 'filename' => $action . '.pdf', 'emailed' => true, 'test' => true );
	}
	public function financing( $item_id ) {
		return array( 'rate' => 0.09, 'down_min' => 0.2, 'down_max' => 0.7, 'down_default' => 0.2, 'terms' => array( 12, 24, 36, 48, 60, 72 ) );
	}
}

$runner = new ASI_Test_Runner();
$shop3  = new ASI_Test_Repository();
$add    = function ( $id, $title, $price ) use ( $shop3 ) {
	$shop3->add( $id, 'es', 'catalog', $title, array( 'category' => array( 'Pick up' ), 'brand' => array( strtok( $title, ' ' ) ) ), 'Pickup.', array(), array( 'price' => (string) $price, 'currency_raw' => '2', 'brand' => strtok( $title, ' ' ), 'attrs' => array( 'body' => 'pickup', 'seats' => 5, 'traction' => '4x4', 'fuel' => 'diesel', 'cc' => 2500 ) ) );
};
$add( 1, 'NISSAN FRONTIER DC', 300000 );
$add( 2, 'ISUZU D-MAX DC', 349900 );
$add( 3, 'MAZDA BT-50', 344900 );
$abot = new ASI_Dialogue( $shop3, array( 'lang' => 'es', 'language' => 'es', 'site_name' => 'Agencia', 'money' => array( 'base' => 'GTQ', 'map' => array( '2' => 'GTQ' ) ), 'packs' => array( new ASI_Pack_Autos() ), 'actions' => $runner ), function () {
	return 0;
} );
$say = function ( $message, &$state ) use ( $abot ) {
	$r     = $abot->handle( $message, $state );
	$state = $r['state'];
	return $r;
};

// Cuotas: la fórmula del sitio (9 %, 20 % de enganche).
$price_of = array( 1 => 300000, 2 => 349900, 3 => 344900 );
$s = array();
$say( 'pick up 4x4', $s );
$first  = $s['results'][0];
$second = $s['results'][1];
$third  = $s['results'][2];
$r = $say( '¿cuánto pagaría al mes por el primero?', $s );
check( 'cuotas: tabla con precio, enganche y 6 plazos', 8, count( $r['table']['rows'] ) );
$expected = ( 0.0075 * $price_of[ $first ] * 0.8 ) / ( 1 - pow( 1.0075, -60 ) );
check( 'cuotas: 60 meses con la fórmula', 'Q ' . number_format( round( $expected ), 0, '.', ',' ), $r['table']['rows'][6][1] );
check( 'cuotas: el enganche va en la tabla', array( 'Enganche (20 %)', ASI_Money::format( round( $price_of[ $first ] * 0.2 ), 'GTQ' ) ), $r['table']['rows'][1] );
check( 'cuotas: el precio va en la tabla', ASI_Money::format( $price_of[ $first ], 'GTQ' ), $r['table']['rows'][0][1] );
check( 'cuotas: ofrece otros enganches', true, in_array( 'Enganche de 30 %', $r['suggestions'], true ) );
check( 'cuotas: invita a escribir el enganche propio', true, false !== strpos( $r['followup'], 'escríbeme el tuyo' ) );
check( 'cuotas: ofrece la cotización', 'quote', $s['offer']['action'] );
$r = $say( 'con 100 mil de enganche a 48 meses', $s );
check( 'cuotas: enganche y plazo que pidió', array( 'Q 100,000', '48 meses' ), array( $r['table']['rows'][1][1], $r['table']['rows'][2][0] ) );
$r2 = $say( 'Enganche de 30 %', $s );
check( 'cuotas: el botón de otro enganche funciona', 'Enganche (30 %)', $r2['table']['rows'][1][0] );
$r = $r2;
$r = $say( 'con 10 % de enganche', $s );
check( 'cuotas: respeta el enganche mínimo y lo dice', true, false !== strpos( $r['text'], 'mínimo' ) && false !== strpos( $r['text'], ASI_Money::format( round( $price_of[ $first ] * 0.2 ), 'GTQ' ) ) );

// "Sí" → pide los datos → los recibe → cotiza.
$r = $say( 'sí', $s );
check( 'cotizar: pide nombre y correo', true, false !== strpos( $r['text'], 'nombre' ) && false !== strpos( $r['text'], 'correo' ) );
check( 'cotizar: avisa que un asesor puede contactarle', true, false !== strpos( $r['followup'], 'asesor' ) );
check( 'cotizar: todavía no ejecuta', 0, count( $runner->calls ) );
$r = $say( 'Ana López', $s );
check( 'cotizar: con el nombre, pide el correo', true, false !== strpos( $r['text'], 'Ana' ) && false !== strpos( $r['text'], 'correo' ) );
$r = $say( 'ana.lopez@example.com y mi cel es 5555-1234', $s );
check( 'cotizar: ejecuta la cotización del vehículo correcto', array( 'quote', array( $first ) ), array( $runner->calls[0][0], $runner->calls[0][1] ) );
check( 'cotizar: con los datos del cliente', array( 'Ana López', 'ana.lopez@example.com', '5555-1234' ), array_values( $runner->calls[0][2] ) );
check( 'cotizar: responde con el PDF', 'https://example.com/wp-json/asi/v1/file?t=abc', $r['file']['url'] );
check( 'cotizar: dice que lo mandó al correo', true, false !== strpos( $r['text'], 'ana.lopez@example.com' ) );
check( 'cotizar: modo prueba lo dice', true, false !== strpos( $r['text'], 'prueba' ) );

// Con los datos ya dados, la siguiente va directo.
$say( 'pick up 4x4', $s );
$r = $say( 'me interesa el segundo', $s );
check( 'cotizar: "me interesa el segundo" con datos ya dados → directo', array( 'quote', array( $second ) ), array( $runner->calls[1][0], $runner->calls[1][1] ) );

// Comparación en PDF después de comparar.
$say( 'pick up 4x4', $s );
$r = $say( 'compara el primero con el segundo', $s );
check( 'comparar: ofrece el PDF', true, false !== strpos( $r['followup'], 'PDF' ) );
$r = $say( 'sí, mándamela', $s );
check( 'comparar: PDF de la comparación de esos dos', 'compare', $runner->calls[2][0] );
check( 'comparar: los mismos dos', 2, count( $runner->calls[2][1] ) );

// Cancelar a mitad.
$s2 = array();
$say( 'pick up 4x4', $s2 );
$say( 'me interesa el tercero', $s2 );
$r = $say( 'mejor no', $s2 );
check( 'cancelar: deja de pedir datos', array(), $s2['pending'] );

// Sin decir cuál, pregunta.
$s3 = array();
$say( 'pick up 4x4', $s3 );
$r = $say( 'me interesa', $s3 );
check( 'cotizar: si no dice cuál, pregunta', true, false !== strpos( $r['text'], 'Cuál' ) || false !== strpos( $r['text'], 'cuál' ) );
$r = $say( 'el tercero', $s3 );
check( 'cotizar: con "el tercero" sigue con ese', array( $s3['offer']['ids'][2] ?? $third ), $s3['pending']['ids'] );

// Si falla, no se queda colgado y da el enlace.
$runner->fail = true;
$s4 = array( 'customer' => array( 'name' => 'Ana', 'email' => 'ana@example.com', 'phone' => '' ) );
$say( 'pick up 4x4', $s4 );
$r = $say( 'cotízame el primero', $s4 );
check( 'falla: lo dice y da el enlace del vehículo', true, false !== strpos( $r['text'], 'no pude' ) && 1 === count( $r['links'] ) );
$runner->fail = false;

// "soy una familia de cuatro" no es un nombre (solo se lee el nombre cuando el chat lo pidió).
$s5 = array();
$r = $say( 'soy una familia de cuatro, ¿qué me recomiendas?', $s5 );
check( 'nombre: no confunde el perfil con un nombre', '', $s5['customer']['name'] );
// Después de las cuotas se puede seguir comparando los resultados.
$s6 = array();
$say( 'pick up 4x4', $s6 );
$say( 'cuánto pagaría al mes por el primero', $s6 );
$r = $say( 'compara el primero con el segundo', $s6 );
check( 'cuotas: no borra los resultados (se puede comparar después)', 'compare', $r['kind'] );
// Después de "parecido al Frontier" (que ofrece comparar), "me interesa el primero" es cotizar.
$runner->calls = array();
$s7 = array( 'customer' => array( 'name' => 'Ana', 'email' => 'ana@example.com', 'phone' => '' ) );
$r  = $say( 'busco algo parecido al Frontier', $s7 );
$first_similar = $s7['results'][0];
$r  = $say( 'me interesa el primero', $s7 );
check( 'parecido → "me interesa el primero" cotiza (no compara)', array( 'quote', array( $first_similar ) ), isset( $runner->calls[0] ) ? array( $runner->calls[0][0], $runner->calls[0][1] ) : array() );
// Varias cotizaciones a la vez (frases reales de la prueba del usuario).
$runner->calls = array();
$add( 4, 'MG RX9 ELEGANCE', 284900 );
$s8 = array( 'customer' => array( 'name' => 'Ana', 'email' => 'ana@example.com', 'phone' => '' ) );
$say( 'pick up 4x4', $s8 );
$s8['shown'] = array_merge( $s8['shown'], array( 4 ) );
$r = $say( 'cotízame la nissan frontier y la suv mg rx9', $s8 );
check( 'varias: por nombre', array( 'quote', array( 1, 4 ) ), isset( $runner->calls[0] ) ? array( $runner->calls[0][0], $runner->calls[0][1] ) : array() );
check( 'varias: lo dice con los dos nombres', true, false !== strpos( $r['text'], 'NISSAN FRONTIER DC' ) && false !== strpos( $r['text'], 'MG RX9 ELEGANCE' ) );
$r = $say( 'quiero la cotización de estos 2 NISSAN FRONTIER DC y MG RX9 ELEGANCE', $s8 );
check( 'varias: "de estos 2 X y Y" usa los nombres, no los dos primeros', array( 1, 4 ), $runner->calls[1][1] );
$say( 'pick up 4x4', $s8 );
$r = $say( 'cotízame el primero y el tercero', $s8 );
check( 'varias: por número', array( $s8['results'][0] ?? 0, 0 )[0] ? 2 : 2, count( $runner->calls[2][1] ) );
$say( 'pick up 4x4', $s8 );
$r = $say( 'me interesan las dos primeras', $s8 );
check( 'varias: "las dos primeras"', 2, count( $runner->calls[3][1] ) );
$say( 'pick up 4x4', $s8 );
$r = $say( 'cotízame todas', $s8 );
check( 'varias: "todas" (los 4 resultados)', 4, count( $runner->calls[4][1] ) );
// Después de "¿cuál te interesa?", responder con nombres.
$s9 = array( 'customer' => array( 'name' => 'Ana', 'email' => 'ana@example.com', 'phone' => '' ) );
$say( 'pick up 4x4', $s9 );
$say( 'me interesa', $s9 );
$runner->calls = array();
$r = $say( 'la nissan frontier te dije y la mazda', $s9 );
check( 'varias: responde "¿cuál?" con dos nombres', array( 1, 3 ), isset( $runner->calls[0] ) ? $runner->calls[0][1] : array() );
// Un modelo con varias versiones que no se mostró: toma la más relevante.
$runner->calls = array();
$shop3->add( 5, 'es', 'catalog', 'KIA SORENTO EX', array( 'category' => array( 'SUV' ), 'brand' => array( 'KIA' ) ), 'SUV.', array(), array( 'price' => '300000', 'currency_raw' => '2', 'brand' => 'KIA' ) );
$shop3->add( 6, 'es', 'catalog', 'KIA SORENTO GT', array( 'category' => array( 'SUV' ), 'brand' => array( 'KIA' ) ), 'SUV.', array(), array( 'price' => '350000', 'currency_raw' => '2', 'brand' => 'KIA' ) );
$s10 = array( 'customer' => array( 'name' => 'Ana', 'email' => 'ana@example.com', 'phone' => '' ) );
$say( 'pick up 4x4', $s10 );
$r = $say( 'cotízame la nissan frontier y la kia sorento', $s10 );
check( 'varias: un modelo con dos versiones no se pierde', 2, isset( $runner->calls[0] ) ? count( $runner->calls[0][1] ) : 0 );
// Sellos: solo los que el sitio elige (o los que distinguen), con su nombre.
$fbot = new ASI_Dialogue( $shop3, array( 'lang' => 'es', 'language' => 'es', 'card_flags' => array( 'Híbrido' => 'Híbrido', 'Reciente Ingreso' => 'Nuevo ingreso' ) ), function () {
	return 0;
} );
$shop3->add( 9, 'es', 'catalog', 'TOYOTA RAV4 HIBRIDA', array( 'category' => array( 'SUV' ) ), 'SUV.', array(), array( 'price' => '300000', 'flags' => array( 'Stock', 'Nuevo', 'Garantía Activa', 'Híbrido', 'Reciente Ingreso' ) ) );
$r = $fbot->handle( 'toyota rav4 hibrida' );
check( 'sellos: solo los elegidos y con su nombre', array( 'Híbrido', 'Nuevo ingreso' ), $r['cards'][0]['flags'] );

// Pidiendo datos, el cliente pide otra cosa (frase real): se deja la cotización y no se toma como nombre.
$s11 = array();
$say( 'pick up 4x4', $s11 );
$say( 'me interesa el primero', $s11 );
$r = $say( 'suguiereme vehiculos porfavor', $s11 );
check( 'real: "suguiereme vehiculos" no es un nombre', '', $s11['customer']['name'] );
check( 'real: y deja de pedir datos', array(), $s11['pending'] );
$s12 = array();
$say( 'pick up 4x4', $s12 );
$say( 'me interesa el primero', $s12 );
$say( 'Ana López', $s12 );
check( 'nombre: un nombre de verdad sí', 'Ana López', $s12['customer']['name'] );
// La frase real: nombres cortos con "la suv / el pickup", "cotizarme" y "gmw" por GWM.
$runner->calls = array();
$shop3->add( 20, 'es', 'catalog', 'MITSUBISHI OUTLANDER GLX 2023', array( 'category' => array( 'SUV' ) ), 'SUV.', array(), array( 'price' => '169900', 'currency_raw' => '2', 'attrs' => array( 'body' => 'suv', 'year' => 2023 ) ) );
$shop3->add( 21, 'es', 'catalog', 'MITSUBISHI OUTLANDER 2024', array( 'category' => array( 'SUV' ) ), 'SUV.', array(), array( 'price' => '229900', 'currency_raw' => '2', 'attrs' => array( 'body' => 'suv', 'year' => 2024 ) ) );
$shop3->add( 22, 'es', 'catalog', 'GWM POER ELITE', array( 'category' => array( 'Pick up' ) ), 'Pickup.', array(), array( 'price' => '174900', 'currency_raw' => '2', 'attrs' => array( 'body' => 'pickup', 'year' => 2026 ) ) );
$shop3->add( 23, 'es', 'catalog', 'HYUNDAI VENUE', array( 'category' => array( 'SUV' ) ), 'SUV.', array(), array( 'price' => '199990', 'currency_raw' => '2', 'attrs' => array( 'body' => 'suv', 'year' => 2026 ) ) );
$base = array( 'customer' => array( 'name' => 'Ana', 'email' => 'ana@example.com', 'phone' => '' ), 'results' => array( 20, 23, 22, 2 ), 'shown' => array( 21, 20, 23, 22, 2 ) );
$try = function ( $message ) use ( $say, $base, $runner ) {
	$runner->calls = array();
	$st = $base;
	$say( $message, $st );
	return isset( $runner->calls[0] ) ? $runner->calls[0][1] : array();
};
check( 'real: "cotizarme la suv mitsubishi outlander y el pickup gmw poer"', array( 20, 22 ), $try( 'mmm puedes cotizarme la suv mitsubishi outlander y el pickup gmw poer porfavor' ) );
check( 'corto: "la mitsubishi y la venue"', array( 20, 23 ), $try( 'cotízame la mitsubishi y la venue' ) );
check( 'número de tarjeta: "la 1 y la 3"', array( 20, 22 ), $try( 'cotízame la 1 y la 3' ) );
check( 'número suelto con cotizar: "1 y 3"', array( 20, 22 ), $try( 'cotizame 1 y 3' ) );
check( 'por precio: "la de 169 mil y la de 199,990"', array( 20, 23 ), $try( 'quiero cotizar la de 169 mil y la de 199,990' ) );
check( 'por año: "la outlander 2023" (no la 2024)', array( 20 ), $try( 'cotízame la outlander 2023' ) );
check( 'error de dedo: "outlader"', array( 20 ), $try( 'cotizame la outlader' ) );
// Conversación real: cuotas de los dos, "la otra" y "el del pickup".
$runner->calls = array();
$s13 = array( 'customer' => array( 'name' => 'Fernanda', 'email' => 'f@example.com', 'phone' => '' ), 'results' => array( 21, 20, 22, 23 ), 'shown' => array( 21, 20, 22, 23 ) );
$r = $say( 'cotizame con cuotas la suv mitsubishi outlander glx y el pickup gwm poer', $s13 );
check( 'real: cotiza los dos', array( 20, 22 ), isset( $runner->calls[0] ) ? $runner->calls[0][1] : array() );
check( 'real: "con cuotas" trae la tabla de los dos en la misma respuesta', 3, isset( $r['table']['head'] ) ? count( $r['table']['head'] ) : 0 );
check( 'real: recuerda la selección', array( 20, 22 ), $s13['selected'] );
$r = $say( 'Calcular cuotas', $s13 );
check( 'real: "Calcular cuotas" calcula los dos', 3, isset( $r['table']['head'] ) ? count( $r['table']['head'] ) : 0 );
$s13['focus'] = 20;
$r = $say( 'y las cuotas de la otra', $s13 );
check( 'real: "y las cuotas de la otra" es la GWM', 22, $r['cards'][0]['id'] ?? 0 );
$s13['focus'] = 20;
$r = $say( 'y el del pickup??', $s13 );
check( 'real: "y el del pickup??" es la GWM de la selección (no 72 pickups)', array( 'finance', 22 ), array( $r['kind'], $r['cards'][0]['id'] ?? 0 ) );
$r = $say( 'y la de la suv', $s13 );
check( 'real: "y la de la suv" es la Outlander', 20, $r['cards'][0]['id'] ?? 0 );
// "Con cuotas" después de una recomendación (que deja oferta de cotizar) y pidiendo datos primero.
$s14 = array( 'results' => array( 21, 20, 22, 23 ), 'shown' => array( 21, 20, 22, 23 ), 'offer' => array( 'action' => 'quote', 'ids' => array( 21, 20, 22, 23 ) ) );
$say( 'cotizame con cuotas la suv mitsubishi outlander glx y el pickup gwm poer', $s14 );
$r = $say( 'fernanda f@example.com', $s14 );
check( 'real: "con cuotas" tras una oferta y pidiendo datos, igual trae la tabla', 3, isset( $r['table']['head'] ) ? count( $r['table']['head'] ) : 0 );
$r = $say( 'Calcular cuotas', $s14 );
check( 'real: ya cotizados, no vuelve a ofrecer el PDF', array(), $s14['offer'] );
// Frase real: "comparativa de estos vehículos" después de las cuotas de dos.
$s15 = array( 'customer' => array( 'name' => 'Fernanda', 'email' => 'f@example.com', 'phone' => '' ), 'results' => array( 21, 20, 22, 23 ), 'shown' => array( 21, 20, 22, 23 ) );
$say( 'cuánto pagaría al mes por la 2 y la 3', $s15 );
$r = $say( 'puedes darme una comparativa de estos vehiculos', $s15 );
check( 'real: "comparativa de estos vehículos" compara los que estaba viendo', array( 'compare', array( 20, 22 ) ), array( $r['kind'], asi_card_ids( $r ) ) );
check( 'real: y ofrece el PDF de la comparación', true, false !== strpos( $r['followup'], 'PDF' ) );
