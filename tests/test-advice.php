<?php
// El asesor: las frases que dijo el jefe en la reunión.
require_once dirname( __DIR__ ) . '/packs/autos/class-asi-pack-autos.php';
$adv = new ASI_Pack_Autos();

// Perfil.
$p = function ( $text, $profile = array() ) use ( $adv ) {
	return $adv->read_profile( $text, 'es', $profile )['profile'];
};
$fam = $p( 'Oye, yo soy una familia de cuatro personas, un niño de 5 años, ¿qué me recomendás?' );
check( 'perfil: familia de cuatro', 4, $fam['people'] );
check( 'perfil: un niño', 1, $fam['kids'] );
check( 'perfil: edad', array( 5 ), $fam['ages'] );
check( 'perfil: voseo "recomendás" pide consejo', true, $adv->asks_advice( '¿qué me recomendás?', 'es' ) );
$two = $p( 'somos mi esposa, yo y dos hijos de 3 y 7 años' );
check( 'perfil: mi esposa y dos hijos', array( 4, 2 ), array( $two['people'], $two['kids'] ) );
check( 'perfil: edades 3 y 7', array( 3, 7 ), ( function ( $a ) { sort( $a ); return $a; } )( $p( 'mi esposa y yo con dos hijos de 3 y 7 años' )['ages'] ) );
check( 'perfil: somos 7', 7, $p( 'somos 7 de familia' )['people'] );
check( 'perfil: trabajo + finca', array( 'work', 'rough' ), $p( 'lo quiero para trabajar en la finca' )['uses'] );
check( 'perfil: "ir al trabajo" es ciudad, no carga', array( 'city' ), $p( 'es para ir al trabajo todos los días' )['uses'] );
check( 'perfil: toneladas', 3.0, $p( 'necesito cargar 3 toneladas' )['cargo_t'] );
check( 'perfil: "7 pasajeros" no es el tamaño de la familia', false, isset( $p( 'suv de 7 pasajeros' )['people'] ) );
check( 'perfil: se acumula', array( 4, array( 'rough' ) ), ( function ( $x ) { return array( $x['people'], $x['uses'] ); } )( $p( 'y lo usamos en la finca', array( 'people' => 4 ) ) ) );

// Recomendación (reglas).
$r = $adv->recommend( $fam, 'es' );
check( 'reco: familia con niño → primero SUV', 'suv', array_keys( $r['weights'] )[0] );
check( 'reco: plazas para todos', array( 'min' => 4 ), $r['constraints']['seats'] );
check( 'reco: resumen', 'una familia de 4 con un niño', $r['summary'] );
$big = $adv->recommend( array( 'people' => 7 ), 'es' );
check( 'reco: 7 personas no ofrece sedán', false, isset( $big['weights']['sedan'] ) || isset( $big['weights']['car'] ) );
check( 'reco: 7 personas lo dice', true, false !== strpos( $big['avoid'], 'sedán' ) );
$farm = $adv->recommend( array( 'people' => 3, 'uses' => array( 'work', 'rough' ) ), 'es' );
check( 'reco: trabajo en finca → pickup primero', 'pickup', array_keys( $farm['weights'] )[0] );
check( 'reco: finca prefiere 4x4', array( 'in' => array( '4x4', 'awd' ) ), $farm['prefer']['traction'] );

// Conversación completa.
$yard = new ASI_Test_Repository();
$car  = function ( $id, $title, $cat, $price, $attrs, $cur = '2' ) use ( $yard ) {
	$yard->add( $id, 'es', 'catalog', $title, array( 'category' => array( $cat ) ), $cat . '.', array(), array( 'price' => (string) $price, 'currency_raw' => $cur, 'attrs' => $attrs ) );
};
$car( 1, 'Kia Sportage', 'SUV', 229900, array( 'body' => 'suv', 'seats' => 5, 'doors' => 4, 'traction' => '4x2', 'transmission' => 'auto', 'year' => 2026 ) );
$car( 2, 'Hyundai Tucson', 'SUV', 249900, array( 'body' => 'suv', 'seats' => 5, 'doors' => 4, 'traction' => '4x2', 'transmission' => 'auto', 'year' => 2026 ) );
$car( 3, 'Mitsubishi Outlander', 'SUV', 179900, array( 'body' => 'suv', 'seats' => 7, 'doors' => 4, 'traction' => '4x2', 'transmission' => 'auto', 'year' => 2025 ) );
$car( 4, 'Toyota Fortuner', 'SUV', 499990, array( 'body' => 'suv', 'seats' => 7, 'doors' => 4, 'traction' => '4x4', 'transmission' => 'auto', 'year' => 2026 ) );
$car( 5, 'Toyota Yaris Sedán', 'Automóvil', 135990, array( 'body' => 'car', 'seats' => 5, 'doors' => 4, 'traction' => '4x2', 'transmission' => 'manual', 'year' => 2026 ) );
$car( 6, 'Toyota Hilux 4x4', 'Pick up', 384990, array( 'body' => 'pickup', 'seats' => 5, 'doors' => 4, 'traction' => '4x4', 'transmission' => 'manual', 'year' => 2026 ) );
$car( 7, 'Nissan Frontier 4x2', 'Pick up', 259900, array( 'body' => 'pickup', 'seats' => 5, 'doors' => 4, 'traction' => '4x2', 'transmission' => 'manual', 'year' => 2026 ) );
$car( 8, 'Hyundai Staria', 'Van', 309990, array( 'body' => 'van', 'seats' => 11, 'doors' => 4, 'traction' => '4x2', 'transmission' => 'auto', 'year' => 2026 ) );
$car( 9, 'Isuzu NPR 3 Ton', 'Camión', 289900, array( 'body' => 'truck', 'seats' => 3, 'doors' => 2, 'traction' => '4x2', 'cargo_t' => 3.0, 'year' => 2026 ) );
$bot = new ASI_Dialogue( $yard, array( 'lang' => 'es', 'language' => 'es', 'site_name' => 'Agencia', 'money' => array( 'base' => 'GTQ', 'map' => array( '2' => 'GTQ', '1' => 'USD' ) ), 'packs' => array( $adv ) ), function () {
	return 0;
} );
$chat = function ( $message, &$state ) use ( $bot ) {
	$r     = $bot->handle( $message, $state );
	$state = $r['state'];
	return $r;
};
$first_body = function ( $r ) use ( $yard ) {
	$rows = $yard->rows( 'es', array( $r['cards'][0]['id'] ) );
	return $rows[0]['card']['attrs']['body'];
};

