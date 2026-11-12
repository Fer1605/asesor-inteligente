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
	public $previews = array();
	public function preview( $action, array $item_ids ) {
		$this->previews[] = array( $action, $item_ids );
		return array( 'url' => 'https://example.com/wp-json/asi/v1/file?t=prev', 'filename' => 'comparativa.pdf' );
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
check( 'comparar: ofrece enviar la comparativa', true, false !== strpos( $r['followup'], 'correo' ) );
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
check( 'real: "comparativa de estos vehículos" compara los que estaba viendo', array( 'compare', array( 20, 22 ) ), array( $r['kind'], $s15['selected'] ) );
check( 'real: y muestra el PDF y ofrece enviarlo', array( true, true ), array( ! empty( $r['file']['url'] ), false !== strpos( $r['followup'], 'correo' ) ) );
// Despedidas (frase real) y que un "gracias" con pedido no corte la conversación.
$s16 = array( 'customer' => array( 'name' => 'Fernanda López', 'email' => 'f@example.com', 'phone' => '' ), 'quoted' => array( 20 ) );
foreach ( array( 'solo eso gracias', 'eso es todo, muchas gracias', 'listo, bye', 'no, nada más, gracias', 'ok perfecto, muchas gracias por la ayuda' ) as $bye ) {
	$st = $s16;
	$r  = $say( $bye, $st );
	check( "cierre: \"{$bye}\"", array( 'smalltalk', true, true ), array( $r['kind'], false !== strpos( $r['text'], 'Fernanda' ), false !== strpos( $r['text'], 'asesor' ) ) );
}
$st = $s16;
$r  = $say( 'gracias, y tienen en color rojo?', $st );
check( 'cierre: "gracias, y en rojo?" sigue buscando', false, false !== strpos( $r['text'], 'Fue un placer' ) );
// Comparar: resumen corto + PDF completo en el chat (sin pedir datos) + oferta de enviarlo.
$runner->calls = array();
$runner->previews = array();
$s17 = array( 'results' => array( 21, 20, 22, 23 ), 'shown' => array( 21, 20, 22, 23 ) );
$r = $say( 'compara la 1 con la 2', $s17 );
check( 'comparar: PDF completo en el chat', 'https://example.com/wp-json/asi/v1/file?t=prev', $r['file']['url'] ?? '' );
check( 'comparar: sin pedir datos ni guardar lead', array( 1, 0 ), array( count( $runner->previews ), count( $runner->calls ) ) );
check( 'comparar: tabla corta (máx. 6 filas)', true, count( $r['compare']['rows'] ) <= 6 );
check( 'comparar: ofrece enviarla al correo', true, false !== strpos( $r['followup'], 'correo' ) );
$say( 'sí', $s17 );
check( 'comparar: "sí" pide los datos para enviarla', array( 'compare', array( 21, 20 ) ), array( $s17['pending']['action'] ?? '', $s17['pending']['ids'] ?? array() ) );
// Conversación real: cotizar 2, cuotas, "comparativa de los carros", "de los que me acabas de cotizar".
$runner->calls = array();
$runner->previews = array();
$s18 = array( 'customer' => array( 'name' => 'Fer', 'email' => 'f@example.com', 'phone' => '' ), 'results' => array( 21, 20, 22, 23 ), 'shown' => array( 21, 20, 22, 23 ) );
$say( 'cotizame la 2 y 3', $s18 );
$say( 'Calcular cuotas', $s18 );
$r = $say( 'me peudes dar na comparativa de los carros porfa', $s18 );
check( 'real: "comparativa de los carros" compara los dos cotizados sin preguntar', array( 'compare', array( 20, 22 ) ), array( $r['kind'], $s18['selected'] ) );
// Si el chat sí tuvo que preguntar, "de los que me acabas de cotizar" responde a esa pregunta.
$s19 = array( 'customer' => array( 'name' => 'Fer', 'email' => 'f@example.com', 'phone' => '' ), 'results' => array( 21 ), 'shown' => array( 21, 20, 22 ), 'quoted' => array( 20, 22 ), 'awaiting' => 'compare' );
$calls_before = count( $runner->calls );
$r = $say( 'de los que me acabas de cotizar', $s19 );
check( 'real: "de los que me acabas de cotizar" compara (no vuelve a cotizar)', array( 'compare', $calls_before ), array( $r['kind'], count( $runner->calls ) ) );
// Si la comparativa ya se mostró en el chat, al enviarla por correo solo se confirma (no se muestra otra vez).
$s20 = array( 'customer' => array( 'name' => 'Fer', 'email' => 'f@example.com', 'phone' => '' ), 'results' => array( 21, 20, 22, 23 ), 'shown' => array( 21, 20, 22, 23 ) );
$r = $say( 'compara la 1 con la 2', $s20 );
check( 'una vez: la comparativa se muestra al comparar', true, ! empty( $r['file']['url'] ) );
$r = $say( 'sí', $s20 );
check( 'una vez: al enviarla no se muestra otra vez', null, $r['file'] ?? null );
check( 'una vez: solo confirma el envío', true, false !== strpos( $r['text'], 'Ya te envié la comparación' ) || false !== strpos( $r['text'], 'ya va en camino' ) );
check( 'una vez: y sigue ofreciendo ayuda', true, '' !== $r['followup'] );

// Comentarios del jefe (10-nov): "de cada una", errores de dedo y preguntar en vez de adivinar.
$four = array( 'results' => array( 21, 20, 22, 23 ), 'shown' => array( 21, 20, 22, 23 ) );
$sj = $four;
$r = $say( 'dame como quedaria las cuotas de cada una', $sj );
check( 'jefe: "las cuotas de cada una" = tabla de las 4 a la vista', array( 'finance', 5 ), array( $r['kind'], isset( $r['table']['head'] ) ? count( $r['table']['head'] ) : 0 ) );
$sj = $four;
$r = $say( 'pero dame la cuoa de cada uno', $sj );
check( 'jefe: "la cuoa de cada uno" = la misma tabla (no una BT-50)', array( 'finance', 5 ), array( $r['kind'], isset( $r['table']['head'] ) ? count( $r['table']['head'] ) : 0 ) );
$sj = $four;
$r = $say( 'cuots de estas', $sj );
check( 'jefe: "cuots de estas" también', 'finance', $r['kind'] );
$sj = array( 'results' => array( 21, 20, 22, 23, 1, 2 ), 'shown' => array( 21, 20, 22, 23, 1, 2 ) );
$r = $say( 'las cuotas de todas', $sj );
check( 'jefe: con más de 4, las 4 primeras', 5, isset( $r['table']['head'] ) ? count( $r['table']['head'] ) : 0 );
check( 'jefe: y ofrece las otras', true, false !== strpos( $r['followup'], 'las otras' ) );
$sj = $four;
$r = $say( 'como es el financiamiento', $sj );
check( 'jefe: "cómo es el financiamiento" con opciones a la vista = cuotas', 'finance', $r['kind'] );
$sj = array();
$r = $say( 'como es el financiamiento', $sj );
check( 'jefe: "cómo es el financiamiento" sin opciones = información', 'info', $r['kind'] );
$sj = $four;
$r = $say( 'quiero la comparatiba de todas', $sj );
check( 'jefe: "la comparatiba de todas" = PDF de las 4', array( 'compare', array( 21, 20, 22, 23 ) ), array( $r['kind'], $sj['selected'] ?? array() ) );
check( 'jefe: y se muestra en el chat', true, ! empty( $r['file']['url'] ) );
$sj = $four;
$r = $say( 'blorf zentu', $sj );
check( 'jefe: sin sentido con opciones a la vista = pregunta', array( 'unclear', array() ), array( $r['kind'], $r['cards'] ) );
check( 'jefe: la pregunta trae botones', true, in_array( 'Cuotas de cada una', $r['suggestions'], true ) && in_array( 'Buscar otra cosa', $r['suggestions'], true ) );
check( 'jefe: queda en aprendizaje', 'none', $r['learn'] ?? '' );
check( 'jefe: conserva las opciones', array( 21, 20, 22, 23 ), $sj['results'] );
$r = $say( 'Cuotas de cada una', $sj );
check( 'jefe: el botón "Cuotas de cada una" funciona', 'finance', $r['kind'] );
$sj = $four;
$say( 'blorf zentu', $sj );
$r = $say( 'Buscar otra cosa', $sj );
check( 'jefe: "Buscar otra cosa" no vuelve a preguntar', true, 'unclear' !== $r['kind'] && ! $sj['results'] );
$sj = $four;
$r = $say( 'nissan frontier', $sj );
check( 'jefe: una búsqueda clara con opciones a la vista sigue buscando', 'results', $r['kind'] );
$sj = array();
$r = $say( 'blorf zentu', $sj );
check( 'jefe: sin opciones a la vista, "no encontré" como antes', 'none', $r['kind'] );
$sj = array_merge( $four, array( 'selected' => array( 20, 22 ) ) );
$r = $say( 'y las cuotas de estos', $sj );
check( 'jefe: "de estos" tras elegir dos = esos dos', 3, isset( $r['table']['head'] ) ? count( $r['table']['head'] ) : 0 );
$sj = array_merge( $four, array( 'selected' => array( 20, 22 ) ) );
$r = $say( 'las cuotas de cada una', $sj );
check( 'jefe: "de cada una" = todas las de la vista', 5, isset( $r['table']['head'] ) ? count( $r['table']['head'] ) : 0 );
$sj = $four;
$say( 'blorf zentu', $sj );
$r = $say( 'Cotizar todas', $sj );
check( 'jefe: el botón "Cotizar todas" cotiza las 4', array( 21, 20, 22, 23 ), $sj['pending']['ids'] ?? ( $sj['quoted'] ?? array() ) );
$sj = $four;
$say( 'blorf zentu', $sj );
$r = $say( 'Comparar todas', $sj );
check( 'jefe: el botón "Comparar todas" compara las 4 en PDF', array( 'compare', 4 ), array( $r['kind'], count( $sj['selected'] ?? array() ) ) );
$sj = $four;
$r = $say( 'dame la cosa esa pues', $sj );
check( 'jefe: "dame la cosa esa pues" con opciones = pregunta', 'unclear', $r['kind'] );
$sj = array( 'results' => array( 21, 20 ), 'shown' => array( 21, 20 ) );
$r = $say( 'pero dame la cuoa de cada uno de la mazda', $sj );
check( 'jefe: "de cada uno" gana aunque se cuele un nombre', 3, isset( $r['table']['head'] ) ? count( $r['table']['head'] ) : 0 );

// Mini chat del 11-nov: tras recomendar o calcular, el chat ofrece cotizar; pedir cuotas sigue siendo cuotas.
$offered = array_merge( $four, array( 'offer' => array( 'action' => 'quote', 'ids' => array( 21, 20, 22, 23 ) ) ) );
$sm = $offered;
$r = $say( 'dame las cuotas de todas', $sm );
check( 'mini chat: "las cuotas de todas" tras ofrecer cotizar = cuotas (no cotización)', array( 'finance', 5, array() ), array( $r['kind'], count( $r['table']['head'] ?? array() ), $sm['pending'] ?? array() ) );
$sm = $offered;
$r = $say( 'queiro asl cuoa de la 3', $sm );
check( 'mini chat: "la cuoa de la 3" tras ofrecer cotizar = cuotas de la 3', array( 'finance', 2, 22 ), array( $r['kind'], count( $r['table']['head'] ?? array() ), $r['cards'][0]['id'] ?? 0 ) );
$sm = $offered;
$r = $say( 'cotizame la 3', $sm );
check( 'mini chat: "cotízame la 3" sigue cotizando', array( 22 ), $sm['pending']['ids'] ?? array() );
$sm = array_merge( $four, array( 'pending' => array( 'action' => 'quote', 'ids' => array( 22 ), 'with' => '' ) ) );
$r = $say( 'dije cuoas', $sm );
check( 'mini chat: "dije cuotas" con la cotización a medias = cuotas de ese vehículo', array( 'finance', 2 ), array( $r['kind'], count( $r['table']['head'] ?? array() ) ) );

// Mini chat 2 (11-nov): "la cotización del 4 con sus cuotas a un 45 % de enganche" → cuotas al 45 %, no al 20 %.
$sm = $four;
$r = $say( 'madname la cotización del 4 con sus cuotas a un 45% de enganche', $sm );
check( 'mini chat 2: pide los datos para cotizar el 4', array( 23 ), $sm['pending']['ids'] ?? array() );
$r = $say( 'fer f@example.com', $sm );
check( 'mini chat 2: con los datos, cotiza y las cuotas van al 45 %', array( 'file', 'Enganche (45 %)' ), array( $r['kind'], $r['table']['rows'][1][0] ?? '' ) );
$sm = array_merge( $four, array( 'customer' => array( 'name' => 'Fer', 'email' => 'f@example.com', 'phone' => '' ) ) );
$r = $say( 'cotizame la 2 con cuotas a 48 meses con 100 mil de enganche', $sm );
check( 'mini chat 2: con datos ya dados, enganche y plazo que pidió', array( 'Q 100,000', '48 meses' ), array( $r['table']['rows'][1][1] ?? '', $r['table']['rows'][2][0] ?? '' ) );

// Memoria de la conversación: lo que pidió de enganche y plazo no se pierde.
$runner->calls = array();
$mem = array_merge( $four, array( 'customer' => array( 'name' => 'Fer', 'email' => 'f@example.com', 'phone' => '' ) ) );
$down_of = function ( $r ) {
	return $r['table']['rows'][1][0] ?? '';
};
$r = $say( 'cuotas de la 1 con 45% de enganche', $mem );
check( 'memoria: pide 45 %', 'Enganche (45 %)', $down_of( $r ) );
$r = $say( 'y las cuotas de la 2', $mem );
check( 'memoria: el siguiente vehículo también al 45 %', 'Enganche (45 %)', $down_of( $r ) );
check( 'memoria: y dice que se acordó', true, false !== strpos( $r['text'], 'como me dijiste' ) );
$r = $say( 'a 48 meses', $mem );
check( 'memoria: cambia el plazo y conserva el 45 %', array( 'Enganche (45 %)', '48 meses', 3 ), array( $down_of( $r ), $r['table']['rows'][2][0] ?? '', count( $r['table']['rows'] ?? array() ) ) );
$r = $say( 'cotizame la 3 con cuotas', $mem );
check( 'memoria: la cotización con cuotas usa 45 % y 48 meses', array( 'file', 'Enganche (45 %)', '48 meses' ), array( $r['kind'], $down_of( $r ), $r['table']['rows'][2][0] ?? '' ) );
$r = $say( 'con 100 mil de enganche', $mem );
check( 'memoria: un monto reemplaza al porcentaje', 'Q 100,000', $r['table']['rows'][1][1] ?? '' );
$r = $say( 'las cuotas de todas', $mem );
check( 'memoria: el monto vale para todas', array( true, true ), array( 0 === strpos( $r['table']['rows'][1][1] ?? '', 'Q 100,000' ), 0 === strpos( $r['table']['rows'][1][2] ?? '', 'Q 100,000' ) ) );
$r = $say( 'Enganche de 30 %', $mem );
check( 'memoria: el botón cambia el enganche', 'Enganche (30 %)', $down_of( $r ) );
$r = $say( 'muestrame las cuotas con todos los plazos', $mem );
check( 'memoria: "todos los plazos" vuelve a la tabla completa con el 30 %', array( 'Enganche (30 %)', 8 ), array( $down_of( $r ), count( $r['table']['rows'] ?? array() ) ) );
$fresh = $four;
$r = $say( 'cuotas de la 1', $fresh );
check( 'memoria: una conversación nueva empieza con el enganche normal', 'Enganche (20 %)', $down_of( $r ) );
$sf = $four;
$say( 'las cuotas de la 4 a un 45% de enganche', $sf );
$r = $say( 'y las de la 2', $sf );
check( 'seguimiento: "y las de la 2" tras cuotas = cuotas de la 2 al 45 % (no una búsqueda)', array( 'finance', 20, 'Enganche (45 %)' ), array( $r['kind'], $r['cards'][0]['id'] ?? 0, $r['table']['rows'][1][0] ?? '' ) );
$r = $say( 'y la tercera?', $sf );
check( 'seguimiento: "y la tercera?" también', array( 'finance', 22 ), array( $r['kind'], $r['cards'][0]['id'] ?? 0 ) );
$r = $say( 'y las de cada una', $sf );
check( 'seguimiento: y después de todas, con el mismo enganche', array( 'finance', 5, 'Enganche (45 %)' ), array( $r['kind'], count( $r['table']['head'] ?? array() ), $r['table']['rows'][1][0] ?? '' ) );
$sf = $four;
$say( 'quiero las cuotas de todos', $sf );
$say( 'las cuotas de la 4 a un 45% de enganche', $sf );
$say( 'y la tercera?', $sf );
$r = $say( 'a 48 meses', $sf );
check( 'seguimiento: "a 48 meses" cambia la última tabla (la tercera), no las 4', array( 2, '48 meses', 22 ), array( count( $r['table']['head'] ?? array() ), $r['table']['rows'][2][0] ?? '', $r['cards'][0]['id'] ?? 0 ) );
$r = $say( 'con 100 mil de enganche', $sf );
check( 'seguimiento: "con 100 mil de enganche" también sobre la última tabla', array( 2, 'Q 100,000' ), array( count( $r['table']['head'] ?? array() ), $r['table']['rows'][1][1] ?? '' ) );

// Botones de enganche: siempre 20, 30, 40 y 50 %, aunque use otro que pidió o recordó.
$chips_of = function ( $r ) {
	return array_values( array_filter( $r['suggestions'] ?? array(), function ( $c ) { return 0 === strpos( $c, 'Enganche de' ); } ) );
};
$basic = array( 'Enganche de 20 %', 'Enganche de 30 %', 'Enganche de 40 %', 'Enganche de 50 %' );
$sc = $four;
$r = $say( 'cuotas de la 1 al 33% de enganche', $sc );
check( 'botones: con 33 % pedido, siguen los 4 básicos', array( 'Enganche (33 %)', $basic ), array( $r['table']['rows'][1][0] ?? '', $chips_of( $r ) ) );
$r = $say( 'y las de la 2', $sc );
check( 'botones: el siguiente vehículo al 33 % y con los 4 básicos', array( 'Enganche (33 %)', $basic ), array( $r['table']['rows'][1][0] ?? '', $chips_of( $r ) ) );
$r = $say( 'Enganche de 20 %', $sc );
check( 'botones: el de 20 % regresa al normal', 'Enganche (20 %)', $r['table']['rows'][1][0] ?? '' );
$sc = $four;
$say( 'cuotas de la 1, tengo 100 mil de enganche', $sc );
$r = $say( 'y las de la 3', $sc );
check( 'monto: "tengo 100 mil" vale para el siguiente vehículo', array( 'Q 100,000', $basic ), array( $r['table']['rows'][1][1] ?? '', $chips_of( $r ) ) );
$sc = $four;
$say( 'cuotas de la 2, tengo 160 mil de enganche', $sc );
$r = $say( 'y las de la 3', $sc );
check( 'monto: si pasa del máximo del sitio, usa el máximo y lo dice', array( 'Q 122,430', true ), array( $r['table']['rows'][1][1] ?? '', false !== strpos( $r['text'], 'máximo' ) ) );
foreach ( array( 'tengo 100 mil para el enganche', 'puedo dar 100 mil como enganche', 'tengo 100 mil de enganche', 'de enganche tengo 100 mil', 'q100,000 para la prima' ) as $phrase ) {
	$sc = $four;
	$say( 'cuotas de la 1', $sc );
	$r = $say( $phrase, $sc );
	check( 'monto: "' . $phrase . '"', 'Q 100,000', $r['table']['rows'][1][1] ?? '' );
}

// Enganche en quetzales o en dólares, con vehículos en dólares (tipo de cambio de referencia 7.7).
$shopU = new ASI_Test_Repository();
$shopU->add( 41, 'es', 'catalog', 'TOYOTA LAND CRUISER', array( 'category' => array( 'SUV' ) ), 'SUV.', array(), array( 'price' => '80000', 'currency_raw' => '1', 'attrs' => array( 'body' => 'suv' ) ) );
$shopU->add( 42, 'es', 'catalog', 'MAZDA CX-5', array( 'category' => array( 'SUV' ) ), 'SUV.', array(), array( 'price' => '300000', 'currency_raw' => '2', 'attrs' => array( 'body' => 'suv' ) ) );
$ubot = new ASI_Dialogue( $shopU, array( 'lang' => 'es', 'language' => 'es', 'site_name' => 'Agencia', 'money' => array( 'base' => 'GTQ', 'map' => array( '1' => 'USD', '2' => 'GTQ' ) ), 'packs' => array( new ASI_Pack_Autos() ), 'actions' => $runner ), function () {
	return 0;
} );
$usay = function ( $message, &$state ) use ( $ubot ) {
	$r     = $ubot->handle( $message, $state );
	$state = $r['state'];
	return $r;
};
$two = array( 'results' => array( 41, 42 ), 'shown' => array( 41, 42 ) );
$su = $two;
$r = $usay( 'cuotas de la 1', $su );
check( 'dólares: el vehículo en dólares da cuotas en dólares', array( '$ 80,000', '$ 16,000' ), array( $r['table']['rows'][0][1] ?? '', $r['table']['rows'][1][1] ?? '' ) );
check( 'dólares: avisa que los montos se toman en quetzales', true, false !== strpos( $r['followup'], 'lo tomo en quetzales' ) );
$r = $usay( 'tengo 154 mil de enganche', $su );
check( 'dólares: Q 154,000 de enganche en un carro en dólares = $ 20,000', '$ 20,000', $r['table']['rows'][1][1] ?? '' );
check( 'dólares: y explica la conversión', true, false !== strpos( $r['text'], 'Q 154,000' ) && false !== strpos( $r['text'], 'Q 7.70 = $ 1' ) );
$r = $usay( 'y las de la 2', $su );
check( 'dólares: el mismo enganche en el carro en quetzales = Q 154,000', 'Q 154,000', $r['table']['rows'][1][1] ?? '' );
$su = $two;
$usay( 'cuotas de la 2', $su );
$r = $usay( 'tengo 20 mil dolares de enganche', $su );
check( 'dólares: "20 mil dólares" en un carro en quetzales = Q 154,000', 'Q 154,000', $r['table']['rows'][1][1] ?? '' );
$r = $usay( 'y las de la 1', $su );
check( 'dólares: lo recuerda en dólares para el carro en dólares', '$ 20,000', $r['table']['rows'][1][1] ?? '' );
$su = $two;
$usay( 'cuotas de la 1', $su );
$r = $usay( 'para el enganche tengo $25,000', $su );
check( 'dólares: "$25,000" se toma en dólares', '$ 25,000', $r['table']['rows'][1][1] ?? '' );
$r = $usay( 'con 25 mil usd de enganche', $su );
check( 'dólares: "25 mil usd" también', '$ 25,000', $r['table']['rows'][1][1] ?? '' );
$sc = $four;
$say( 'cuotas de la 1', $sc );
$r = $say( 'puedo dar 100 mil de entrada', $sc );
check( 'monto: "100 mil de entrada" = enganche', 'Q 100,000', $r['table']['rows'][1][1] ?? '' );
$sc = $four;
$say( 'cuotas de la 1', $sc );
$r = $sc ? $abot->handle( 'de entrada te puedo dar unos 150 y el resto en 5 años', $sc ) : array();
check( 'monto: con el plazo leído y el monto no, se pide ayuda a la IA', array( true, '60 meses' ), array( ! empty( $r['fin_unread'] ), $r['table']['rows'][2][0] ?? '' ) );
$r = $say( 'con 150 de enganche', $sc );
check( 'monto: "150 de enganche" = 150 mil', 'Q 150,000', $r['table']['rows'][1][1] ?? '' );

// Lo que probó el jefe: "cuotas de la 2, 6 y 1" y luego "¿y la 6?".
$shop8 = new ASI_Test_Repository();
foreach ( array( 'NISSAN FRONTIER', 'ISUZU DMAX', 'MAZDA BT50', 'TOYOTA HILUX', 'MITSUBISHI L200', 'FORD RANGER', 'GWM POER', 'JAC T8' ) as $i => $title ) {
	$shop8->add( 101 + $i, 'es', 'catalog', $title, array( 'category' => array( 'Pick up' ), 'brand' => array( strtok( $title, ' ' ) ) ), 'Pickup.', array(), array( 'price' => (string) ( 300000 - $i * 1000 ), 'currency_raw' => '2', 'attrs' => array( 'body' => 'pickup', 'year' => 2026 - $i ) ) );
}
$bot8 = new ASI_Dialogue( $shop8, array( 'lang' => 'es', 'language' => 'es', 'site_name' => 'Agencia', 'money' => array( 'base' => 'GTQ', 'map' => array( '2' => 'GTQ' ) ), 'packs' => array( new ASI_Pack_Autos() ), 'actions' => $runner ), function () {
	return 0;
} );
$say8 = function ( $message, &$state ) use ( $bot8 ) {
	$r     = $bot8->handle( $message, $state );
	$state = $r['state'];
	return $r;
};
$s   = array();
$say8( 'pick up', $s );
$vis = $s['results'];
check( 'lista: hay 8 a la vista', 8, count( $vis ) );
$r = $say8( 'dame las cuotas de la 2, 6 y 1', $s );
check( 'lista: "la 2, 6 y 1" = tres columnas', 4, count( $r['table']['head'] ) );
check( 'lista: en el orden que las pidió', array( $vis[1], $vis[5], $vis[0] ), $s['fin_ids'] );
$s = array();
$say8( 'pick up', $s );
$r = $say8( 'cuotas de la 2 y la 1', $s );
$r = $say8( 'y la 6?', $s );
check( '"¿y la 6?" tras cuotas = cuotas, no cotización', 'finance', $r['kind'] );
check( '"¿y la 6?" suma la 6 a la tabla', array( $vis[1], $vis[0], $vis[5] ), $s['fin_ids'] );
check( '"¿y la 6?" no empieza una cotización', array(), isset( $s['pending'] ) ? (array) $s['pending'] : array() );
$r = $say8( 'cotizame la 6', $s );
check( 'pedir la cotización sí la empieza', true, ! empty( $s['pending']['action'] ) );
foreach ( array( 'cuotas de la 1, 3, 5', 'cuotas de 1, 2 y 3', 'cuotas de la primera, la tercera y la quinta' ) as $m ) {
	$s = array();
	$say8( 'pick up', $s );
	$r = $say8( $m, $s );
	check( "lista: \"$m\" = tres", 3, count( $s['fin_ids'] ) );
}

// Cotizar después de una tabla de cuotas de varias (prueba de la usuaria: tabla de 4 → cotización de 1).
$quote_after = array( 'Envíame la cotización', 'quiero la cotizacion', 'cotizame esas', 'mandame la cotizacion en pdf', 'cotizacion de las 4', 'cotizame las cuatro', 'si', 'dame la cotizacion de todas', 'cotizame estas' );
foreach ( $quote_after as $m ) {
	$s = array();
	$say8( 'pick up', $s );
	$say8( 'dame las cuotas con un enganche de 60 mil de la 1 3 6 y 8', $s );
	$r = $say8( $m, $s );
	check( "tabla de 4 → \"$m\" cotiza las 4", array( $vis[0], $vis[2], $vis[5], $vis[7] ), isset( $s['pending']['ids'] ) ? $s['pending']['ids'] : array() );
}
$s = array();
$say8( 'pick up', $s );
$say8( 'cuotas de la 1 3 6 y 8', $s );
$say8( 'cotizame la 6', $s );
check( 'tabla de 4 → "cotízame la 6" cotiza solo la 6', array( $vis[5] ), isset( $s['pending']['ids'] ) ? $s['pending']['ids'] : array() );
$s = array();
$say8( 'pick up', $s );
$say8( 'cuotas de la 1 3 6 y 8', $s );
$say8( 'cotizame las 2 y 3', $s );
check( 'tabla de 4 → "las 2 y 3" son la 2 y la 3 de la lista', array( $vis[1], $vis[2] ), isset( $s['pending']['ids'] ) ? $s['pending']['ids'] : array() );
$s = array();
$say8( 'pick up', $s );
$say8( 'cotizame las 3', $s );
check( 'sin tabla: "cotízame las 3" = las primeras 3', array( $vis[0], $vis[1], $vis[2] ), isset( $s['pending']['ids'] ) ? $s['pending']['ids'] : array() );
$s = array();
$say8( 'pick up', $s );
$say8( 'cuotas de la 1 y la 2', $s );
$say8( 'pick up', $s );
$say8( 'cotizame la cuarta', $s );
check( 'después de buscar otra vez, la tabla vieja no cuenta', array( $vis[3] ), isset( $s['pending']['ids'] ) ? $s['pending']['ids'] : array() );

// Listas: todas las combinaciones de 2 a 4 opciones de 8, en 8 formas de escribirlas (1,232 casos).
require __DIR__ . '/lists-sweep.inc';

// Más de 4 (lo que pidió el jefe): "1, 2, 5, 6 y 8" = las primeras 4 y la 8 lista para "sí" / "las demás".
$s = array();
$say8( 'pick up', $s );
$r = $say8( 'cuotas de la 1, 2, 5, 6 y 8', $s );
check( 'cinco: tabla con las primeras 4', array( $vis[0], $vis[1], $vis[4], $vis[5] ), $s['fin_ids'] );
check( 'cinco: avisa que falta una', true, false !== strpos( $r['followup'], 'la que falta' ) );
check( 'cinco: botón para verla', 'Ver la que falta', $r['suggestions'][0] );
check( 'cinco: la IA no la cuenta como olvidada', array( $vis[0], $vis[1], $vis[4], $vis[5], $vis[7] ), ASI_AI_Interpreter::rules_action( $r )['ids'] );
$r = $say8( 'Ver la que falta', $s );
check( 'cinco: "ver las otras" = la 8', array( $vis[7] ), $s['fin_ids'] );
$s = array();
$say8( 'pick up', $s );
$say8( 'cuotas 1, 3, 4, 5, 6 y 8', $s );
$r = $say8( 'si', $s );
check( 'seis: "sí" = las 2 que faltaban', array( $vis[6 - 1], $vis[7] ), $s['fin_ids'] );

// La IA revisa acciones con vehículos (IA simulada).
$ord   = ASI_Lexicon::get( 'es' )->get_list( 'ordinals' );
$qw    = ASI_Lexicon::get( 'es' )->get_list( 'quote_words' );
$ph    = ASI_Lexicon::get( 'es' )->get_list( 'ai_phrases' );
$seen  = array( 'results' => $vis );
$fin2  = array( 'kind' => 'finance', 'state' => array( 'fin_ids' => array( $vis[1], $vis[0] ) ) );
$quo   = array( 'kind' => 'smalltalk', 'state' => array( 'pending' => array( 'action' => 'quote', 'ids' => array( $vis[5] ) ) ) );
check( 'acciones: lista de opciones se revisa', true, ASI_AI_Interpreter::action_worth_asking( 'cuotas de la 2, 6 y 1', $fin2, $seen, $qw, $ord ) );
check( 'acciones: una sola opción no gasta', false, ASI_AI_Interpreter::action_worth_asking( 'cuotas de la 2', $fin2, $seen, $qw, $ord ) );
check( 'acciones: cotización que no pidió se revisa', true, ASI_AI_Interpreter::action_worth_asking( 'y la 6?', $quo, $seen, $qw, $ord ) );
check( 'acciones: cotización pedida no gasta', false, ASI_AI_Interpreter::action_worth_asking( 'cotizame la 6', $quo, $seen, $qw, $ord ) );
check( 'acciones: la IA vio más opciones = corrige', true, ASI_AI_Interpreter::action_override( ASI_AI_Interpreter::rules_action( $fin2 ), array( 'action' => 'finance', 'items' => array( 2, 6, 1 ) ), $vis ) );
check( 'acciones: la IA vio menos = no corrige', false, ASI_AI_Interpreter::action_override( ASI_AI_Interpreter::rules_action( $fin2 ), array( 'action' => 'finance', 'items' => array( 2 ) ), $vis ) );
check( 'acciones: la IA vio otras = no corrige', false, ASI_AI_Interpreter::action_override( ASI_AI_Interpreter::rules_action( $fin2 ), array( 'action' => 'finance', 'items' => array( 3, 4, 5 ) ), $vis ) );
check( 'acciones: cotización por error → cuotas', true, ASI_AI_Interpreter::action_override( ASI_AI_Interpreter::rules_action( $quo ), array( 'action' => 'finance', 'items' => array( 6 ) ), $vis ) );
// Completo: las reglas (simuladas) vieron 2 y 1; la IA, 2, 6 y 1 → tabla con tres.
$s = array();
$say8( 'pick up', $s );
$titles8 = array();
foreach ( $shop8->rows( 'es', $vis ) as $row ) {
	$titles8[ array_search( (int) $row['post_id'], $vis, true ) ] = $row['title'];
}
ksort( $titles8 );
$ask = function () {
	return array( 'text' => '{"action":"finance","items":[2,6,1]}', 'model' => 'fake' );
};
$out = ASI_AI_Interpreter::review_action( $bot8, 'cuotas de la 2, 6 y 1', $s, $fin2, array_values( $titles8 ), array( 'finance', 'quote', 'compare', 'detail' ), $ph, $ask );
check( 'acciones: corregida con la IA = tres columnas', 4, count( $out['response']['table']['head'] ) );
