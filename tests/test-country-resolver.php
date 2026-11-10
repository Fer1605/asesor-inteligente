<?php
// Caso real: Grupo Delta (Polylang, 6 países × 2 idiomas). "en-gt" tiene locale en_US.
$delta = array(
	array( 'slug' => 'es-gt', 'locale' => 'es_GT' ),
	array( 'slug' => 'en-gt', 'locale' => 'en_US' ),
	array( 'slug' => 'es-cr', 'locale' => 'es_CR' ),
	array( 'slug' => 'en-cr', 'locale' => 'en_CR' ),
	array( 'slug' => 'es-sv', 'locale' => 'es_SV' ),
	array( 'slug' => 'en-sv', 'locale' => 'en_SV' ),
	array( 'slug' => 'es-pa', 'locale' => 'es_PA' ),
	array( 'slug' => 'en-pa', 'locale' => 'en_PA' ),
	array( 'slug' => 'en-hn', 'locale' => 'en_HN' ),
	array( 'slug' => 'es-hn', 'locale' => 'es_HN' ),
	array( 'slug' => 'en-ni', 'locale' => 'en_NI' ),
	array( 'slug' => 'es-ni', 'locale' => 'es_NI' ),
);
check(
	'Delta: 12 idiomas → 6 países',
	array( 'es-gt' => 'gt', 'en-gt' => 'gt', 'es-cr' => 'cr', 'en-cr' => 'cr', 'es-sv' => 'sv', 'en-sv' => 'sv', 'es-pa' => 'pa', 'en-pa' => 'pa', 'en-hn' => 'hn', 'es-hn' => 'hn', 'en-ni' => 'ni', 'es-ni' => 'ni' ),
	ASI_Country_Resolver::resolve( $delta )
);

// Orden invertido en el slug ("cr-es"), como en algunas categorías de Delta.
check( 'slug invertido cr-es', 'cr', ASI_Country_Resolver::country_from_slug( 'cr-es', 'es_CR' ) );
check( 'slug con guion bajo', 'gt', ASI_Country_Resolver::country_from_slug( 'en_gt', 'en_US' ) );
check( 'slug con sufijo numérico', 'sv', ASI_Country_Resolver::country_from_slug( 'es-sv-2', 'es_SV' ) );
check( 'slug sin locale', 'sv', ASI_Country_Resolver::country_from_slug( 'es-sv', '' ) );
check( '"es" solo es idioma, no España', '', ASI_Country_Resolver::country_from_slug( 'es', 'es_ES' ) );

// Sitio típico de un solo país con 2 idiomas: no hay filtro de país.
check(
	'es/en en sitio de un país',
	array( 'es' => '', 'en' => '' ),
	ASI_Country_Resolver::resolve( array( array( 'slug' => 'es', 'locale' => 'es_ES' ), array( 'slug' => 'en', 'locale' => 'en_US' ) ) )
);

// Todos los locales comparten región → ese país.
check(
	'es_MX + en_MX sin país en slug',
	array( 'es' => 'mx', 'en' => 'mx' ),
	ASI_Country_Resolver::resolve( array( array( 'slug' => 'es', 'locale' => 'es_MX' ), array( 'slug' => 'en', 'locale' => 'en_MX' ) ) )
);

// Mixto: un idioma sin país en el slug pero cuyo locale coincide con un país del sitio.
check(
	'mixto: "es" con locale es_GT en sitio con es-sv',
	array( 'es' => 'gt', 'en-gt' => 'gt', 'es-sv' => 'sv' ),
	ASI_Country_Resolver::resolve(
		array(
			array( 'slug' => 'es', 'locale' => 'es_GT' ),
			array( 'slug' => 'en-gt', 'locale' => 'en_US' ),
			array( 'slug' => 'es-sv', 'locale' => 'es_SV' ),
		)
	)
);

// Mixto: el locale apunta a un país que el sitio no usa → idioma global.
check(
	'mixto: "en" con en_US en sitio multipaís sin US',
	array( 'en' => '', 'es-sv' => 'sv' ),
	ASI_Country_Resolver::resolve( array( array( 'slug' => 'en', 'locale' => 'en_US' ), array( 'slug' => 'es-sv', 'locale' => 'es_SV' ) ) )
);

check( 'alias uk → gb', 'gb', ASI_Country_Resolver::country_from_slug( 'en-uk', 'en_GB' ) );
