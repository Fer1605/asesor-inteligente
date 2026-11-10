<?php
// Respuestas con datos, preguntas de producto, comparaciones, precios, negaciones, aprendizaje y tono.

// --- Frases que responden ---
$sentences = ASI_Extractor::sentences( "Horario\nLunes a viernes de 8:00 a 17:00. Sábados de 8:00 a 12:00." );
check( 'frases: el título corto se une a la frase', 'Horario: Lunes a viernes de 8:00 a 17:00', $sentences[0] );
check( 'frases: elige la que responde', array( 'Horario: Lunes a viernes de 8:00 a 17:00.', 'Sábados de 8:00 a 12:00.' ), ASI_Extractor::best( "Somos una empresa con 50 años.\nHorario\nLunes a viernes de 8:00 a 17:00. Sábados de 8:00 a 12:00.", array(), array( 'horario', 'lunes', 'viernes', 'sabado' ), 2 ) );
check( 'frases: sin palabras de la pregunta no inventa', array(), ASI_Extractor::best( 'Vendemos maquinaria agrícola desde 1950.', array( 'garantia' ), array(), 1 ) );

// --- Precios como número ---
check( 'precio: Q 1,250.00', 1250.0, ASI_Dialogue::parse_price( 'Q 1,250.00' ) );
check( 'precio: $95', 95.0, ASI_Dialogue::parse_price( '$95' ) );
check( 'precio: ₡12 500', 12500.0, ASI_Dialogue::parse_price( '₡12 500' ) );
check( 'precio: 1.250,50', 1250.5, ASI_Dialogue::parse_price( '1.250,50' ) );
check( 'precio: sin número', 0.0, ASI_Dialogue::parse_price( 'Consultar' ) );

// --- Sitio de prueba con fichas completas ---
$repo = asi_test_delta_repository();
$repo->set_details( 3, array( array( 'Potencia', '75 HP' ), array( 'Peso', '3,200 kg' ), array( 'Tracción', '4x4' ) ), 'Tractor utilitario de 75 HP para trabajo agrícola. Incluye cabina con aire acondicionado. Garantía de 2 años o 2,000 horas.' );
$repo->set_details( 4, array( array( 'Potencia', '110 HP' ), array( 'Peso', '4,500 kg' ), array( 'Tracción', '4x4' ) ), 'Tractor de 110 HP con cabina.' );
$repo->add( 14, 'es-sv', 'info', 'Horarios', array(), 'Horarios de atención' );
$repo->set_details( 14, array(), "Horario de atención\nLunes a viernes de 8:00 a 17:00. Sábados de 8:00 a 12:00." );
$repo->set_details( 10, array(), "Escríbenos o llámanos.\nTeléfono: 2222-0000\nCorreo: ventas@example.com" );
$repo->set_details( 12, array(), 'Somos una empresa salvadoreña con más de 100 años de historia. Nuestra misión es impulsar el desarrollo del país.' );

$bot = asi_test_dialogue( $repo );
$r   = $bot->handle( '¿cuánto pesa el tractor 5075E?' );
check( 'dato: responde el peso', true, 'fact' === $r['kind'] && false !== mb_strpos( $r['text'], '3,200 kg' ) );
check( 'dato: muestra la tarjeta', array( 3 ), asi_card_ids( $r ) );

$r2 = $bot->handle( '¿y qué potencia tiene?', $r['state'] );
check( 'dato de seguimiento: del mismo producto', true, false !== mb_strpos( $r2['text'], '75 HP' ) );

$r3 = $bot->handle( '¿trae aire acondicionado?', $r2['state'] );
check( 'sí/no: cita la descripción', true, 'fact' === $r3['kind'] && $r3['quote'] && false !== mb_strpos( $r3['quote'][0], 'aire acondicionado' ) );

$r4 = $bot->handle( '¿tiene GPS?', $r3['state'] );
check( 'dato que no está: lo dice con honestidad', true, false !== mb_strpos( $r4['text'], 'No encontré' ) || false !== mb_strpos( $r4['text'], 'no menciona' ) );

