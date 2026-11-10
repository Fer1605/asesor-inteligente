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

foreach ( array( 'asi_settings', 'asi_schema', 'asi_languages', 'asi_reindex_state', 'asi_db_version', 'asi_fulltext', 'asi_misses', 'asi_pages_created', 'asi_questions', 'asi_learned', 'asi_clicks_db_version', 'asi_doc_version' ) as $option ) {
	delete_option( $option );
}

// Vocabularios en caché.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_bi\\_vocab\\_%' OR option_name LIKE '\\_transient\\_timeout\\_bi\\_vocab\\_%'" );

wp_clear_scheduled_hook( 'asi_discover' );
wp_clear_scheduled_hook( 'asi_reindex_batch' );
