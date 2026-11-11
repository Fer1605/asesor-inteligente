<?php
// Barrido (no es test-*.php: lo carga test-actions.php): todas las combinaciones de 2 a 4 opciones (de 8) en varios formatos. Se carga dentro de tests/run.php.
$words = array( 1 => 'primera', 2 => 'segunda', 3 => 'tercera', 4 => 'cuarta', 5 => 'quinta', 6 => 'sexta', 7 => 'septima', 8 => 'octava' );
$join  = function ( array $parts ) {
	$last = array_pop( $parts );
	return implode( ', ', $parts ) . ' y ' . $last;
};
$formats = array(
	'comas'      => function ( $n ) use ( $join ) { return 'cuotas de la ' . $join( $n ); },
	'la cada'    => function ( $n ) use ( $join ) { return 'cuotas de ' . $join( array_map( function ( $x ) { return 'la ' . $x; }, $n ) ); },
	'opcion'     => function ( $n ) use ( $join ) { return 'quiero cuotas de ' . $join( array_map( function ( $x ) { return 'opcion ' . $x; }, $n ) ); },
	'la opcion'  => function ( $n ) use ( $join ) { return 'cuotas de ' . $join( array_map( function ( $x ) { return 'la opcion ' . $x; }, $n ) ); },
	'numeros'    => function ( $n ) use ( $join ) { return 'cuotas ' . $join( $n ); },
	'solo comas' => function ( $n ) { return 'cuotas de la ' . implode( ', ', $n ); },
	'ordinales'  => function ( $n ) use ( $join, $words ) { return 'cuotas de ' . $join( array_map( function ( $x ) use ( $words ) { return 'la ' . $words[ $x ]; }, $n ) ); },
	'numero'     => function ( $n ) use ( $join ) { return 'cuotas del numero ' . $join( $n ); },
);
$combos = array();
$rec = function ( $start, $cur ) use ( &$rec, &$combos ) {
	if ( count( $cur ) >= 2 && count( $cur ) <= 4 ) {
		$combos[] = $cur;
	}
	if ( count( $cur ) === 4 ) return;
	for ( $i = $start; $i <= 8; $i++ ) { $rec( $i + 1, array_merge( $cur, array( $i ) ) ); }
};
$rec( 1, array() );
$base = array(); $say8( 'pick up', $base );
$vis = $base['results'];
foreach ( $formats as $name => $fmt ) {
	$ok = 0; $bad = array();
	foreach ( $combos as $c ) {
		$order = $c; shuffle( $order );
		$s = $base;
		$m = $fmt( $order );
		$r = $bot8->handle( $m, $s );
		$want = array_map( function ( $x ) use ( $vis ) { return $vis[ $x - 1 ]; }, $order );
		$got  = isset( $r['state']['fin_ids'] ) ? $r['state']['fin_ids'] : array();
		if ( $got === $want ) { $ok++; } elseif ( count( $bad ) < 3 ) { $bad[] = $m . ' → ' . implode( ',', array_map( function ( $id ) use ( $vis ) { return array_search( $id, $vis ) + 1; }, $got ) ); }
	}
	check( "listas ($name): las " . count( $combos ) . ' combinaciones de 2 a 4 opciones', count( $combos ), $ok );
}