$r5 = $bot->handle( '¿tiene tractores?', $r3['state'] );
check( 'sí/no que nombra productos: es una búsqueda', 'results', $r5['kind'] );

$r = $bot->handle( '¿cuánto cuesta el tractor 6110J?' );
check( 'precio no publicado: ofrece cotizar', true, 'fact' === $r['kind'] && (bool) $r['links'] );

$search = $bot->handle( 'tractores' );
$r      = $bot->handle( '¿cuánto pesa el segundo?', $search['state'] );
check( 'dato por posición en la lista', true, 'fact' === $r['kind'] && 1 === count( $r['cards'] ) );

// --- Comparar ---
$r = $bot->handle( 'diferencia entre el 5075E y el 6110J' );
check( 'comparar: tabla', 'compare', $r['kind'] );
$labels = array_column( $r['compare']['rows'], 0 );
check( 'comparar: incluye la potencia', true, in_array( 'Potencia', $labels, true ) );
check( 'comparar: dice la diferencia principal', true, false !== mb_strpos( $r['text'], '75 HP' ) && false !== mb_strpos( $r['text'], '110 HP' ) );
$r = $bot->handle( 'compáralos', $search['state'] );
check( 'comparar los de la lista', 'compare', $r['kind'] );
$r = $bot->handle( 'compáralo con el 6110J', $r2['state'] );
check( 'comparar el que se ve con otro', array( 3, 4 ), asi_card_ids( $r ) );
$r = $bot->handle( 'compara' );
check( 'comparar sin saber cuáles: pregunta', 'clarify', $r['kind'] );

// --- Negación ---
$r   = $bot->handle( 'montacargas que no sean eléctricos' );
$ids = asi_card_ids( $r );
sort( $ids );
check( 'negación: quita los eléctricos', array( 1, 6 ), $ids );
check( 'negación: lo dice', true, false !== mb_strpos( $r['text'], 'eléctricos' ) );
$first = $bot->handle( 'montacargas' );
$r     = $bot->handle( 'pero que no sea eléctrico', $first['state'] );
check( 'negación como refinamiento', false, in_array( 2, asi_card_ids( $r ), true ) );

// --- Páginas: la frase que responde ---
$r = $bot->handle( '¿cuál es su horario?' );
check( 'horario: cita la frase', true, $r['quote'] && false !== mb_strpos( implode( ' ', $r['quote'] ), 'Lunes a viernes' ) );
$r = $bot->handle( '¿cuál es su teléfono?' );
check( 'contacto: cita el teléfono', true, $r['quote'] && false !== mb_strpos( implode( ' ', $r['quote'] ), '2222-0000' ) );
$r = $bot->handle( '¿quiénes son ustedes?' );
check( 'quiénes somos: cita quiénes son', true, $r['quote'] && false !== mb_strpos( $r['quote'][0], 'Somos' ) );

// --- Descripción con la ficha ---
$r = $bot->handle( 'excavadora' );
check( 'un resultado: lo describe', true, false !== mb_strpos( $r['text'], 'excavadoras' ) );

// --- El más barato / el más caro ---
$shop = new ASI_Test_Repository();
$shop->add( 1, 'es', 'catalog', 'Camiseta básica', array( 'category' => array( 'Camisetas' ) ), 'Algodón.', array(), array( 'price' => 'Q 95.00' ) );
$shop->add( 2, 'es', 'catalog', 'Camiseta deportiva', array( 'category' => array( 'Camisetas' ) ), 'Dry fit.', array(), array( 'price' => 'Q 250.00' ) );
$shop->add( 3, 'es', 'catalog', 'Camiseta premium', array( 'category' => array( 'Camisetas' ) ), 'Pima.', array(), array( 'price' => 'Q 1,200.00' ) );
$shop->add( 4, 'es', 'catalog', 'Gorra', array( 'category' => array( 'Accesorios' ) ), 'Ajustable.', array(), array( 'price' => 'Q 60.00' ) );
$shop_bot = new ASI_Dialogue( $shop, array( 'lang' => 'es', 'language' => 'es' ) );
$r = $shop_bot->handle( '¿cuál es la camiseta más barata?' );
check( 'el más barato', array( 1 ), asi_card_ids( $r ) );
check( 'el más barato: dice el precio', true, false !== mb_strpos( $r['text'], 'Q 95.00' ) );
$list = $shop_bot->handle( 'camisetas' );
$r    = $shop_bot->handle( 'el más caro', $list['state'] );
check( 'el más caro de la búsqueda anterior', array( 3 ), asi_card_ids( $r ) );