$s = array();
$r = $chat( 'Oye, yo soy una familia de cuatro personas, un niño de 5 años, ¿qué me recomendás?', $s );
check( 'conv: recomienda una SUV primero', 'suv', $first_body( $r ) );
check( 'conv: dice para quién', true, false !== strpos( $r['text'], 'una familia de 4 con un niño' ) );
check( 'conv: explica el porqué', true, false !== strpos( $r['text'], 'sillas' ) );
check( 'conv: pide el presupuesto', true, false !== strpos( $r['followup'], 'presupuesto' ) || false !== strpos( $r['followup'], 'invertir' ) );
check( 'conv: pocas tarjetas, como un asesor', true, count( $r['cards'] ) <= 4 && count( $r['cards'] ) >= 2 );
check( 'conv: ofrece montos como botones', 3, count( $r['suggestions'] ) );
check( 'conv: no ofrece camión', false, in_array( 9, asi_card_ids( $r ), true ) );

$r = $chat( 'como 250 mil', $s );
check( 'conv: con presupuesto, todo entra', true, (bool) $r['cards'] && ! array_filter( $r['cards'], function ( $c ) { return ASI_Money::parse_display_price( $c['price'] ) > 250000; } ) );
check( 'conv: lo dice', true, false !== strpos( $r['text'], 'Q 250,000' ) );
check( 'conv: no vuelve a pedir el presupuesto', false, false !== strpos( $r['followup'], 'presupuesto aproximado' ) );

$r = $chat( 'tengo 120 mil', $s );
check( 'conv: se pasa del presupuesto → lo dice', true, false !== strpos( $r['followup'], 'alto' ) || false !== strpos( $r['followup'], 'se pasa' ) );
check( 'conv: muestra lo más accesible primero', 5, $r['cards'][0]['id'] );

$s = array();
$r = $chat( 'somos 7 de familia', $s );
check( 'conv: 7 personas → solo de 7 plazas o más', array(), array_values( array_filter( asi_card_ids( $r ), function ( $id ) { return in_array( $id, array( 1, 2, 5, 6, 7 ), true ); } ) ) );
check( 'conv: 7 personas → dice por qué no un sedán', true, false !== strpos( $r['text'], 'sedán' ) );

$s = array();
$r = $chat( 'lo quiero para trabajar en la finca, somos 3', $s );
check( 'conv: finca y trabajo → primero la pickup 4x4', 6, $r['cards'][0]['id'] );

$s = array();
$r = $chat( '¿qué me recomiendas?', $s );
check( 'conv: sin datos → pregunta una cosa', 'smalltalk', $r['kind'] );
check( 'conv: sin datos → botones de perfil', true, count( $r['suggestions'] ) >= 3 );
$r = $chat( 'somos 4 con niños', $s );
check( 'conv: después de preguntar, recomienda', 'suv', $first_body( $r ) );

$s = array();
$r = $chat( 'somos 5 y busco una pickup', $s );
check( 'conv: respeta el tipo que pidió', array( 6, 7 ), ( function ( $x ) { sort( $x ); return $x; } )( asi_card_ids( $r ) ) );

