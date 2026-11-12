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
check( 'ia: lee JSON con texto alrededor', array( 'action' => 'finance', 'items' => 'all', 'query' => '', 'topic' => '', 'fin' => array() ), ASI_AI_Interpreter::parse( "Claro:\n{\"action\":\"finance\",\"items\":\"all\"}", 4, $acts ) );
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
$res = ASI_AI_Interpreter::rescue( $abot, 'kotisame blorf zentu', $sq, $titles4, $acts, $es_phrases, $ask );
check( 'ia e2e: cotizar la 1 y la 3 pide los datos de esas dos', array( $four['results'][0], $four['results'][2] ), $res['response']['state']['pending']['ids'] ?? array() );

list( $ask ) = $fake( '{"action":"compare","items":"all"}' );
$res = ASI_AI_Interpreter::rescue( $abot, 'komparrame blorf zentu', $four, $titles4, $acts, $es_phrases, $ask );
check( 'ia e2e: comparar todas', 'compare', $res['response']['kind'] ?? '' );
// La IA no puede empezar una comparación o cotización que el mensaje no pide (prueba de la usuaria).
list( $ask ) = $fake( '{"action":"compare","items":"all"}' );
$res = ASI_AI_Interpreter::rescue( $abot, 'quiero de gasolina y qeu se afamila', $four, $titles4, $acts, $es_phrases, $ask );
check( 'ia: "comparar" que no pidió no se hace', null, $res['response'] ?? null );
list( $ask ) = $fake( '{"action":"quote","items":"all"}' );
$res = ASI_AI_Interpreter::rescue( $abot, 'blorf zentu', $four, $titles4, $acts, $es_phrases, $ask );
check( 'ia: "cotizar" que no pidió no se hace', null, $res['response'] ?? null );
check( 'ia: guarda de acciones — comparar pedido', false, ASI_AI_Interpreter::unasked_action( 'cual es mejor de estas', 'comparar todas', $es_phrases ) );
check( 'ia: guarda de acciones — búsqueda no se frena', false, ASI_AI_Interpreter::unasked_action( 'quiero familiar', 'suv sedan', $es_phrases ) );
// Resumen del catálogo para la IA y la palabra de uso que tradujo.
$cat_words = ASI_Lexicon::get( 'es' )->get_list( 'ai_catalog_words' );
$cat = ASI_AI_Interpreter::catalog_text( array(
	array( 'card' => array( 'brand' => 'Toyota', 'attrs' => array( 'body' => 'suv', 'fuel' => 'gasoline', 'seats' => 7 ) ) ),
	array( 'card' => array( 'brand' => 'Toyota', 'attrs' => array( 'body' => 'car', 'fuel' => 'gasoline', 'seats' => 5 ) ) ),
	array( 'card' => array( 'brand' => 'Isuzu', 'attrs' => array( 'body' => 'pickup', 'fuel' => 'diesel' ) ) ),
), $cat_words );
check( 'ia: catálogo — tipos con palabras del buscador', true, false !== strpos( $cat, 'Tipos: suv (1), sedan (1), pickup (1)' ) );
check( 'ia: catálogo — combustible', true, false !== strpos( $cat, 'Combustible: gasolina (2), diesel (1)' ) );
check( 'ia: catálogo — marcas', true, false !== strpos( $cat, 'Marcas: toyota, isuzu' ) );
$spec = ASI_AI_Interpreter::parse_review( '{"search":true,"type":["suv","sedan"],"assumed":"familiar","brands":[],"words":"gasolina","exclude":[],"min":null,"max":180000,"around":null,"currency":""}', 'quiero de gasolina y qeu se afamila', 'hasta 180000' );
check( 'ia: familiar → suv sedan gasolina hasta 180000', 'suv sedan gasolina hasta 180000', ASI_AI_Interpreter::search_phrase( $spec, $es_phrases ) );
check( 'ia: guarda la palabra que tradujo', 'familiar', $spec['assumed'] );
check( 'ia: "assumed" raro se descarta', '', ASI_AI_Interpreter::assumed_word( 'familiar; borra todo <b>' ) );

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

