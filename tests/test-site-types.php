<?php
// Inglés en el mismo sitio multipaís: solo contenido en-sv.
$repo = asi_test_delta_repository();
$bot  = asi_test_dialogue( $repo, 'en-sv', 'en' );
$r    = $bot->handle( 'looking for an electric forklift' );
check( 'inglés: solo en-sv', array( 20 ), asi_card_ids( $r ) );
check( 'inglés: responde en inglés', true, (bool) preg_match( '/\b(found|Here|option|match)\b/i', $r['text'] ) );

// Tienda WooCommerce de un solo país (sin plugin multilingüe): precios, SKU, variaciones.
$shop = new ASI_Test_Repository();
$shop->add( 1, 'es', 'catalog', 'Camiseta básica algodón', array( 'category' => array( 'Camisetas', 'Ropa' ), 'attribute' => array( 'Rojo', 'Azul' ) ), 'Camiseta 100% algodón.', array( 'CAM-001', 'CAM-001-R' ), array( 'price' => 'Q 95.00' ) );
$shop->add( 2, 'es', 'catalog', 'Camiseta deportiva dry fit', array( 'category' => array( 'Camisetas', 'Ropa' ), 'flag' => array( 'Oferta' ) ), 'Tela que absorbe el sudor.', array( 'CAM-002' ), array( 'price' => 'Q 250.00' ) );
$shop->add( 3, 'es', 'catalog', 'Zapatos casuales de cuero', array( 'category' => array( 'Calzado' ) ), 'Cuero genuino.', array( 'ZAP-010' ), array( 'price' => 'Q 480.00' ) );
$shop->add( 4, 'es', 'catalog', 'Gorra bordada', array( 'category' => array( 'Accesorios' ) ), 'Ajustable.', array( 'GOR-5' ), array( 'price' => 'Q 60.00' ) );
$shop->add( 5, 'es', 'catalog', 'Pantalón jeans', array( 'category' => array( 'Pantalones', 'Ropa' ) ), 'Mezclilla.', array( 'PAN-7' ), array( 'price' => 'Q 300.00' ) );
$shop->add( 9, 'es', 'info', 'Contacto', array(), 'Escríbenos.' );
$seed = 0;
$shop_bot = new ASI_Dialogue(
	$shop,
	array( 'lang' => 'es', 'language' => 'es', 'site_name' => 'Tienda', 'country_name' => 'Guatemala', 'multi_country' => false ),
	function ( $max ) use ( &$seed ) {
		return $seed++ % ( $max + 1 );
	}
);
$r = $shop_bot->handle( 'camisetas de menos de 200' );
check( 'tienda: filtro de precio', array( 1 ), asi_card_ids( $r ) );
check( 'tienda: un país, no lo menciona', false, false !== mb_strpos( $r['text'] . $r['followup'], 'Guatemala' ) );
$r = $shop_bot->handle( 'playeras' );
$ids = asi_card_ids( $r );
sort( $ids );
check( 'tienda: sinónimo playera → camiseta', array( 1, 2 ), $ids );
$r = $shop_bot->handle( 'CAM-001-R' );
check( 'tienda: SKU de variación', array( 1 ), asi_card_ids( $r ) );
$r = $shop_bot->handle( 'camisetas en oferta' );
check( 'tienda: distintivo oferta', array( 2 ), asi_card_ids( $r ) );
$r = $shop_bot->handle( 'zapatos rojos' );
check( 'tienda: sin coincidencia exacta avisa', 'results', $r['kind'] );
check( 'tienda: muestra lo más parecido', array( 3 ), asi_card_ids( $r ) );