// Lo normal sigue siendo búsqueda.
$s = array();
$r = $chat( 'suv de 7 pasajeros', $s );
check( 'conv: una búsqueda normal no se vuelve consejo', false, false !== strpos( $r['text'], 'recomiendo' ) );
// Un modelo por tarjeta, y el porqué del uso primero.
$car( 10, 'Kia Sportage 2025', 'SUV', 219900, array( 'body' => 'suv', 'seats' => 5, 'doors' => 4, 'year' => 2025 ) );
$s = array();
$r = $chat( 'somos 4 con un niño, ¿qué me recomiendas?', $s );
$titles = array_map( function ( $c ) { return strtolower( implode( ' ', array_slice( explode( ' ', $c['title'] ), 0, 2 ) ) ); }, $r['cards'] );
check( 'conv: no repite el mismo modelo', count( $titles ), count( array_unique( $titles ) ) );
$farm_r = $adv->recommend( array( 'people' => 3, 'uses' => array( 'work', 'rough' ) ), 'es' )['reasons'];
check( 'reco: finca → primero palangana y 4x4', array( true, true ), array( false !== strpos( $farm_r[0] . $farm_r[1], 'palangana' ), false !== strpos( $farm_r[0] . $farm_r[1], '4x4' ) ) );
check( 'reco: con uso claro no dice "3 adultos"', false, false !== strpos( implode( ' ', $farm_r ), 'adultos' ) );
// La conversación real de la prueba del usuario (con sus errores de dedo).
$fam2 = $p( 'oye mira ando viendo camionetas , somos una familia de 4 itnegrantes, 2 niños de 6 y 13 años, pero mi esposo también trabaj mucho en fincas, entonces ando viendo opciones' );
check( 'real: "somos una familia de 4" son 4, no 1', 4, $fam2['people'] );
check( 'real: 2 niños de 6 y 13', array( 2, array( 6, 13 ) ), array( $fam2['kids'], ( function ( $a ) { sort( $a ); return $a; } )( $fam2['ages'] ) ) );
check( 'real: "trabaj… en fincas" es trabajo y finca', array( 'work', 'rough' ), $fam2['uses'] );
check( 'real: "2 adulto y 2 niños"', 2, $p( 'no no somos 4, 2 adulto y 2 niños' )['adults'] );

$s = array();
$r = $chat( 'oye mira ando viendo camionetas , somos una familia de 4 itnegrantes, 2 niños de 6 y 13 años, pero mi esposo también trabaj mucho en fincas, entonces ando viendo opciones', $s );
check( 'real: dice familia de 4', true, false !== strpos( $r['text'], 'familia de 4' ) );
check( 'real: con finca y trabajo ofrece pickup', true, in_array( 'pickup', array_map( function ( $c ) use ( $yard ) { return $yard->rows( 'es', array( $c['id'] ) )[0]['card']['attrs']['body']; }, $r['cards'] ), true ) );
$r = $chat( 'no no somos 4, 2 adulto y 2 niños, mi esposo trabaja mcho en fincas enotnces neceistamos un bien carro tipo pickup pero tambien podria ser una suv para uqe nos sirva con la familia', $s );
check( 'real: una aclaración larga no se vuelve cotización', 'results', $r['kind'] );
check( 'real: pickup o SUV, las dos', array( 'pickup', 'suv' ), ( function ( $cards ) use ( $yard ) { $b = array_unique( array_map( function ( $c ) use ( $yard ) { return $yard->rows( 'es', array( $c['id'] ) )[0]['card']['attrs']['body']; }, $cards ) ); sort( $b ); return array_values( $b ); } )( $r['cards'] ) );
$shown_before = $s['shown'];
$r = $chat( 'a ver pero quiero ver más opciones nyo no elegí ningua de las que me diste', $s );
check( 'real: "más opciones" muestra otras', true, (bool) $r['cards'] && ! array_intersect( asi_card_ids( $r ), array_slice( $shown_before, -4 ) ) );
// Repetir la situación (sin datos nuevos) sigue siendo una conversación de asesor y respeta "pickup o SUV".
$s = array();
$chat( 'somos 4, 2 adultos y 2 niños, trabajamos en fincas', $s );
$r = $chat( 'no no somos 4, 2 adulto y 2 niños, mi esposo trabaja mcho en fincas enotnces neceistamos un bien carro tipo pickup pero tambien podria ser una suv para uqe nos sirva con la familia', $s );
check( 'real: la aclaración sin datos nuevos vuelve a recomendar', true, false !== strpos( $r['text'], 'te recomiendo' ) || false !== strpos( $r['text'], 'me iría' ) || false !== strpos( $r['text'], 'mejor te queda' ) );
check( 'reco: con terracería no ofrece sedán', false, isset( $adv->recommend( array( 'people' => 5, 'kids' => 3, 'uses' => array( 'rough' ) ), 'es' )['weights']['sedan'] ) );

