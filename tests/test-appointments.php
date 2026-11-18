<?php
// "Quiero agendar una cita": sin calendario. Apagado da los datos de contacto; encendido toma la
// solicitud (nombre, correo y cuándo) y la pasa a los asesores.
final class ASI_Test_Appointments {
	public $on    = true;
	public $calls = array();
	public function enabled() {
		return $this->on;
	}
	public function submit( array $customer, $when, $item_id = 0 ) {
		$this->calls[] = array( $customer, $when, $item_id );
		return array( 'test' => false );
	}
}

$appts = new ASI_Test_Appointments();
$ashop = new ASI_Test_Repository();
$ashop->add( 1, 'es', 'catalog', 'MAZDA BT-50', array( 'category' => array( 'Pick up' ) ), 'Pickup.', array(), array( 'price' => '344900' ) );
$ashop->add( 2, 'es', 'catalog', 'NISSAN FRONTIER', array( 'category' => array( 'Pick up' ) ), 'Pickup.', array(), array( 'price' => '300000' ) );
$acontact = array( 'on_closing' => true, 'items' => array( array( 'type' => 'phone', 'value' => '+502 0000-0000', 'url' => 'tel:+50200000000' ) ), 'page' => '' );
$apbot = new ASI_Dialogue( $ashop, array( 'lang' => 'es', 'language' => 'es', 'site_name' => 'Agencia', 'appointments' => $appts, 'contact' => $acontact ), function () {
	return 0;
} );
$asay = function ( $message, &$state ) use ( $apbot ) {
	$r     = $apbot->handle( $message, $state );
	$state = $r['state'];
	return $r;
};

// Encendido: pide los datos, sin prometer fecha.
$st = array();
$r  = $asay( 'quiero agendar una cita', $st );
check( 'cita: no promete fecha ni hora', true, false !== strpos( $r['text'], 'no agendo fecha ni hora' ) );
check( 'cita: pide nombre, correo y cuándo', true, false !== strpos( $r['text'], 'tu nombre' ) && false !== strpos( $r['text'], 'tu correo' ) && false !== strpos( $r['text'], 'qué día' ) );
check( 'cita: deja los datos de contacto a la mano', true, ! empty( $r['contact']['items'] ) );
$r = $asay( 'Luis de leon, luis.prueba@example.com, el sábado en la tarde', $st );
check( 'cita: con todo en un mensaje, la envía', array( 'Luis de Leon', 'luis.prueba@example.com', 'sábado en la tarde' ), $appts->calls ? array( $appts->calls[0][0]['name'], $appts->calls[0][0]['email'], $appts->calls[0][1] ) : array() );
check( 'cita: confirma que la pasó a los asesores', true, false !== strpos( $r['text'], 'asesores' ) && false !== strpos( $r['text'], 'luis.prueba@example.com' ) );
check( 'cita: ya no queda pendiente', array(), $st['pending'] );

// Por partes: el cuándo en la primera frase, luego correo, luego nombre.
$appts->calls = array();
$st = array();
$r  = $asay( 'me gustaria una cita para el viernes a las 10', $st );
check( 'cita: toma el cuándo de la primera frase', 'viernes a las 10', $st['appt'] );
$r = $asay( 'ana@example.com', $st );
check( 'cita: con el correo, pide el nombre', true, false !== strpos( $r['text'], 'nombre' ) );
$r = $asay( 'Ana María de la Cruz', $st );
check( 'cita: envía con nombre compuesto', array( 'Ana María de la Cruz', 'viernes a las 10' ), $appts->calls ? array( $appts->calls[0][0]['name'], $appts->calls[0][1] ) : array() );

// Si falta solo el cuándo, lo que responda es el cuándo.
$appts->calls = array();
$st = array( 'customer' => array( 'name' => 'Ana', 'email' => 'ana@example.com', 'phone' => '' ) );
$r  = $asay( 'quiero una cita', $st );
check( 'cita: con nombre y correo ya dados, solo pregunta cuándo', true, false !== strpos( $r['text'], 'qué día' ) && false === strpos( $r['text'], 'tu correo' ) );
$r = $asay( 'cuando puedan', $st );
check( 'cita: "cuando puedan" es el cuándo', 'cuando puedan', $appts->calls ? $appts->calls[0][1] : '' );

// Con un vehículo en pantalla, la solicitud lo lleva.
$appts->calls = array();
$st = array();
$asay( 'mazda bt-50', $st );
$focus = (int) $st['focus'] ? (int) $st['focus'] : (int) $st['results'][0];
$st['focus'] = $focus;
$asay( 'quiero una prueba de manejo', $st );
$asay( 'Ana López, ana@example.com, mañana en la mañana', $st );
check( 'cita: lleva el vehículo que estaba viendo', $focus, $appts->calls ? $appts->calls[0][2] : -1 );

// Cancelar.
$appts->calls = array();
$st = array();
$asay( 'quiero agendar una cita', $st );
$r = $asay( 'cancelar', $st );
check( 'cita: se puede cancelar', array( array(), 0 ), array( $st['pending'], count( $appts->calls ) ) );

// Pide otra cosa a medio camino: se deja la cita.
$st = array();
$asay( 'quiero agendar una cita', $st );
$r = $asay( 'mejor muéstrame pickups', $st );
check( 'cita: si pide otra cosa, se deja la cita', array( array(), 'results' ), array( $st['pending'], $r['kind'] ) );

// Apagado: nunca se queda mudo; da los datos de contacto.
$appts->on = false;
$st = array();
$r  = $asay( 'quiero agendar una cita', $st );
check( 'cita apagada: lo dice y da el contacto', true, false !== strpos( $r['text'], 'no agendo citas' ) && ! empty( $r['contact']['items'] ) );
check( 'cita apagada: no pide datos', array(), $st['pending'] );