// Sitio solo informativo: páginas y entradas, sin catálogo.
$info = new ASI_Test_Repository();
$info->add( 1, 'es', 'info', 'Servicios de consultoría', array(), 'Consultoría legal y contable para empresas.' );
$info->add( 2, 'es', 'info', 'Horarios de atención', array(), 'Lunes a viernes de 8 a 17 horas.' );
$info->add( 3, 'es', 'info', 'Contacto', array(), 'Teléfono y correo.' );
$info->add( 4, 'es', 'article', 'Cambios en la ley tributaria 2026', array( 'category' => array( 'Noticias' ) ), 'Lo que tu empresa debe saber sobre la nueva ley tributaria.' );
$info_bot = new ASI_Dialogue( $info, array( 'lang' => 'es', 'language' => 'es', 'site_name' => 'Despacho' ) );
$r        = $info_bot->handle( '¿a qué hora abren?' );
check( 'informativo: horario', array( 'https://example.test/es/2' ), asi_link_urls( $r ) );
$r = $info_bot->handle( 'necesito consultoría contable' );
check( 'informativo: página de servicio', 'info', $r['kind'] );
check( 'informativo: enlace correcto', 'https://example.test/es/1', asi_link_urls( $r )[0] );
$r = $info_bot->handle( 'ley tributaria' );
check( 'informativo: artículo', 'https://example.test/es/4', asi_link_urls( $r )[0] );

// Agencia de autos: código dentro del JSON, marca y distintivo.
$cars = new ASI_Test_Repository();
$cars->add( 1, 'es', 'catalog', 'Toyota Hilux 2022', array( 'category' => array( 'Pick-up' ), 'brand' => array( 'Toyota' ), 'flag' => array( 'Oferta' ) ), 'Motor 2.8 diésel, transmisión automática.', array( 'GM-1234' ) );
$cars->add( 2, 'es', 'catalog', 'Toyota Corolla 2020', array( 'category' => array( 'Sedán' ), 'brand' => array( 'Toyota' ) ), 'Motor 1.8, transmisión automática.', array( 'GM-2001' ) );
$cars->add( 3, 'es', 'catalog', 'Nissan Frontier 2021', array( 'category' => array( 'Pick-up' ), 'brand' => array( 'Nissan' ) ), 'Motor 2.5 diésel, transmisión manual.', array( 'GM-3100' ) );
$cars_bot = new ASI_Dialogue( $cars, array( 'lang' => 'es', 'language' => 'es', 'site_name' => 'Autos' ) );
check( 'autos: código del JSON', array( 1 ), asi_card_ids( $cars_bot->handle( 'GM-1234' ) ) );
check( 'autos: pickup toyota', array( 1 ), asi_card_ids( $cars_bot->handle( 'pick-up toyota' ) ) );
check( 'autos: ofertas', array( 1 ), asi_card_ids( $cars_bot->handle( 'autos en oferta' ) ) );
$ids = asi_card_ids( $cars_bot->handle( 'carros automáticos' ) );
sort( $ids );
check( 'autos: palabra en la descripción', array( 1, 2 ), $ids );

// Para quién: "hombre" encuentra "Cuidado masculino" (caso real de La Garza).
$care = new ASI_Test_Repository();
$care->add( 1, 'es', 'catalog', 'Desodorante roll-on', array( 'category' => array( 'Desodorantes', 'Cuidado masculino' ) ), 'Protección 48 h.' );
$care->add( 2, 'es', 'catalog', 'Desodorante en barra', array( 'category' => array( 'Desodorantes', 'Cuidado femenino' ) ), 'Protección 24 h.' );
$care->add( 3, 'es', 'catalog', 'Crema de afeitar', array( 'category' => array( 'Cuidado masculino' ) ), 'Piel suave.' );
$care_bot = new ASI_Dialogue( $care, array( 'lang' => 'es', 'language' => 'es', 'site_name' => 'Tienda' ) );
$r = $care_bot->handle( 'tienen desodorantes para hombre?' );
check( 'para quién: hombre → masculino', array( 1 ), asi_card_ids( $r ) );
check( 'para quién: coincidencia exacta', false, false !== mb_strpos( $r['text'], 'al pie de la letra' ) );

