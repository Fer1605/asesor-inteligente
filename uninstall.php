<?php
/**
 * Desinstalación: borra la tabla del índice y las opciones del plugin.
 * No toca ningún contenido del sitio.
 *
 * @package AsesorInteligente
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}asi_index" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}asi_clicks" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}asi_chats" ); // Conversaciones guardadas.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}asi_stats" ); // Reportería.

foreach ( array( 'asi_settings', 'asi_schema', 'asi_languages', 'asi_reindex_state', 'asi_db_version', 'asi_fulltext', 'asi_misses', 'asi_pages_created', 'asi_questions', 'asi_learned', 'asi_clicks_db_version', 'asi_doc_version', 'asi_reindex_pending_ids', 'asi_ai_keys', 'asi_ai_usage', 'asi_ai_cache', 'asi_chats_db_version', 'asi_stats_db_version', 'asi_stats_visitors_since', 'asi_stats_demo' ) as $option ) {
	delete_option( $option );
}

// El rol "Asesor inteligente" (quien lo tenga queda sin rol; un administrador le asigna otro).
remove_role( 'asi_asesor' );

// Cachés (vocabulario, sellos, formularios, logo) y nombres de los PDFs temporales.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_asi\\_%' OR option_name LIKE '\\_transient\\_timeout\\_asi\\_%' OR option_name LIKE 'asi\\_file\\_%'" );

// PDFs temporales generados en el chat.
$uploads = wp_upload_dir();
$dir     = trailingslashit( $uploads['basedir'] ) . 'asi-files';
if ( is_dir( $dir ) ) {
	foreach ( array_merge( (array) glob( $dir . '/*' ), (array) glob( $dir . '/.*' ) ) as $file ) {
		if ( is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}
	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}

wp_clear_scheduled_hook( 'asi_discover' );
wp_clear_scheduled_hook( 'asi_reindex_batch' );
wp_clear_scheduled_hook( 'asi_reindex_pending' );
wp_clear_scheduled_hook( 'asi_stats_purge' );
