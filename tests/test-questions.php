<?php
// --- Preguntas generales del negocio, términos que pocos productos indican y la IA.
$q_lot = new ASI_Test_Repository();
$q_lot->add( 1, 'es', 'info', 'Preguntas Frecuentes', array(), '¿Qué documentos necesito para comprar un auto? Documento de identificación, recibo de luz y comprobante de ingresos. ¿Cuánto tarda el proceso? De 3 a 7 días.' );
$q_lot->add( 2, 'es', 'info', 'Contáctanos', array(), 'Escríbenos o llámanos al teléfono de la agencia.' );
$q_lot->add( 10, 'es', 'catalog', 'Delta Ranger', array( 'category' => array( 'SUV', 'Blanco' ) ), 'SUV.', array(), array( 'price' => '250000', 'currency_raw' => '2' ) );
$q_lot->add( 11, 'es', 'catalog', 'Garza Rover', array( 'category' => array( 'SUV', 'Negro' ) ), 'SUV.', array(), array( 'price' => '230000', 'currency_raw' => '2' ) );
$q_lot->add( 12, 'es', 'catalog', 'Delta Nova', array( 'category' => array( 'SUV' ) ), 'SUV.', array(), array( 'price' => '210000', 'currency_raw' => '2' ) );
$q_lot->add( 13, 'es', 'catalog', 'Garza Poder', array( 'category' => array( 'Pick up' ) ), 'Pick up.', array(), array( 'price' => '200000', 'currency_raw' => '2' ) );
$q_sparse = array( 'terms' => array( 'blanco' => 'color', 'negro' => 'color' ), 'tax' => array( 'color' => array( 'label' => 'color', 'ids' => array( 10, 11 ) ) ) );
$q_bot    = function () use ( $q_lot, $q_sparse ) {
	return new ASI_Dialogue( $q_lot, array( 'lang' => 'es', 'language' => 'es', 'site_name' => 'Agencia', 'sparse' => $q_sparse, 'money' => array( 'base' => 'GTQ', 'map' => array( '2' => 'GTQ' ) ) ), function () {
		return 0;
	} );
};

// Requisitos: la página que lo dice, no un producto ni cuotas.
$r = $q_bot()->handle( '¿Qué necesitan para que yo pueda adquirir un carro?' );
check( 'requisitos: es información', 'info', $r['kind'] );
check( 'requisitos: cita lo que se necesita', true, (bool) preg_grep( '/identificaci/u', (array) ( $r['quote'] ?? array() ) ) );
$r = $q_bot()->handle( 'Necesito saber que requisitos necesitan para poder adquirir un carro' );
check( 'requisitos: "poder" no se corrige a un modelo', array( 'info', array() ), array( $r['kind'], asi_card_ids( $r ) ) );
$r = $q_bot()->handle( 'que beneficios obtengo al adquirir un carro con ustedes?' );
check( 'beneficios: no muestra productos', array(), asi_card_ids( $r ) );
check( 'beneficios: sin página, manda a contacto', true, in_array( 'https://example.test/es/2', asi_link_urls( $r ), true ) );

// Un término que pocos productos indican: se avisa y se ofrece ver los que no lo indican.
$r = $q_bot()->handle( 'suv blanca' );
check( 'color: primero los de ese color', array( 10 ), asi_card_ids( $r ) );
check( 'color: aparte, los que no indican color (no los de otro color)', array( 12 ), array_column( $r['extra']['cards'], 'id' ) );
check( 'color: con su aclaración aparte', true, false !== strpos( $r['note'], 'varios colores' ) );
$r = $q_bot()->handle( 'suv' );
check( 'color: sin color pedido no hay aclaración', '', $r['note'] ?? '' );

// La IA: unas cuotas que la pregunta no pide no se calculan; con un mensaje ilegible, sí se interpreta.
check( 'ia: "¿qué necesito para adquirir?" no son cuotas', true, ASI_AI_Interpreter::unasked_finance( 'que necesitan para que yo pueda adquirir un carro', 'como es el financiamiento' ) );
check( 'ia: si habla de cuotas, sí', false, ASI_AI_Interpreter::unasked_finance( 'cuanto pagaria al mes por ese', 'cuotas de la 1' ) );
check( 'ia: mensaje ilegible, la IA decide', false, ASI_AI_Interpreter::unasked_finance( 'blorf zentu', 'cuotas de cada una' ) );
$q_an = new ASI_Query_Analyzer( ASI_Lexicon::get( "es" ), $q_lot->vocabulary( "es" ) );
check( 'ia: el color que la IA dejó fuera vuelve', array( 'blanco' ), ASI_AI_Interpreter::dropped_terms( $q_an, 'suv blanca', 'suv' ) );
check( 'ia: si la IA lo dejó, nada que agregar', array(), ASI_AI_Interpreter::dropped_terms( $q_an, 'suv blanca', 'suv blanco' ) );

// "Peor" (por "pero") no es un modelo del catálogo que se le parece.
$q_lot->add( 14, 'es', 'catalog', 'Garza Pear', array( 'category' => array( 'Pick up' ) ), 'Pick up.', array(), array( 'price' => '190000', 'currency_raw' => '2' ) );
$r = $q_bot()->handle( 'peor no quiero garza' );
check( 'corrector: "peor" no se vuelve un modelo', false, in_array( 14, asi_card_ids( $r ), true ) && 1 === count( asi_card_ids( $r ) ) );

// Garantía del negocio ("¿tienen garantía?") contra la de un producto que está viendo ("¿qué garantía tiene?").
$q_lot->add( 3, 'es', 'info', 'Garantías', array(), '¿Qué garantía ofrecen los autos usados? Garantía en motor y caja por 30 días o 1,000 km.' );
$r = $q_bot()->handle( 'tienen garantía? y por cuanto tiempo?' );
check( 'garantía sin nada a la vista: que sí y que varía según cuál', array( 'info', array() ), array( $r['kind'], asi_card_ids( $r ) ) );
$q_lot->set_details( 11, array( array( 'Motor', 'Diésel' ) ), null, array( array( 'Garantía', '3 años' ) ) );
$r = $q_bot()->handle( 'tienen garantía? y por cuanto tiempo?', array( 'results' => array( 10, 11 ), 'shown' => array( 10, 11 ) ) );
check( 'garantía con varios a la vista: la de cada uno', 2, count( $r['quote'] ?? array() ) );
$q_lot->set_details( 10, array( array( 'Motor', 'Gasolina' ) ), null, array( array( 'Garantía', '5 años' ) ) );
$r = $q_bot()->handle( 'que garantía tiene', array( 'results' => array( 10 ), 'shown' => array( 10 ), 'focus' => 10 ) );
check( 'garantía de lo que ve: su ficha', array( 'Garantía: 5 años' ), $r['quote'] ?? array() );
$r = $q_bot()->handle( 'tienen garantía? y por cuanto tiempo?', array( 'results' => array( 10 ), 'shown' => array( 10 ) ) );
check( 'garantía con uno a la vista (aunque no lo nombre): la de ese', array( 'Garantía: 5 años' ), $r['quote'] ?? array() );
