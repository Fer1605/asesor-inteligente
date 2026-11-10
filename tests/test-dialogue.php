<?php
$repo = asi_test_delta_repository();
$bot  = asi_test_dialogue( $repo );

// Saludo.
$r = $bot->handle( 'hola' );
check( 'saludo: tipo', 'smalltalk', $r['kind'] );
check( 'saludo: sugiere categorías reales', true, in_array( 'Montacargas', $r['suggestions'], true ) );

// Búsqueda simple: solo El Salvador en español.
$r = $bot->handle( 'busco montacargas' );
check( 'montacargas: resultados', 'results', $r['kind'] );
$ids = asi_card_ids( $r );
sort( $ids );
check( 'montacargas: solo es-sv, nunca otros contextos', array( 1, 2, 6 ), $ids );

// Fidelidad: "eléctrico" deja solo el eléctrico.
$r = $bot->handle( 'montacargas eléctrico' );
check( 'montacargas eléctrico: solo el eléctrico', array( 2 ), asi_card_ids( $r ) );
check( 'montacargas eléctrico: texto habla de una opción', true, false !== mb_stripos( $r['text'], 'una opción' ) || false !== mb_stripos( $r['text'], 'esta opción' ) );

// Error de ortografía.
$r = $bot->handle( 'montacargs' );
check( 'typo: corrige y encuentra', true, in_array( 2, asi_card_ids( $r ), true ) );
check( 'typo: lo dice', true, false !== mb_strpos( $r['text'], '“montacarga”' ) );

// Código de producto.
$r = $bot->handle( '5075E' );
check( 'código: primero el exacto', 3, asi_card_ids( $r )[0] );
check( 'código: solo ese', array( 3 ), asi_card_ids( $r ) );

// Categoría + marca.
$r   = $bot->handle( 'tractores john deere' );
$ids = asi_card_ids( $r );
sort( $ids );
check( 'tractores john deere', array( 3, 4 ), $ids );

// Sinónimo que lleva a una categoría del sitio: refacciones → Repuestos.
$r = $bot->handle( 'refacciones john deere' );
check( 'sinónimo → categoría', array( 7 ), asi_card_ids( $r ) );

// Dos términos críticos: renta + montacargas.
$r = $bot->handle( 'renta de montacargas' );
check( 'renta de montacargas', array( 6 ), asi_card_ids( $r ) );

// Conversación con memoria: buscar, refinar, pedir más, preguntar por uno.
$state = array();
$r     = $bot->handle( 'maquinaria agrícola', $state );
$state = $r['state'];
check( 'memoria: guarda la búsqueda', 'maquinaria agrícola', $state['q'] );
$r     = $bot->handle( 'solo john deere', $state );
check( 'refinar: tipo', 'results', $r['kind'] );
check( 'refinar: lo dice', true, false !== mb_stripos( $r['text'], 'john deere' ) );
$state = $r['state'];
$r     = $bot->handle( 'ver más opciones', $state );
check( 'más: ya no hay', 'no_more', $r['kind'] );
$r = $bot->handle( 'el segundo', $state );
check( 'referencia: detalle', 'detail', $r['kind'] );
check( 'referencia: el segundo de la lista', array( $state['results'][1] ), asi_card_ids( $r ) );
check( 'referencia: ofrece contacto', true, in_array( 'https://example.test/es-sv/10', asi_link_urls( $r ), true ) );

// Información del sitio del mismo país.
$r = $bot->handle( '¿dónde están ubicados?' );
check( 'ubicación: página de sucursales', array( 'https://example.test/es-sv/11' ), asi_link_urls( $r ) );
$r = $bot->handle( 'cuál es su teléfono' );
check( 'contacto: página de contacto', array( 'https://example.test/es-sv/10' ), asi_link_urls( $r ) );
$r = $bot->handle( 'hola, ¿quiénes son?' );
check( 'saludo + pregunta: gana la pregunta', array( 'https://example.test/es-sv/12' ), asi_link_urls( $r ) );

