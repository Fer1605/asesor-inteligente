<?php
/**
 * Pruebas de la lógica pura, sin WordPress.
 * Uso: php tests/run.php
 *
 * Este archivo no se sube a producción.
 */

define( 'ASI_CLI_TESTS', true );

$root = dirname( __DIR__ );
require $root . '/includes/i18n/class-asi-country-resolver.php';
require $root . '/includes/text/class-asi-normalizer.php';
require $root . '/includes/text/class-asi-money.php';
require $root . '/includes/discovery/class-asi-field-classifier.php';
require $root . '/includes/index/class-asi-elementor-text.php';
require $root . '/includes/discovery/class-asi-json-paths.php';
require $root . '/includes/engine/class-asi-lexicon.php';
require $root . '/includes/engine/class-asi-query-analyzer.php';
require $root . '/includes/engine/class-asi-search.php';
require $root . '/includes/engine/class-asi-vocabulary.php';
require $root . '/includes/dialogue/class-asi-intent-classifier.php';
require $root . '/includes/engine/class-asi-extractor.php';
require $root . '/includes/dialogue/class-asi-tone.php';
require $root . '/includes/dialogue/class-asi-responder.php';
require $root . '/includes/dialogue/trait-asi-dialogue-products.php';
require $root . '/includes/dialogue/class-asi-dialogue.php';
require $root . '/includes/extend/interface-asi-connector.php';
require $root . '/includes/extend/class-asi-extensions.php';
require __DIR__ . '/fixtures.php';

$failures = 0;
$total    = 0;

function check( $label, $expected, $actual ) {
	global $failures, $total;
	$total++;
	if ( $expected === $actual ) {
		return;
	}
	$failures++;
	echo "✗ {$label}\n  esperado: " . var_export( $expected, true ) . "\n  obtenido: " . var_export( $actual, true ) . "\n";
}

foreach ( glob( __DIR__ . '/test-*.php' ) as $file ) {
	require $file;
}

echo ( $failures ? "\n{$failures} de {$total} pruebas fallaron.\n" : "{$total} pruebas OK.\n" );
exit( $failures ? 1 : 0 );