// Lo que probó el jefe: tope dicho con negación y luego "solo toyota".
$s = array();
$chat( 'busco una suv', $s );
$r = $chat( 'pero que no sean mayor a 300 mil', $s );
$ids = asi_card_ids( $r );
check( 'jefe: "no mayor a" es tope', true, (bool) $ids && ! array_filter( $r['cards'], function ( $c ) { return ASI_Money::parse_display_price( $c['price'] ) > 300000; } ) );
check( 'jefe: el tope afina la búsqueda (siguen siendo SUV)', array(), array_diff( $ids, array( 1, 2, 3, 10 ) ) );
check( 'jefe: lo dice como tope', true, false !== strpos( $r['text'], 'Q 300,000' ) && false === strpos( $r['text'], 'desde' ) );
check( 'jefe: dice con qué afinó', true, false !== strpos( $r['text'], 'hasta Q 300,000' ) );
$r = $chat( 'quiero solo toyota', $s );
check( 'jefe: "solo toyota" no excluye Toyota', false, false !== stripos( $r['text'], 'quit' ) || false !== stripos( $r['text'], 'sin incluir' ) || false !== stripos( $r['text'], 'fuera' ) );
$s = array();
$chat( 'busco una suv', $s );
$r = $chat( 'quiero solo toyota', $s );
check( 'jefe: solo toyota tras SUV = Fortuner', array( 4 ), asi_card_ids( $r ) );
$s = array();
$chat( 'busco una suv', $s );
$chat( 'tengo 300 mil', $s );
$r = $chat( 'mejor hasta 200 mil', $s );
check( 'monto nuevo reemplaza al anterior', array( 3 ), asi_card_ids( $r ) );
$s = array();
$r = $chat( 'quiero una suv que no sea toyota', $s );
check( 'negación normal sigue funcionando', false, in_array( 4, asi_card_ids( $r ), true ) );
$s = array();
$r = $chat( 'una pickup que no sea nissan, solo toyota', $s );
check( 'negación con "solo" después: pickup Toyota', array( 6 ), asi_card_ids( $r ) );

// Modo "lee todo": la IA revisa cada búsqueda (IA simulada).
$es_ai    = ASI_Lexicon::get( 'es' )->get_list( 'ai_phrases' );
$an       = new ASI_Query_Analyzer( ASI_Lexicon::get( 'es' ), $yard->vocabulary( 'es' ), array( 'base' => 'GTQ', 'map' => array( '2' => 'GTQ', '1' => 'USD' ) ), array( $adv ) );
$said     = function ( $json ) {
	$calls = (object) array( 'n' => 0, 'prompt' => '' );
	return array(
		function ( $prompt ) use ( $json, $calls ) {
			$calls->n++;
			$calls->prompt = $prompt;
			return array( 'text' => $json, 'model' => 'fake' );
		},
		$calls,
	);
};
$review = function ( $message, &$s, $json ) use ( $bot, $an, $es_ai, $said ) {
	list( $ask, $calls ) = $said( $json );
	$before = $s;
	$rules  = $bot->handle( $message, $s );
	$out    = ASI_AI_Interpreter::review( $bot, $an, $message, $before, $rules, $es_ai, $ask );
	$final  = $out['response'] ? $out['response'] : $rules;
	$s      = $final['state'];
	return array( $final, $out, $calls, $rules );
};

check( 'lee todo: revisa búsquedas', true, ASI_AI_Interpreter::reviewable( array( 'kind' => 'results', 'state' => array( 'q' => 'suv' ) ), array(), 'suv' ) );
check( 'lee todo: no revisa un formulario a medias', false, ASI_AI_Interpreter::reviewable( array( 'kind' => 'results', 'state' => array( 'q' => 'suv' ) ), array( 'pending' => array( 'type' => 'quote' ) ), 'ana' ) );
check( 'lee todo: no revisa cuotas', false, ASI_AI_Interpreter::reviewable( array( 'kind' => 'finance', 'state' => array( 'q' => 'suv' ) ), array(), 'cuotas' ) );

$p = ASI_AI_Interpreter::parse_review( '{"search":true,"type":"suv","brands":["toyota"],"exclude":[],"min":null,"max":100000}', 'quiero solo toyota', 'suv que no sean mayor a 100 mil' );
check( 'lee todo: el monto puede venir de lo anterior', 100000.0, $p['max'] );
check( 'lee todo: frase canónica', 'suv toyota hasta 100000', ASI_AI_Interpreter::search_phrase( $p, $es_ai ) );
$p = ASI_AI_Interpreter::parse_review( '{"search":true,"type":"suv","max":50000}', 'una suv barata', '' );
check( 'lee todo: monto inventado se descarta', null, $p['max'] );
check( 'lee todo: no es búsqueda', null, ASI_AI_Interpreter::parse_review( '{"search":false}', 'cuotas de la 2', 'suv' ) );
$p = ASI_AI_Interpreter::parse_review( '{"search":true,"type":"pickup","exclude":["nissan"],"max":40000}', 'pickup que no sea nissan hasta 40 mil dolares', '' );
check( 'lee todo: moneda por las palabras del cliente', 'pickup hasta $40000 que no sea nissan', ASI_AI_Interpreter::search_phrase( $p, $es_ai ) );