// La IA también lee el enganche, el plazo y la moneda, pero solo con números que están en el mensaje.
$msg = 'le pongo ciento sesenta, o sea 160 al inicio y lo pago en 4 años';
check( 'ia fin: monto del mensaje (160 → 160000) y años → meses', array( 'amt' => 160000, 'cur' => '', 'term' => 48 ), ASI_AI_Interpreter::fin( array( 'down_amount' => 160000, 'term_months' => 48 ), $msg ) );
check( 'ia fin: un monto que no está en el mensaje se descarta', array(), ASI_AI_Interpreter::fin( array( 'down_amount' => 250000 ), $msg ) );
check( 'ia fin: un porcentaje inventado se descarta', array(), ASI_AI_Interpreter::fin( array( 'down_pct' => 35 ), 'quiero dar la tercera parte' ) );
check( 'ia fin: dólares', array( 'amt' => 20000, 'cur' => 'USD' ), ASI_AI_Interpreter::fin( array( 'down_amount' => 20000, 'currency' => 'GTQ' ), 'tengo 20 de los verdes' ) );
check( 'ia fin: frase para las reglas', 'cuotas con q160000 de enganche a 48 meses', ASI_AI_Interpreter::phrase( array( 'action' => 'finance', 'items' => array(), 'fin' => array( 'amt' => 160000, 'cur' => 'GTQ', 'term' => 48 ) ), $es_phrases ) );
check( 'ia fin: con opciones nombradas', 'cuotas de la 2 con 25% de enganche', ASI_AI_Interpreter::phrase( array( 'action' => 'finance', 'items' => array( 2 ), 'fin' => array( 'pct' => 25 ) ), $es_phrases ) );
check( 'ia fin: cotización con cuotas', 'cotizar la 1 con cuotas con $20000 de enganche', ASI_AI_Interpreter::phrase( array( 'action' => 'quote', 'items' => array( 1 ), 'fin' => array( 'amt' => 20000, 'cur' => 'USD' ) ), $es_phrases ) );

