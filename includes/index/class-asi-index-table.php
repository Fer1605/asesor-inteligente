<?php
/**
 * Tabla propia del índice de búsqueda.
 *
 * Una fila por post publicado e indexable. Guarda el contexto (idioma + país) para que el
 * filtro por contexto sea un WHERE simple y rápido, sin cargar WordPress ni consultar la meta.
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || exit;

class ASI_Index_Table {

	const DB_VERSION        = 3;
	const DB_VERSION_OPTION = 'asi_db_version';

	/**
	 * Nombre completo de la tabla.
	 *
	 * @return string
	 */
	public static function name() {
		global $wpdb;
		return $wpdb->prefix . 'asi_index';
	}

	/**
	 * Crea o actualiza la tabla si hace falta.
	 */
	public static function maybe_install() {
		if ( (int) get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}
		self::install();
	}

	/**
	 * Crea la tabla con dbDelta. El índice FULLTEXT es opcional: si el motor de la base de datos
	 * no lo soporta, la búsqueda usa LIKE y lo deja anotado.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::name();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				post_id bigint(20) unsigned NOT NULL,
				post_type varchar(40) NOT NULL DEFAULT '',
				role varchar(20) NOT NULL DEFAULT '',
				lang varchar(20) NOT NULL DEFAULT '',
				country varchar(8) NOT NULL DEFAULT '',
				title text NOT NULL,
				title_norm varchar(500) NOT NULL DEFAULT '',
				terms_norm text NOT NULL,
				facets text NOT NULL,
				codes varchar(500) NOT NULL DEFAULT '',
				body_norm mediumtext NOT NULL,
				term_ids text NOT NULL,
				card longtext NOT NULL,
				details longtext NOT NULL,
				signature char(32) NOT NULL DEFAULT '',
				indexed_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (post_id),
				KEY context (country, lang, role)
			) {$charset};"
		);

		self::maybe_add_fulltext();
		// Autoload: se consulta en cada petición y así no cuesta una query.
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}

	/**
	 * Intenta crear el índice FULLTEXT y anota si está disponible.
	 */
	private static function maybe_add_fulltext() {
		global $wpdb;
		$table = self::name();

		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", 'search_text' ) );
		if ( ! $exists ) {
			$suppress = $wpdb->suppress_errors( true );
			$wpdb->query( "ALTER TABLE {$table} ADD FULLTEXT KEY search_text (title_norm, terms_norm, codes, body_norm)" );
			$wpdb->suppress_errors( $suppress );
			$exists = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", 'search_text' ) );
		}
		update_option( 'asi_fulltext', $exists ? 1 : 0, false );
	}

	/**
	 * Guarda o reemplaza la fila de un post.
	 *
	 * @param array $row Columnas.
	 * @return bool
	 */
	public static function upsert( array $row ) {
		global $wpdb;
		return false !== $wpdb->replace( self::name(), $row );
	}

	/**
	 * Borra la fila de un post.
	 *
	 * @param int $post_id Post.
	 */
	public static function delete( $post_id ) {
		global $wpdb;
		$wpdb->delete( self::name(), array( 'post_id' => (int) $post_id ), array( '%d' ) );
	}

	/**
	 * Firma guardada de un post ('' si no está indexado).
	 *
	 * @param int $post_id Post.
	 * @return string
	 */
	public static function get_signature( $post_id ) {
		global $wpdb;
		$table = self::name();
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT signature FROM {$table} WHERE post_id = %d", $post_id ) );
	}

	/**
	 * Resumen para el panel: filas por idioma y rol.
	 *
	 * @return array
	 */
	public static function stats() {
		global $wpdb;
		$table = self::name();
		return (array) $wpdb->get_results( "SELECT lang, country, role, COUNT(*) AS total FROM {$table} GROUP BY lang, country, role ORDER BY country, lang, role", ARRAY_A );
	}

	/**
	 * Borra filas de posts que ya no existen o no están publicados.
	 *
	 * @return int Filas borradas.
	 */
	public static function purge_orphans( array $enabled_types = array() ) {
		global $wpdb;
		$table   = self::name();
		$deleted = (int) $wpdb->query(
			"DELETE i FROM {$table} i
			LEFT JOIN {$wpdb->posts} p ON p.ID = i.post_id AND p.post_status = 'publish'
			WHERE p.ID IS NULL"
		);
		// Tipos de contenido que se desactivaron (a mano o por el descubrimiento).
		if ( $enabled_types ) {
			$placeholders = implode( ',', array_fill( 0, count( $enabled_types ), '%s' ) );
			$deleted     += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE post_type NOT IN ({$placeholders})", $enabled_types ) );
		}
		return $deleted;
	}

}