// Cuando la IA entendió lo mismo, se queda la respuesta de las reglas.
$s = array();
$chat( 'busco una suv', $s );
list( $r, $out ) = $review( 'pero que no sean mayor a 300 mil', $s, '{"search":true,"type":"suv","brands":[],"words":"","exclude":[],"min":null,"max":300000,"currency":""}' );
check( 'lee todo: de acuerdo = no cambia nada', null, $out['response'] );
check( 'lee todo: pero sí consultó', true, $out['asked'] );

// Cuando entendió otra cosa, se busca con su lectura (como una regla vieja que excluía Toyota).
$s = array();
$chat( 'busco una suv', $s );
list( $r, $out, $calls, $rules ) = $review( 'las de la marca del triangulo japonesa', $s, '{"search":true,"type":"suv","brands":["toyota"],"words":"","exclude":[],"min":null,"max":null,"currency":""}' );
check( 'lee todo: corrige con su lectura', array( 4 ), asi_card_ids( $r ) );
check( 'lee todo: le pasa lo que venía buscando', true, false !== strpos( $calls->prompt, 'busco una suv' ) );
check( 'lee todo: la siguiente búsqueda parte de la corregida', 'suv toyota', $s['q'] );

// Si la lectura de la IA no da resultados, se queda la de las reglas.
$s = array();
$chat( 'busco una suv', $s );
list( $r, $out ) = $review( 'solo las moradas', $s, '{"search":true,"type":"suv","brands":["ferrari"],"words":"","exclude":[],"min":null,"max":null}' );
check( 'lee todo: lectura sin resultados no reemplaza', null, $out['response'] );

// Con caché no vuelve a preguntar.
$s = array();
$chat( 'busco una suv', $s );
$before = $s;
$rules  = $bot->handle( 'las de la marca del triangulo japonesa', $s );
list( $ask, $calls ) = $said( '{}' );
$out = ASI_AI_Interpreter::review( $bot, $an, 'las de la marca del triangulo japonesa', $before, $rules, $es_ai, $ask, '', 'suv toyota' );
check( 'lee todo: caché sin consultar', array( 0, array( 4 ) ), array( $calls->n, asi_card_ids( $out['response'] ) ) );
$s = array();
$chat( 'busco pickup toyota', $s );
$r = $chat( 'cuanto sale la mas barata', $s );
check( '"cuánto sale la más barata" = la más barata de lo que veía', true, false !== strpos( $r['text'], 'Hilux' ) );
check( 'la más barata: precio con formato', true, false !== strpos( $r['text'], '384,990' ) );
$p = ASI_AI_Interpreter::parse_review( '{"search":true,"type":"pickup","around":250000,"max":250000}', 'pickup que cueste unos 250 mil', '' );
check( 'lee todo: "unos 250 mil" es aproximado, no tope', 'pickup alrededor de 250000', ASI_AI_Interpreter::search_phrase( $p, $es_ai ) );
$s = array();
$r = $chat( 'una suv de hasta 200 mil', $s );
$extra = isset( $r['extra']['cards'] ) ? $r['extra']['cards'] : array();
check( 'similares respetan el presupuesto', array(), array_filter( $extra, function ( $c ) { return ASI_Money::parse_display_price( $c['price'] ) > 200000; } ) );

// Filtro: qué búsquedas gastan una consulta.
$sig  = ASI_Lexicon::get( 'es' )->get_list( 'ai_signals' );
$ok   = array( 'kind' => 'results', 'state' => array( 'q' => 'x' ) );
$gate = function ( $m ) use ( $ok, $sig ) {
	return ASI_AI_Interpreter::reviewable( $ok, array(), $m, $sig );
};
check( 'filtro: "suv" no gasta', false, $gate( 'suv' ) );
check( 'filtro: "solo toyota" no gasta', false, $gate( 'solo toyota' ) );
check( 'filtro: "pickup 4x4" no gasta', false, $gate( 'pickup 4x4' ) );
check( 'filtro: "busco una camioneta" no gasta', false, $gate( 'busco una camioneta' ) );
check( 'filtro: montos sí', true, $gate( 'pero que no sean mayor a 100 mil' ) );
check( 'filtro: monto en letras sí', true, $gate( 'algo de cien mil' ) );
check( 'filtro: negación sí', true, $gate( 'ni nissan ni isuzu' ) );
check( 'filtro: "más barato" sí', true, $gate( 'algo mas barato' ) );
check( 'filtro: "pero" sí', true, $gate( 'pero automatica' ) );
check( 'filtro: mensaje largo sí', true, $gate( 'quiero algo comodo para viajar con mi familia los domingos' ) );
check( 'filtro: resultado aproximado sí', true, ASI_AI_Interpreter::reviewable( array( 'kind' => 'results', 'learn' => 'weak', 'state' => array( 'q' => 'x' ) ), array(), 'suv morada', $sig ) );
check( 'filtro: lo que no entendió siempre', true, ASI_AI_Interpreter::reviewable( array( 'kind' => 'unclear' ), array(), 'las de la marca del triangulo', $sig ) );

