<?php
// --- Textos con nombre de la ficha ("Garantía: 10 años") y nombres propios del negocio (sinónimos del sitio).
$notes_lot = new ASI_Test_Repository();
$notes_lot->add( 41, 'es', 'catalog', 'Delta Ranger 2027', array( 'category' => array( 'SUV' ) ), 'SUV nueva.', array(), array( 'price' => '399990', 'currency_raw' => '2' ) );
$notes_lot->add( 42, 'es', 'catalog', 'Garza Rover 2021', array( 'category' => array( 'SUV' ) ), 'SUV usada.', array(), array( 'price' => '189990', 'currency_raw' => '2' ) );
$notes_lot->add( 43, 'es', 'catalog', 'Garza Pickup 2020', array( 'category' => array( 'Pick up' ) ), 'Pick up usada.', array(), array( 'price' => '159990', 'currency_raw' => '2' ) );
$notes_lot->set_details( 41, array( array( 'Motor', 'Híbrido' ), array( 'Puertas', '4' ) ), null, array( array( 'Garantía', '10 años ó 200,000 kms' ) ) );
$notes_lot->set_details( 42, array( array( 'Motor', 'Gasolina' ) ), null, array( array( 'Información Extra', 'Servicio de mantenimiento, Polarizado, Limpieza total' ) ) );
$notes_lot->set_details( 43, array( array( 'Motor', 'Diésel' ) ), null, array( array( 'Garantía', 'En motor y caja por 30 días' ), array( 'Información Extra', 'Servicio de frenos' ) ) );

$notes_bot = function ( array $synonyms = array() ) use ( $notes_lot ) {
	return new ASI_Dialogue( $notes_lot, array( 'lang' => 'es', 'language' => 'es', 'site_name' => 'Agencia', 'synonyms' => $synonyms, 'money' => array( 'base' => 'GTQ', 'map' => array( '2' => 'GTQ' ) ) ), function () {
		return 0;
	} );
};
$notes_state = function ( $id ) {
	return array( 'results' => array( $id ), 'shown' => array( $id ), 'focus' => $id );
};

$r = $notes_bot()->handle( 'que garantía tiene', $notes_state( 41 ) );
check( 'notas: garantía de un texto con nombre', array( 'fact', array( 'Garantía: 10 años ó 200,000 kms' ) ), array( $r['kind'], $r['quote'] ) );
check( 'notas: no dice que no lo encontró', false, false !== strpos( $r['text'], 'No encontré' ) );

$r = $notes_bot()->handle( 'que garantía tiene', $notes_state( 42 ) );
check( 'notas: sin sinónimos, un usado sin garantía no inventa', array(), $r['quote'] );

$house = array( array( 'Sello de la Casa', 'información extra', 'garantía' ) );
$r = $notes_bot( $house )->handle( 'tiene sello de la casa', $notes_state( 42 ) );
check( 'notas: nombre propio del negocio → su campo', array( 'Sello de la Casa: Servicio de mantenimiento, Polarizado, Limpieza total' ), $r['quote'] );
$r = $notes_bot( $house )->handle( 'y el sello de la casa?', $notes_state( 42 ) );
check( 'notas: nombre propio sin verbo', array( 'Sello de la Casa: Servicio de mantenimiento, Polarizado, Limpieza total' ), $r['quote'] );
$r = $notes_bot( $house )->handle( 'que garantía tiene', $notes_state( 42 ) );
check( 'notas: garantía de un usado sin garantía → el sinónimo del negocio', array( 'Sello de la Casa: Servicio de mantenimiento, Polarizado, Limpieza total' ), $r['quote'] );
$r = $notes_bot( $house )->handle( 'que garantía tiene', $notes_state( 43 ) );
check( 'notas: si tiene garantía, responde la garantía', array( 'Garantía: En motor y caja por 30 días' ), $r['quote'] );
$r = $notes_bot( $house )->handle( 'que garantía tiene', $notes_state( 41 ) );
check( 'notas: el sinónimo no cambia la garantía de un nuevo', array( 'Garantía: 10 años ó 200,000 kms' ), $r['quote'] );
$r = $notes_bot( $house )->handle( 'cuantas puertas tiene', $notes_state( 41 ) );
check( 'notas: los demás datos siguen igual', 'fact', $r['kind'] );
check( 'notas: puertas', false, false === strpos( $r['text'], '4 puertas' ) );

$r = $notes_bot( $house )->handle( 'tiene sello de la casa', $notes_state( 41 ) );
check( 'notas: nombre del negocio en un nuevo → su garantía, con su nombre', array( 'Garantía: 10 años ó 200,000 kms' ), $r['quote'] );
