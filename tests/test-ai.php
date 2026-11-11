<?php
// Interpretación con IA (opcional): la IA solo elige una orden; el plugin responde con sus datos.
// Se prueba con una IA simulada: no sale nada a internet.

$fake = function ( $reply ) {
	$asked = (object) array( 'prompt' => '' );
	$fn    = function ( $prompt ) use ( $reply, $asked ) {
		$asked->prompt = $prompt;
		return null === $reply ? null : array( 'text' => $reply, 'model' => 'fake:model' );
	};
	return array( $fn, $asked );
};
$es_phrases = ASI_Lexicon::get( 'es' )->get_list( 'ai_phrases' );
$en_phrases = ASI_Lexicon::get( 'en' )->get_list( 'ai_phrases' );
$acts       = array( 'finance', 'quote', 'compare', 'detail' );
$titles4    = array( 'NISSAN FRONTIER', 'ISUZU D-MAX', 'MAZDA BT-50', 'TOYOTA HILUX' );

// Cuándo entra la IA.
check( 'ia: entra cuando no entiende', true, ASI_AI_Interpreter::needs_help( array( 'kind' => 'unclear' ) ) );
check( 'ia: entra cuando no encontró nada', true, ASI_AI_Interpreter::needs_help( array( 'kind' => 'none' ) ) );
check( 'ia: no entra cuando ya respondió', false, ASI_AI_Interpreter::needs_help( array( 'kind' => 'results' ) ) );

// Datos personales: nunca salen.
$clean = ASI_AI_Interpreter::scrub( 'soy ana@example.com mi cel 5555-1234 o +502 2222 3333 ve https://x.com/a' );
check( 'ia: quita correos, teléfonos y enlaces', false, (bool) preg_match( '/@|5555|2222|https/', $clean ) );
check( 'ia: deja el resto del mensaje', true, false !== strpos( $clean, 'mi cel' ) );

// Lo que devuelve la IA se valida.
check( 'ia: lee JSON con texto alrededor', array( 'action' => 'finance', 'items' => 'all', 'query' => '', 'topic' => '' ), ASI_AI_Interpreter::parse( "Claro:\n{\"action\":\"finance\",\"items\":\"all\"}", 4, $acts ) );
check( 'ia: números de opciones válidos', array( 1, 3 ), ASI_AI_Interpreter::parse( '{"action":"quote","items":[1,3,9]}', 4, $acts )['items'] );
check( 'ia: opciones que no existen se descartan', 'all', ASI_AI_Interpreter::parse( '{"action":"finance","items":[7,9]}', 4, $acts )['items'] );
check( 'ia: todas las opciones = all', 'all', ASI_AI_Interpreter::parse( '{"action":"finance","items":[1,2,3,4]}', 4, $acts )['items'] );
check( 'ia: acción inventada = nada', null, ASI_AI_Interpreter::parse( '{"action":"discount","items":"all"}', 4, $acts ) );
check( 'ia: acción que el sitio no tiene = nada', null, ASI_AI_Interpreter::parse( '{"action":"quote","items":"all"}', 4, array( 'finance' ) ) );
check( 'ia: texto sin JSON = nada', null, ASI_AI_Interpreter::parse( 'No entiendo', 4, $acts ) );
check( 'ia: "none" = nada', null, ASI_AI_Interpreter::parse( '{"action":"none"}', 4, $acts ) );
check( 'ia: cuotas sin opciones = info de financiamiento', array( 'info', 'financing' ), array_values( array_intersect_key( ASI_AI_Interpreter::parse( '{"action":"finance","items":"all"}', 0, $acts ), array( 'action' => 1, 'topic' => 1 ) ) ) );
check( 'ia: cotizar sin opciones = nada', null, ASI_AI_Interpreter::parse( '{"action":"quote","items":"all"}', 0, $acts ) );
check( 'ia: comparar una sola = todas', 'all', ASI_AI_Interpreter::parse( '{"action":"compare","items":[2]}', 4, $acts )['items'] );
check( 'ia: detalle de una', array( 2 ), ASI_AI_Interpreter::parse( '{"action":"detail","items":[2,3]}', 4, $acts )['items'] );
check( 'ia: tema de info desconocido = nada', null, ASI_AI_Interpreter::parse( '{"action":"info","topic":"precios"}', 4, $acts ) );
check( 'ia: la búsqueda no lleva teléfonos ni signos', 'pickup diesel 4x4', ASI_AI_Interpreter::parse( '{"action":"search","query":"pickup <b>diesel</b> 4x4 55551234"}', 0, $acts )['query'] );
check( 'ia: búsqueda vacía = nada', null, ASI_AI_Interpreter::parse( '{"action":"search","query":"!!!"}', 0, $acts ) );

// Frases que las reglas ya entienden.
check( 'ia: frase cuotas de todas', 'cuotas de cada una', ASI_AI_Interpreter::phrase( array( 'action' => 'finance', 'items' => 'all' ), $es_phrases ) );
check( 'ia: frase cotizar 1 y 3', 'cotizar la 1 y la 3', ASI_AI_Interpreter::phrase( array( 'action' => 'quote', 'items' => array( 1, 3 ) ), $es_phrases ) );
check( 'ia: frase en inglés', 'payments for the 2', ASI_AI_Interpreter::phrase( array( 'action' => 'finance', 'items' => array( 2 ) ), $en_phrases ) );

