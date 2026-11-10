<?php
// Conexiones que solo se rompen en WordPress: cada formulario del panel tiene quién lo reciba,
// y no quedan nombres viejos del renombre (bi_ → asi_).
$code = '';
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ), FilesystemIterator::SKIP_DOTS ) ) as $file ) {
	$path = (string) $file;
	if ( preg_match( '/\.(php|js)$/', $path ) && false === strpos( $path, '/tests/' ) && false === strpos( $path, '/.git/' ) ) {
		$code .= file_get_contents( $path ) . "\n";
	}
}
preg_match_all( '/name="action" value="([a-z_]+)"/', $code, $m );
foreach ( array_unique( $m[1] ) as $action ) {
	check( "panel: el formulario \"{$action}\" tiene quién lo reciba", true, false !== strpos( $code, "'admin_post_{$action}'" ) );
}
check( 'renombre: no quedan "bi_" de la versión anterior', array(), array_values( array_unique( preg_match_all( '/[\'"_](bi_[a-z_]+)|[a-z]_bi_[a-z]/', $code, $left ) ? $left[0] : array() ) ) );