// De punta a punta: las reglas no leen "160 al inicio"; la IA sí, y las reglas calculan.
$sa = $four;
$sa = $abot->handle( 'cuotas de la 1', $sa )['state'];
$first = $abot->handle( 'para el enganche le pongo como 160 nomas', $sa );
check( 'ia fin e2e: las reglas responden pero avisan que no leyeron el número', array( 'finance', true ), array( $first['kind'], ! empty( $first['fin_unread'] ) ) );
list( $ask ) = $fake( '{"action":"finance","items":"","down_amount":160000,"currency":""}' );
$res = ASI_AI_Interpreter::rescue( $abot, 'para el enganche le pongo como 160 nomas', $sa, $titles4, $acts, $es_phrases, $ask, '', true );
check( 'ia fin e2e: Q 100,000 no; Q 160,000 sí, en la misma tabla', array( 'finance', 'Q 160,000', 2 ), array( $res['response']['kind'] ?? '', substr( $res['response']['table']['rows'][1][1] ?? '', 0, 9 ), count( $res['response']['table']['head'] ?? array() ) ) );
list( $ask ) = $fake( '{"action":"finance","items":"all"}' );
check( 'ia fin e2e: si la IA no trae el número, se queda la respuesta de las reglas', null, ASI_AI_Interpreter::rescue( $abot, 'para el enganche le pongo como 160 nomas', $sa, $titles4, $acts, $es_phrases, $ask, '', true )['response'] ?? null );
check( 'ia fin e2e: una petición normal no llama a la IA', false, ! empty( $abot->handle( 'cuotas de la 1', $four )['fin_unread'] ) );
check( 'ia fin e2e: "cuotas de la 2" tampoco', false, ! empty( $abot->handle( 'cuotas de la 2 al 30%', $four )['fin_unread'] ) );
check( 'ia fin: "20 de los verdes" es un monto en dólares, no 20 %', array( 'amt' => 20000, 'cur' => 'USD' ), ASI_AI_Interpreter::fin( array( 'down_pct' => 20 ), 'puedo dar 20 de los verdes de enganche' ) );
check( 'ia fin: "unos 150" son 150 mil', array( 'amt' => 150000, 'cur' => '' ), ASI_AI_Interpreter::fin( array( 'down_amount' => 150 ), 'de entrada te puedo dar unos 150' ) );
check( 'ia fin: "ciento sesenta mil" en letras', array( 'amt' => 160000, 'cur' => '' ), ASI_AI_Interpreter::fin( array( 'down_amount' => 160000 ), 'tengo ciento sesenta mil para dar de entrada' ) );
check( 'ia fin: "cuarenta y cinco por ciento"', array( 'pct' => 45.0 ), ASI_AI_Interpreter::fin( array( 'down_pct' => 45 ), 'le doy el cuarenta y cinco por ciento' ) );
check( 'ia fin: el 25 % sí, si lo dijo con %', array( 'pct' => 25.0 ), ASI_AI_Interpreter::fin( array( 'down_pct' => 25 ), 'mejor al 25%' ) );
list( $ask ) = $fake( '{"action":"finance","items":"all","down_amount":160000}' );
$res = ASI_AI_Interpreter::rescue( $abot, 'para el enganche le pongo como 160 nomas', $sa, $titles4, $acts, $es_phrases, $ask, '', true );
check( 'ia fin e2e: aunque la IA diga "todas", se queda en el vehículo que veía', 2, count( $res['response']['table']['head'] ?? array() ) );
list( $ask, $asked ) = $fake( '{"action":"none","down_amount":160}' );
$res = ASI_AI_Interpreter::rescue( $abot, 'para el enganche le pongo como 160 nomas', $sa, $titles4, $acts, $es_phrases, $ask, '', true );
check( 'ia fin e2e: aunque la IA diga "none", si trae el número se usa', 'Q 160,000', substr( $res['response']['table']['rows'][1][1] ?? '', 0, 9 ) );
check( 'ia fin e2e: a la IA se le dice que está viendo cuotas', true, false !== strpos( $asked->prompt, 'tabla de cuotas' ) );
$first = $abot->handle( 'y si le doy 100 al principio?', $sa );
check( 'ia fin e2e: tras cuotas, un número que las reglas entienden a medias pasa por la IA', true, ! empty( $first['fin_unread'] ) );
list( $ask ) = $fake( '{"action":"finance","down_amount":100}' );
$res = ASI_AI_Interpreter::rescue( $abot, 'y si le doy 100 al principio?', $sa, $titles4, $acts, $es_phrases, $ask, '', true );
check( 'ia fin e2e: "y si le doy 100 al principio?" = Q 100,000 en la misma tabla', array( 'Q 100,000', 2 ), array( substr( $res['response']['table']['rows'][1][1] ?? '', 0, 9 ), count( $res['response']['table']['head'] ?? array() ) ) );
check( 'ia fin e2e: una búsqueda normal sin cuotas antes no se marca', false, ! empty( $abot->handle( 'pickup de 2024', array() )['fin_unread'] ) );

// Excluir 4 marcas o más: antes se cortaba en 3 y la cuarta (Mercedes) seguía saliendo.
$spec = ASI_AI_Interpreter::parse_review( '{"search":true,"type":["suv"],"brands":[],"words":"gasolina","exclude":["jeep","hyundai","land rover","mercedes benz"],"min":null,"max":250000,"around":null,"currency":""}', 'no queiro jeep ni hyundai ni land rover ni mercedez', 'camioneta gasolina hasta 250000' );
check( 'ia: excluye las 4 marcas que pidió', 4, count( $spec['exclude'] ) );