// --- Aprendizaje: respuestas enseñadas y clics ---
$lexicon = ASI_Lexicon::get( 'es' );
$analyzer = new ASI_Query_Analyzer( $lexicon, $repo->vocabulary( 'es-sv' ) );
check( 'clave: igual con plurales, acentos y orden', ASI_Dialogue::content_key( $analyzer, $lexicon, 'Busco montacargas eléctricos' ), ASI_Dialogue::content_key( $analyzer, $lexicon, 'eléctrico montacargas' ) );
$taught  = new ASI_Dialogue( $repo, array( 'lang' => 'es-sv', 'language' => 'es', 'learned' => array( ASI_Dialogue::content_key( $analyzer, $lexicon, 'carretilla para bodega' ) => array( 2 ) ) ) );
$r       = $taught->handle( 'Carretilla para bodega' );
check( 'respuesta enseñada', array( 2 ), asi_card_ids( $r ) );
$r = asi_test_dialogue( $repo )->handle( 'montacargas' );
$before = asi_card_ids( $r );
$repo->clicks[ ASI_Dialogue::content_key( $analyzer, $lexicon, 'montacargas' ) ] = array( 6 => 12 );
$r = asi_test_dialogue( $repo )->handle( 'montacargas' );
check( 'clics: lo más abierto sube', 6, asi_card_ids( $r )[0] );
check( 'clics: no agrega resultados', count( $before ), count( asi_card_ids( $r ) ) );
$repo->clicks = array();

// --- Tono: usted y emojis ---
$tone   = new ASI_Tone( $lexicon, 'usted' );
$leftover = array();
$walk   = function ( $node ) use ( &$walk, $tone, &$leftover ) {
	foreach ( (array) $node as $item ) {
		if ( is_array( $item ) ) {
			$walk( $item );
			continue;
		}
		$converted = $tone->template( $item );
		if ( preg_match( '/(?<![\p{L}])(tú|tu|tus|te|estás|buscas|quieres|puedes|tienes|necesitas|cuéntame|escríbeme|escríbenos|dime|dame|mira|pediste|prefieres)(?![\p{L}])/iu', $converted, $m ) ) {
			$leftover[] = $m[1] . ' ← ' . $converted;
		}
	}
};
$walk( $lexicon->get_list( 'responses' ) );
$walk( $lexicon->get_list( 'ui' ) );
check( 'usted: ninguna plantilla queda en tú', array(), $leftover );
check( 'usted: conserva marcadores', '¿Le ayudo con {title}?', $tone->template( '¿Te ayudo con {title}?' ) );
$formal = new ASI_Dialogue( $repo, array( 'lang' => 'es-sv', 'language' => 'es', 'tone' => 'usted', 'emojis' => true ) );
$r      = $formal->handle( 'gracias' );
check( 'emojis: al final del agradecimiento', true, false !== mb_strpos( $r['text'], '😊' ) );
check( 'usted en la conversación', false, (bool) preg_match( '/(?<![\p{L}])(te|tú|puedes|quieres)(?![\p{L}])/iu', $r['text'] ) );

// --- Preguntar para afinar ---
$many = new ASI_Test_Repository();
foreach ( range( 1, 10 ) as $i ) {
	$many->add( $i, 'es', 'catalog', 'Montacargas modelo ' . $i, array( 'category' => array( 'Montacargas', $i <= 5 ? 'Eléctricos' : 'Combustión' ) ), 'Equipo.' );
}
$r = ( new ASI_Dialogue( $many, array( 'lang' => 'es', 'language' => 'es' ) ) )->handle( 'montacargas' );
check( 'afinar: pregunta con "o"', true, false !== mb_strpos( $r['followup'], ' o ' ) );
check( 'afinar: ofrece las líneas', 2, count( $r['suggestions'] ) );