// Cotizar + producto: resultados y enlace de contacto.
$r = $bot->handle( 'quiero cotizar un tractor' );
check( 'cotizar tractor: resultados', 'results', $r['kind'] );
check( 'cotizar tractor: enlace de contacto', true, in_array( 'https://example.test/es-sv/10', asi_link_urls( $r ), true ) );

// Pregunta que responde una página, no un producto.
$r = $bot->handle( '¿tienen garantía?' );
check( 'garantía: página', 'info', $r['kind'] );
check( 'garantía: enlace', array( 'https://example.test/es-sv/13' ), asi_link_urls( $r ) );

// Saludo + búsqueda en el mismo mensaje.
$r = $bot->handle( 'hola buenas, necesito una excavadora' );
check( 'saludo + búsqueda: resultados', array( 5 ), asi_card_ids( $r ) );
check( 'saludo + búsqueda: saluda', true, 0 === mb_strpos( $r['text'], '¡' ) );

// Sin resultados: honesto y con caminos.
$r = $bot->handle( 'helicóptero' );
check( 'sin resultados: tipo', 'none', $r['kind'] );
check( 'sin resultados: sugiere categorías', true, count( $r['suggestions'] ) > 0 );
check( 'sin resultados: ofrece contacto', array( 'https://example.test/es-sv/10' ), asi_link_urls( $r ) );

// Gracias y despedida.
check( 'gracias', 'smalltalk', $bot->handle( 'muchas gracias' )['kind'] );
check( 'adiós', 'smalltalk', $bot->handle( 'adiós' )['kind'] );

// No se repite: dos saludos seguidos con el estado de la conversación.
$a = $bot->handle( 'hola' );
$b = $bot->handle( 'hola', $a['state'] );
check( 'no repite la misma frase', true, $a['text'] !== $b['text'] );

// Escribe en inglés en la página en español: se le ofrece la versión en inglés.
$r = $bot->handle( 'do you have any forklifts for rent please' );
check( 'idioma: ofrece el sitio en inglés', true, in_array( 'https://example.test/en-sv/', asi_link_urls( $r ), true ) );

// Estado de otra página de idioma: se reinicia.
$r = $bot->handle( 'el segundo', array( 'lang' => 'en-sv', 'q' => 'forklift', 'results' => array( 20 ) ) );
check( 'estado de otro contexto: no se usa', true, 'detail' !== $r['kind'] );

// Responder: el país solo se menciona en sitios multipaís.
$lex = ASI_Lexicon::get( 'es' );
$one = new ASI_Responder( $lex, array( 'multi_country' => false, 'country_name' => 'España' ), array(), function () { return 0; } );
check( 'un país: no lo menciona', 'Mmm, no encontré nada para “x”.', $one->say( 'none', array( 'query' => 'x' ) ) );
$multi = new ASI_Responder( $lex, array( 'multi_country' => true, 'country_name' => 'Honduras' ), array(), function () { return 0; } );
check( 'multipaís: lo menciona', 'Mmm, no encontré nada para “x” en Honduras.', $multi->say( 'none', array( 'query' => 'x' ) ) );
check( 'plural', '1 opción', $multi->count_options( 1 ) );
check( 'lista', 'a, b y c', $multi->join_list( array( 'a', 'b', 'c' ) ) );

// Precio.
check( 'precio: "25 mil"', 25000.0, ASI_Query_Analyzer::parse_amount( '25 mil' ) );
check( 'precio: formato', 12500.0, ASI_Search::parse_price( 'Q 12,500.00' ) );
check( 'precio: formato europeo', 1299.9, ASI_Search::parse_price( '$1.299,90' ) );

// Una categoría que tiene casi todo el catálogo ("Venta") no se sugiere.
$r = asi_test_dialogue( asi_test_delta_repository() )->handle( 'hola' );
check( 'no sugiere categorías demasiado generales', false, in_array( 'Venta', $r['suggestions'], true ) );