// Las instrucciones llevan las opciones a la vista y el mensaje limpio.
$prompt = ASI_AI_Interpreter::prompt( 'cuanto sale al mes', $titles4, $acts, 'Agencia' );
check( 'ia: instrucciones con las opciones numeradas', true, false !== strpos( $prompt, '3. MAZDA BT-50' ) );
check( 'ia: instrucciones con el mensaje', true, false !== strpos( $prompt, '"""cuanto sale al mes"""' ) );
check( 'ia: sin cotizar si el sitio no cotiza', false, false !== strpos( ASI_AI_Interpreter::prompt( 'x', $titles4, array( 'finance' ) ), 'quote:' ) );

// De punta a punta con el diálogo real (IA simulada).
$sa = $four;
$first = $abot->handle( 'blorf zentu', $sa );
check( 'ia e2e: las reglas no entienden', 'unclear', $first['kind'] );
list( $ask, $asked ) = $fake( '{"action":"finance","items":"all","query":"","topic":""}' );
$res = ASI_AI_Interpreter::rescue( $abot, 'blorf zentu', $sa, $titles4, $acts, $es_phrases, $ask, 'Agencia' );
check( 'ia e2e: cuotas de las 4 con los datos del plugin', array( 'finance', 5 ), array( $res['response']['kind'], count( $res['response']['table']['head'] ?? array() ) ) );
check( 'ia e2e: guarda la frase que funcionó', 'cuotas de cada una', $res['phrase'] );
check( 'ia e2e: y quién la interpretó', 'fake:model', $res['model'] );

list( $ask ) = $fake( '{"action":"finance","items":[2,4]}' );
$res = ASI_AI_Interpreter::rescue( $abot, 'blorf zentu', $four, $titles4, $acts, $es_phrases, $ask );
check( 'ia e2e: cuotas de la 2 y la 4', array( 'finance', 3 ), array( $res['response']['kind'] ?? '', count( $res['response']['table']['head'] ?? array() ) ) );

list( $ask ) = $fake( '{"action":"quote","items":[1,3]}' );
$sq = $four;
$res = ASI_AI_Interpreter::rescue( $abot, 'blorf zentu', $sq, $titles4, $acts, $es_phrases, $ask );
check( 'ia e2e: cotizar la 1 y la 3 pide los datos de esas dos', array( $four['results'][0], $four['results'][2] ), $res['response']['state']['pending']['ids'] ?? array() );

list( $ask ) = $fake( '{"action":"compare","items":"all"}' );
$res = ASI_AI_Interpreter::rescue( $abot, 'blorf zentu', $four, $titles4, $acts, $es_phrases, $ask );
check( 'ia e2e: comparar todas', 'compare', $res['response']['kind'] ?? '' );

list( $ask ) = $fake( '{"action":"detail","items":[3]}' );
$res = ASI_AI_Interpreter::rescue( $abot, 'blorf zentu', $four, $titles4, $acts, $es_phrases, $ask );
check( 'ia e2e: detalle de la 3', array( 'detail', $four['results'][2] ), array( $res['response']['kind'] ?? '', $res['response']['cards'][0]['id'] ?? 0 ) );

list( $ask ) = $fake( '{"action":"search","query":"isuzu"}' );
$res = ASI_AI_Interpreter::rescue( $abot, 'algo como de la marca japonesa esa de los camiones', array(), array(), $acts, $es_phrases, $ask );
check( 'ia e2e: búsqueda interpretada', 'results', $res['response']['kind'] ?? '' );

list( $ask ) = $fake( '{"action":"none"}' );
$res = ASI_AI_Interpreter::rescue( $abot, 'blorf zentu', $four, $titles4, $acts, $es_phrases, $ask );
check( 'ia e2e: si la IA tampoco entiende, se queda la pregunta', null, $res['response'] );

list( $ask ) = $fake( null );
check( 'ia e2e: si la IA no responde, se queda la pregunta', null, ASI_AI_Interpreter::rescue( $abot, 'blorf zentu', $four, $titles4, $acts, $es_phrases, $ask ) );

list( $ask ) = $fake( '{"action":"search","query":"blorf zentu"}' );
$res = ASI_AI_Interpreter::rescue( $abot, 'blorf zentu', array(), array(), $acts, $es_phrases, $ask );
check( 'ia e2e: si la IA repite el mensaje, no se acepta', null, $res['response'] );

list( $ask, $asked ) = $fake( '{"action":"none"}' );
ASI_AI_Interpreter::rescue( $abot, 'mi correo es ana@example.com y quiero algo', array(), array(), $acts, $es_phrases, $ask );
check( 'ia e2e: a la IA no le llega el correo', false, false !== strpos( $asked->prompt, 'ana@example.com' ) );
