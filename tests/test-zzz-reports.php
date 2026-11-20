<?php
// Reportería: rangos de presupuesto y cómo se nombra cada campo de una búsqueda del chat.
// (Corre después de test-zz-sheet.php: usa $yard3, $money y $autos.)

$bucket = function ( $min, $max, $cur = 'GTQ' ) {
	return ASI_Search_Sheet::budget_bucket( array_merge( ASI_Search_Sheet::blank(), array( 'min' => $min, 'max' => $max, 'currency' => $cur ) ) );
};
check( 'reportes: 225 mil y 300 mil caen en el mismo rango', array( 'Hasta Q 300,000', 'Hasta Q 300,000' ), array( $bucket( null, 225000.0 ), $bucket( null, 300000.0 ) ) );
check( 'reportes: rango en otra escala y moneda', array( 'Hasta $100,000', 'Hasta $40,000', 'Desde Q 200,000', '' ), array( $bucket( null, 95000.0, 'USD' ), $bucket( null, 35000.0, 'USD' ), $bucket( 250000.0, null ), $bucket( null, null ) ) );

$rbot = new ASI_Dialogue( $yard3, array( 'lang' => 'es', 'language' => 'es', 'site_name' => 'Agencia', 'money' => $money, 'packs' => array( $autos ) ), function () {
	return 0;
} );
check( 'reportes: también sin conversación (búsquedas del sitio)', array( array( 'dim' => 'attr:body', 'label' => 'SUV' ) ), $rbot->report_criteria( 'suv' ) );
$rbot->handle( 'hola' );
$crit = $rbot->report_criteria( 'camioneta toyota o honda de gasolina hasta 225000' );
$pairs = array();
foreach ( $crit as $c ) {
	$pairs[] = $c['dim'] . '=' . $c['label'];
}
sort( $pairs );
check( 'reportes: marca, tipo, combustible y presupuesto, con su nombre para mostrar', array( 'attr:body=SUV', 'attr:fuel=Gasolina', 'budget=Hasta Q 300,000', 'term:brand=Honda', 'term:brand=Toyota' ), $pairs );
check( 'reportes: búsqueda vacía', array(), $rbot->report_criteria( '' ) );

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return trim( strip_tags( (string) $text ) );
	}
}
// Registro de conversaciones: lo que escribe mientras el chat pide sus datos no se guarda.
$contact_turn = ASI_Chat_Log::turn( 'Ana López, ana@example.com', array( 'pending' => array( 'action' => 'appointment', 'ids' => array( 1 ) ) ), array( 'kind' => 'appointment', 'text' => 'Gracias, Ana.', 'state' => array() ) );
check( 'registro: el mensaje de contacto no se guarda', '[datos de contacto]', $contact_turn['u'] );
check( 'registro: la respuesta con su nombre tampoco', '', $contact_turn['r'] );
$normal_turn = ASI_Chat_Log::turn( 'una pickup diésel', array( 'last' => array( 'x' ) ), array( 'kind' => 'results', 'text' => 'Tengo 3 opciones.', 'state' => array() ) );
check( 'registro: un turno normal sí se guarda (y un estado raro no rompe nada)', 'una pickup diésel', $normal_turn['u'] );