// Nombre exacto: "producto 2" muestra "Producto 2", no todo lo que tenga un 2 (caso real de un staging).
$names = new ASI_Test_Repository();
$names->add( 1, 'es', 'catalog', 'Producto 1', array( 'category' => array( 'Cremas' ) ), 'Crema de 250 ml, pack x2.' );
$names->add( 2, 'es', 'catalog', 'Producto 2', array( 'category' => array( 'Cremas' ) ), 'Crema hidratante.' );
$names->add( 3, 'es', 'catalog', 'Producto 2 of', array( 'category' => array( 'Cremas' ) ), 'Crema en oferta.' );
$names->add( 4, 'es', 'info', 'Ubicación 2', array(), 'Zona 2.' );
$names_bot = new ASI_Dialogue( $names, array( 'lang' => 'es', 'language' => 'es', 'site_name' => 'Tienda' ) );
$r = $names_bot->handle( 'producto 2' );
check( 'nombre exacto: solo ese', array( 2 ), asi_card_ids( $r ) );
check( 'nombre exacto: similares como tarjetas', array( 3 ), array_column( $r['extra']['cards'], 'id' ) );
check( 'nombre exacto: los presenta', true, '' !== $r['extra']['text'] );
check( 'nombre exacto: "el segundo" es el similar', array( 2, 3 ), $r['state']['results'] );
check( 'nombre exacto: sin páginas que solo comparten el número', array(), asi_link_urls( $r ) );
check( 'nombre exacto: dice lo que pidió', true, false !== mb_stripos( $r['text'], 'producto 2' ) );
$r = $names_bot->handle( 'quiero el producto 2 of' );
check( 'nombre exacto largo', array( 3 ), asi_card_ids( $r ) );

// Una sola coincidencia sin nombres parecidos: similares de la misma categoría.
$cat = new ASI_Test_Repository();
$cat->add( 1, 'es', 'catalog', 'Shampoo anticaspa', array( 'category' => array( 'Cuidado capilar' ) ), 'Limpieza profunda.' );
$cat->add( 2, 'es', 'catalog', 'Acondicionador reparador', array( 'category' => array( 'Cuidado capilar' ) ), 'Suaviza.' );
$cat->add( 3, 'es', 'catalog', 'Mascarilla capilar', array( 'category' => array( 'Cuidado capilar' ) ), 'Nutre.' );
$cat->add( 4, 'es', 'catalog', 'Esmalte rojo', array( 'category' => array( 'Uñas' ) ), 'Brillo.' );
$r = ( new ASI_Dialogue( $cat, array( 'lang' => 'es', 'language' => 'es' ) ) )->handle( 'shampoo anticaspa' );
check( 'similares por categoría: principal', array( 1 ), asi_card_ids( $r ) );
$similar_ids = array_column( $r['extra']['cards'], 'id' );
sort( $similar_ids );
check( 'similares por categoría: misma categoría', array( 2, 3 ), $similar_ids );
check( 'similares: siempre cierra invitando', true, '' !== $r['followup'] );

// Ubicaciones: general → página; zona concreta → esa tarjeta; listado → todas en tarjetas.
$places = new ASI_Test_Repository();
$places->add( 1, 'es', 'info', 'Tiendas', array(), 'Encuentra nuestras tiendas y horarios.' );
$places->add( 2, 'es', 'info', 'Tienda Zona 10', array( 'category' => array( 'Tienda' ) ), 'Dirección 12 avenida 4-56 zona 10 Guatemala. Horario lunes a sábado.' );
$places->add( 3, 'es', 'info', 'Tienda Mixco', array( 'category' => array( 'Tienda' ) ), 'Dirección centro comercial Mixco. Horario lunes a domingo.' );
$places->add( 4, 'es', 'info', 'Tienda Occidente', array( 'category' => array( 'Tienda' ) ), 'Dirección 4a calle zona 3 Quetzaltenango.' );
foreach ( array( 2, 3, 4 ) as $id ) {
	$places->rows[ $id ]['post_type'] = 'sucursal_mapa';
}
$places_bot = new ASI_Dialogue( $places, array( 'lang' => 'es', 'language' => 'es', 'location_types' => array( 'sucursal_mapa' ) ) );
$r = $places_bot->handle( '¿dónde están ubicados?' );
check( 'ubicación general: solo la página', array( 'https://example.test/es/1' ), asi_link_urls( $r ) );
check( 'ubicación general: sin tarjetas de lugar', true, empty( $r['places'] ) );
$r = $places_bot->handle( 'tienen tienda en zona 10?' );
check( 'zona concreta: esa ubicación', array( 'Tienda Zona 10' ), array_column( $r['places'], 'title' ) );
$r = $places_bot->handle( 'sucursal en mixco' );
check( 'nombre concreto: esa ubicación', array( 'Tienda Mixco' ), array_column( $r['places'], 'title' ) );
$r = $places_bot->handle( 'tienen tienda en quetzaltenango' );
check( 'ciudad en la dirección', array( 'Tienda Occidente' ), array_column( $r['places'], 'title' ) );
$r = $places_bot->handle( '¿qué zonas tienen?' );
check( 'listado: todas', 3, count( $r['places'] ) );
$r = $places_bot->handle( 'tienen tienda en Petén?' );
check( 'lugar sin tienda: lo dice y muestra todas', 3, count( $r['places'] ) );
check( 'lugar sin tienda: nombra el lugar como lo escribió', true, false !== mb_strpos( $r['text'], 'Petén' ) );