// "Presupuesto" = cuánto quiere gastar, no una cotización (lo que probó el jefe).
foreach ( array(
	'quiero camionetas con un presupuesto no mayor a 500 mil',
	'tengo un presupuesto de 300 mil para una suv',
	'mi presupuesto es de 250 mil',
	'suv con presupuesto de hasta 300 mil',
) as $m ) {
	$s = array();
	$r = $chat( $m, $s );
	check( "presupuesto es búsqueda: $m", 'results', $r['kind'] );
}
$s = array();
$r = $chat( 'quiero camionetas con un presupuesto no mayor a 300 mil', $s );
check( 'presupuesto: camionetas dentro del monto', true, (bool) $r['cards'] && ! array_filter( $r['cards'], function ( $c ) { return ASI_Money::parse_display_price( $c['price'] ) > 300000; } ) );
$s = array();
$r = $chat( 'quiero una cotizacion', $s );
check( 'cotizar sin monto sigue siendo cotización', 'info', $r['kind'] );
check( 'filtro: info con monto se revisa', true, ASI_AI_Interpreter::reviewable( array( 'kind' => 'info' ), array(), 'quiero camionetas con un presupuesto no mayor a 500 mil', $sig ) );
check( 'filtro: info simple no gasta', false, ASI_AI_Interpreter::reviewable( array( 'kind' => 'info' ), array(), 'horario', $sig ) );
// Si las reglas hubieran mandado a cotizar, la IA lo convierte en búsqueda.
$s = array();
$fake_info = array( 'kind' => 'info', 'state' => array( 'q' => '' ), 'cards' => array() );
list( $ask ) = $said( '{"search":true,"type":"suv","brands":[],"words":"","exclude":[],"min":null,"max":300000,"around":null}' );
$out = ASI_AI_Interpreter::review( $bot, $an, 'quiero camionetas con un presupuesto no mayor a 300 mil', $s, $fake_info, $es_ai, $ask );
check( 'lee todo: de "pide tu cotización" a opciones', 'results', $out['response']['kind'] );
check( 'filtro: información con "pero" no gasta', false, ASI_AI_Interpreter::reviewable( array( 'kind' => 'info' ), array(), 'donde estan ubicados pero en la capital', $sig ) );
check( 'filtro: "hablar con alguien" no gasta', false, ASI_AI_Interpreter::reviewable( array( 'kind' => 'info' ), array(), 'quiero hablar con alguien de la empresa', $sig ) );

// Información: nunca la página de "gracias"; contacto y asesor con WhatsApp.
$site = new ASI_Test_Repository();
$site->add( 50, 'es', 'info', 'Gracias contacto', array(), 'Gracias por contactarnos, nos comunicaremos contigo.', array(), array( 'url' => 'https://x.test/gracias-contacto/' ) );
$site->add( 51, 'es', 'info', 'Contáctanos', array(), 'Escríbenos o llámanos. Atención al cliente.', array(), array( 'url' => 'https://x.test/contactanos/' ) );
$site->add( 52, 'es', 'info', 'Ubicaciones', array(), 'Nuestras sedes y sucursales.', array(), array( 'url' => 'https://x.test/ubicaciones/' ) );
$mk = function ( $wa ) use ( $site ) {
	return new ASI_Dialogue( $site, array( 'lang' => 'es', 'language' => 'es', 'site_name' => 'Agencia', 'whatsapp' => $wa ), function () {
		return 0;
	} );
};
$urls = function ( $r ) {
	return array_column( $r['links'], 'url' );
};
$sb = $mk( '+50211112222' );
foreach ( array( 'quiero hablar con un asesor', 'quiero hablar con alguien de la empresa', 'tienen whatsapp?', 'como los contacto' ) as $m ) {
	$r = $sb->handle( $m, array() );
	check( "info: \"$m\" → WhatsApp y Contáctanos", array( 'https://wa.me/50211112222', 'https://x.test/contactanos/' ), $urls( $r ) );
}
$r = $mk( '' )->handle( 'quiero hablar con un asesor', array() );
check( 'info: sin WhatsApp configurado, solo Contáctanos', array( 'https://x.test/contactanos/' ), $urls( $r ) );
$r = $sb->handle( 'que sedes tienen', array() );
check( 'info: "sedes" = ubicaciones', array( 'https://x.test/ubicaciones/' ), $urls( $r ) );
// Lugares: sin corrector ortográfico y con alias ("la capital" → sedes por zona).
$places = new ASI_Test_Repository();
$places->add( 60, 'es', 'info', 'Zona 10', array(), 'Sede zona 10.', array(), array( 'url' => 'https://x.test/z10/' ) );
$places->add( 61, 'es', 'info', 'Cobán', array(), 'Sede Cobán.', array(), array( 'url' => 'https://x.test/coban/' ) );
$places->add( 62, 'es', 'catalog', 'Jeep con capota', array( 'category' => array( 'SUV' ) ), 'Capota de lona.' );
$pb = new ASI_Dialogue( $places, array( 'lang' => 'es', 'language' => 'es', 'site_name' => 'Agencia', 'location_types' => array( 'page' ) ), function () {
	return 0;
} );
$r = $pb->handle( 'donde estan ubicados en la capital', array() );
check( 'lugares: "la capital" no se corrige a "capota"', false, false !== stripos( $r['text'], 'capota' ) );
check( 'lugares: "la capital" = las sedes por zona', array( 'https://x.test/z10/' ), array_values( array_filter( array_column( isset( $r['places'] ) ? $r['places'] : $r['links'], 'url' ), function ( $u ) { return false !== strpos( $u, 'x.test/z' ) || false !== strpos( $u, 'coban' ); } ) ) );

