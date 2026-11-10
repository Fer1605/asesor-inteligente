<?php
check( 'acentos y signos', 'montacargas un forklifts fgl25t fgl35t', ASI_Normalizer::normalize( 'Montacargas UN FORKLIFTS “FGL25T FGL35T”' ) );
check( 'conserva ñ', 'año diseño', ASI_Normalizer::normalize( 'Año, Diseño' ) );
check( 'entidades html', 'tractor john deere', ASI_Normalizer::normalize( 'Tractor&nbsp;<b>John</b> Deere' ) );
check( 'tokens con stopwords', array( 'tractor', 'agricola' ), ASI_Normalizer::tokens( 'un tractor para agrícola', array( 'un' => 1, 'para' => 1 ) ) );
check( 'código compacto', 'fgl25t', ASI_Normalizer::compact_code( 'FGL-25 T' ) );
check( 'parece código', true, (bool) ASI_Normalizer::looks_like_code( '5075E' ) );
check( 'no es código', false, (bool) ASI_Normalizer::looks_like_code( 'tractor' ) );
check( 'singular tractores', 'tractor', ASI_Normalizer::singular( 'tractores' ) );
check( 'singular camiones', 'camion', ASI_Normalizer::singular( 'camiones' ) );
check( 'singular forklifts', 'forklift', ASI_Normalizer::singular( 'forklifts' ) );
check( 'singular accessories', 'accessory', ASI_Normalizer::singular( 'accessories' ) );
check( 'singular mismo en ambos lados', ASI_Normalizer::singular( 'montacargas' ), ASI_Normalizer::singular( 'montacarga' ) );
check( 'no toca códigos', 'fb20s', ASI_Normalizer::singular( 'fb20s' ) );