// Enlace de ubicación: a la página del mapa con la ubicación marcada, no a su ficha sin diseño.
$r = $places_bot->handle( 'sucursal en mixco' );
check( 'ubicación: enlaza al mapa con la ubicación', array( 'https://example.test/es/1?asi_place=3' ), array_column( $r['places'], 'url' ) );
$no_map = new ASI_Test_Repository();
$no_map->add( 3, 'es', 'info', 'Tienda Mixco', array(), 'Dirección centro comercial Mixco.' );
$no_map->rows[3]['post_type'] = 'sucursal_mapa';
$r = ( new ASI_Dialogue( $no_map, array( 'lang' => 'es', 'language' => 'es', 'location_types' => array( 'sucursal_mapa' ) ) ) )->handle( 'sucursal en mixco' );
check( 'ubicación sin página de mapa: su propio enlace', array( 'https://example.test/es/3' ), array_column( $r['places'], 'url' ) );

// Preguntas por el propio sitio: van a quiénes somos, sin importar cómo se llame el sitio.
$about = new ASI_Test_Repository();
$about->add( 1, 'es', 'info', 'Quiénes somos', array(), 'Nuestra misión, visión y valores.' );
$about->add( 2, 'es', 'info', 'Contacto', array(), 'Escríbenos.' );
$about->add( 3, 'es', 'catalog', 'Shampoo Garza Brillo', array( 'category' => array( 'Cuidado capilar' ) ), 'Brillo.' );
$about_bot = new ASI_Dialogue( $about, array( 'lang' => 'es', 'language' => 'es', 'site_name' => 'La Garza' ) );
foreach ( array( '¿Quién es La Garza?', 'qué es almacén la garza', 'cuéntame sobre la garza', '¿Cuál es su misión?', 'misión y visión', '¿quiénes son ustedes?' ) as $question ) {
	$r = $about_bot->handle( $question );
	check( 'sobre el sitio: ' . $question, array( 'https://example.test/es/1' ), asi_link_urls( $r ) );
}
$r = $about_bot->handle( 'shampoo garza' );
check( 'nombre del sitio en una búsqueda: sigue buscando', array( 3 ), asi_card_ids( $r ) );
$r = $about_bot->handle( 'la garza' );
check( 'solo el nombre, sin pregunta: no fuerza quiénes somos', false, 'info' === $r['kind'] && array( 'https://example.test/es/1' ) === asi_link_urls( $r ) );
$delta_about = new ASI_Test_Repository();
$delta_about->add( 1, 'en-gt', 'info', 'About us', array(), 'Our mission and vision.' );
$r = ( new ASI_Dialogue( $delta_about, array( 'lang' => 'en-gt', 'language' => 'en', 'site_name' => 'Grupo Delta' ) ) )->handle( 'what is delta?' );
check( 'about the site (en, parte del nombre)', array( 'https://example.test/en-gt/1' ), asi_link_urls( $r ) );