// WhatsApp: se saca del sitio.
check( 'wa: enlace con texto', '+50232699880', ASI_WhatsApp::number_from( 'https://wa.me/+50232699880?text=Hola' ) );
check( 'wa: api.whatsapp.com', '+50240461378', ASI_WhatsApp::number_from( 'https://api.whatsapp.com/send?phone=50240461378&text=x' ) );
check( 'wa: número escrito con código', '+50232699880', ASI_WhatsApp::number_from( '+502 3269-9880' ) );
check( 'wa: sin código de país no se adivina', '', ASI_WhatsApp::number_from( '3269-9880' ) );
check( 'wa: enlace sin número', '', ASI_WhatsApp::number_from( 'https://wa.me/message/E2SWHJRS4AQXK1' ) );
$tree = array(
	array( 'elements' => array(
		array( 'settings' => array( 'link' => array( 'url' => 'https://wa.me/50240461378' ) ) ),
		array( 'settings' => array( 'link' => array( 'url' => 'https://wa.me/50240461378' ) ) ),
		array( 'settings' => array( '_position' => 'fixed', 'link' => array( 'url' => 'http://wa.me/+50232699880' ) ) ),
	) ),
);
$links  = ASI_WhatsApp::links_in_elementor( $tree );
$scores = array();
foreach ( $links as $l ) {
	$scores[ $l['number'] ] = ( isset( $scores[ $l['number'] ] ) ? $scores[ $l['number'] ] : 0 ) + ( $l['fixed'] ? 100 : 1 );
}
check( 'wa: el botón flotante gana aunque otro se repita', '+50232699880', ASI_WhatsApp::best( $scores ) );

// Lo que el sitio no tiene, y lo que está en otros sitios del negocio (menú).
$shop = new ASI_Test_Repository();
$shop->add( 70, 'es', 'catalog', 'Toyota 4Runner', array( 'category' => array( 'SUV' ) ), 'Equipo: estribo, llanta de repuesto, aire acondicionado.' );
$shop->add( 71, 'es', 'catalog', 'Nissan Frontier', array( 'category' => array( 'Pick up' ) ), 'Equipo: llanta de repuesto, duraliner.' );
$shop->add( 72, 'es', 'info', 'Contáctanos', array(), 'Escríbenos. Atención al cliente.', array(), array( 'url' => 'https://x.test/contactanos/' ) );
$shop->add( 73, 'es', 'info', 'Seguros', array(), 'Lorem ipsum dolor sit amet, consectetur adipiscing elit.', array(), array( 'url' => 'https://x.test/seguros/' ) );
$menu = array(
	array( 'title' => 'CARRETONES', 'url' => 'https://carretones.test/', 'external' => true, 'status' => 'ok' ),
	array( 'title' => 'NEGOCIOS SOBRE RUEDAS', 'url' => 'https://negocios.test/', 'external' => true, 'status' => 'ok' ),
	array( 'title' => 'ACCESORIOS', 'url' => 'https://x.test/accesorios/', 'external' => false, 'status' => 'construction' ),
);
$nb = new ASI_Dialogue( $shop, array( 'lang' => 'es', 'language' => 'es', 'site_name' => 'Agencia', 'site_links' => $menu, 'whatsapp' => '+50211112222' ), function () {
	return 0;
} );
$r = $nb->handle( 'venden repuestos?', array() );
check( 'no lo tenemos: "venden repuestos?" no muestra vehículos', array(), $r['cards'] );
check( 'no lo tenemos: lo dice claro', true, false !== strpos( $r['text'], 'no contamos con' ) || false !== strpos( $r['text'], 'no tenemos' ) );
$r = $nb->handle( 'tienen motos?', array() );
check( 'no lo tenemos: "tienen motos?"', true, false !== strpos( $r['text'], 'motos' ) && ( false !== strpos( $r['text'], 'no contamos' ) || false !== strpos( $r['text'], 'no tenemos' ) ) );
$r = $nb->handle( 'carros con aire acondicionado', array() );
check( 'característica pedida sí busca', true, (bool) $r['cards'] );
$r = $nb->handle( 'que es negocios sobre ruedas', array() );
check( 'menú: otro sitio del negocio', array( 'https://negocios.test/' ), array_column( $r['links'], 'url' ) );
check( 'menú: se abre aparte', true, ! empty( $r['links'][0]['external'] ) );
check( 'menú: nombre bonito', true, false !== strpos( $r['text'], 'Negocios Sobre Ruedas' ) );
$r = $nb->handle( 'carretones', array() );
check( 'menú: "carretones"', array( 'https://carretones.test/' ), array_column( $r['links'], 'url' ) );
$r = $nb->handle( 'tienen accesorios?', array() );
check( 'menú: sección en construcción = no contamos', true, false !== strpos( $r['text'], 'Accesorios' ) && ! $r['cards'] );
$r = $nb->handle( 'venden seguros?', array() );
check( 'página de relleno no se ofrece', false, in_array( 'https://x.test/seguros/', array_column( $r['links'], 'url' ), true ) );
$adv_menu = new ASI_Dialogue( $yard, array( 'lang' => 'es', 'language' => 'es', 'site_name' => 'Agencia', 'packs' => array( $adv ), 'site_links' => $menu ), function () {
	return 0;
} );
$r = $adv_menu->handle( 'que es negocios sobre ruedas', array() );
check( 'menú: va antes que el asesor por perfil ("negocio" no es trabajo aquí)', array( 'https://negocios.test/' ), array_column( $r['links'], 'url' ) );

