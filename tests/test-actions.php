<?php
// Cerrar la venta en el chat: cuotas, cotización y comparación en PDF.
require_once dirname( __DIR__ ) . '/packs/autos/class-asi-pack-autos.php';

final class ASI_Test_Runner implements ASI_Action_Runner {
	public $calls = array();
	public $fail  = false;
	public function available() {
		return array(
			'quote'   => array( 'label' => 'Cotización', 'fields' => array( 'name', 'email', 'phone' ) ),
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
check( 'cuotas: tabla de plazos', 6, count( $r['table']['rows'] ) );
$expected = ( 0.0075 * $price_of[ $first ] * 0.8 ) / ( 1 - pow( 1.0075, -60 ) );
check( 'cuotas: 60 meses con la fórmula', 'Q ' . number_format( round( $expected ), 0, '.', ',' ), $r['table']['rows'][4][1] );
check( 'cuotas: dice el enganche', true, false !== strpos( $r['text'], ASI_Money::format( round( $price_of[ $first ] * 0.2 ), 'GTQ' ) ) );
check( 'cuotas: ofrece la cotización', 'quote', $s['offer']['action'] );
$r = $say( 'con 100 mil de enganche a 48 meses', $s );
check( 'cuotas: enganche y plazo que pidió', true, false !== strpos( $r['text'], 'Q 100,000' ) && false !== strpos( $r['text'], '48 meses' ) );
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