// Errores de dedo antes de leer el tipo de vehículo (lo que probó el jefe).
foreach ( array( 'quiero una camioneeta automatica', 'quiero una camioneta automatica', 'camionetas', 'una camionta', 'quiero una camioneta' ) as $m ) {
	$s = array();
	$r = $chat( $m, $s );
	$bodies = array();
	foreach ( $yard->rows( 'es', asi_card_ids( $r ) ) as $row ) {
		$bodies[] = $row['card']['attrs']['body'];
	}
	check( "camioneta no es camión: \"$m\"", array(), array_values( array_diff( array_unique( $bodies ), array( 'suv', 'pickup', 'van' ) ) ) );
	check( "camioneta trae resultados: \"$m\"", true, (bool) $bodies );
}
$q = $an->analyze( 'quiero una camioneeta automatica' );
check( 'typo: se lee el tipo aunque esté mal escrito', array( 'suv', 'pickup', 'van' ), $q['attrs']['body']['in'] );
check( 'typo: y la transmisión', array( 'auto' ), $q['attrs']['transmission']['in'] );
check( 'typo: "picup 4x4"', array( '4x4' ), $an->analyze( 'picup 4x4' )['attrs']['traction']['in'] );
check( 'typo: no corrige montos ni negaciones', array( null, 300000.0 ), array( $an->analyze( 'que no pasen de 300 mil quetzales' )['price_min'], $an->analyze( 'que no pasen de 300 mil quetzales' )['price_max'] ) );

// Barrido de errores de dedo: cada palabra del giro con una letra repetida, una que falta o dos al
// revés debe leerse igual que bien escrita (mismos atributos).
$typo_words = array( 'camioneta', 'pickup', 'automatica', 'automatico', 'mecanica', 'diesel', 'gasolina', 'hibrido', 'electrico', 'sedan', 'hatchback', 'microbus', 'todoterreno', 'crossover', 'pasajeros' );
$variants   = function ( $w ) {
	$out = array();
	for ( $i = 1; $i < strlen( $w ); $i++ ) {
		$out[] = substr( $w, 0, $i ) . $w[ $i ] . substr( $w, $i );               // letra repetida
		if ( $i < strlen( $w ) - 1 ) {
			$out[] = substr( $w, 0, $i ) . substr( $w, $i + 1 );                  // letra que falta
			$out[] = substr( $w, 0, $i ) . $w[ $i + 1 ] . $w[ $i ] . substr( $w, $i + 2 ); // dos al revés
		}
	}
	return array_values( array_unique( array_diff( $out, array( $w ) ) ) );
};
$typo_total = 0;
$typo_ok    = 0;
$typo_bad   = array();
foreach ( $typo_words as $w ) {
	$suffix = 'pasajeros' === $w ? '7 ' : '';
	$want   = $an->analyze( 'quiero ' . $suffix . $w )['attrs'];
	foreach ( $variants( $w ) as $typo ) {
		if ( strlen( $typo ) < 5 ) {
			continue;
		}
		$typo_total++;
		if ( $an->analyze( 'quiero ' . $suffix . $typo )['attrs'] === $want ) {
			$typo_ok++;
		} elseif ( count( $typo_bad ) < 12 ) {
			$typo_bad[] = $typo;
		}
	}
}
check( "errores de dedo: los $typo_total casos se leen igual que bien escritos", array( $typo_total, array() ), array( $typo_ok, $typo_bad ) );
// Y no se "corrigen" palabras reales que no son del catálogo.
foreach ( array( 'quiero algo para la familia', 'es para trabajar en la finca', 'precio en quetzales', 'que no pasen de 300 mil', 'tienen sede en la capital', 'busco algo comodo' ) as $m ) {
	check( "no corrige palabras reales: \"$m\"", array(), $an->analyze( $m )['corrections'] );
}
