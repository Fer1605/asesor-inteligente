<?php
/**
 * Aprendizaje: lo que el chat aprende con el uso, sin IA externa.
 *
 *  1. Preguntas sin buena respuesta: se guardan (sin datos personales) agrupadas por su forma
 *     ("montacargas pequeño" y "Montacargas pequeños" son la misma) para verlas en el panel.
 *  2. Respuestas enseñadas: desde el panel se dice qué contenido responde una pregunta, y desde
 *     entonces el chat la contesta así.
 *  3. Clics: qué resultado abre la gente después de cada búsqueda. Lo más abierto sube un poco
 *     en esa búsqueda (solo reordena; nunca agrega resultados que no correspondan).
 *
 * Costo: un INSERT … ON DUPLICATE KEY por clic (tabla pequeña, con índice) y una lectura por
 * búsqueda. Las preguntas y lo enseñado viven en opciones que no se cargan en cada página.
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || exit;

class ASI_Learning {

	const TABLE_VERSION        = 1;
	const TABLE_VERSION_OPTION = 'asi_clicks_db_version';
	const QUESTIONS_OPTION     = 'asi_questions';
	const LEARNED_OPTION       = 'asi_learned';
	const QUESTIONS_MAX        = 300;
	const LEARNED_MAX          = 500;
	const CLICKS_PER_MINUTE    = 30;

	/**
	 * Nombre de la tabla de clics.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'asi_clicks';
	}

	/**
	 * Crea la tabla de clics si hace falta.
	 */
	public static function maybe_install() {
		if ( (int) get_option( self::TABLE_VERSION_OPTION ) === self::TABLE_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
				lang varchar(20) NOT NULL DEFAULT '',
				qkey varchar(190) NOT NULL DEFAULT '',
				post_id bigint(20) unsigned NOT NULL,
				clicks int(10) unsigned NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (lang, qkey, post_id)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( self::TABLE_VERSION_OPTION, self::TABLE_VERSION, true );
	}

	/**
	 * Registra que después de buscar $query se abrió $post_id.
	 *
	 * @param string $lang    Idioma del contexto.
	 * @param string $key     Clave de la búsqueda.
	 * @param int    $post_id Resultado abierto.
	 */
	public static function record_click( $lang, $key, $post_id ) {
		global $wpdb;
		if ( '' === $key || ! $post_id ) {
			return;
		}
		$table = self::table();
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (lang, qkey, post_id, clicks, updated_at) VALUES (%s, %s, %d, 1, %s)
				ON DUPLICATE KEY UPDATE clicks = clicks + 1, updated_at = VALUES(updated_at)",
				$lang,
				$key,
				(int) $post_id,
				current_time( 'mysql', true )
			)
		);
	}

	/**
	 * Clics por resultado para una búsqueda.
	 *
	 * @param string $lang Idioma.
	 * @param string $key  Clave.
	 * @return array<int,int>
	 */
	public static function boosts( $lang, $key ) {
		global $wpdb;
		if ( '' === $key || (int) get_option( self::TABLE_VERSION_OPTION ) !== self::TABLE_VERSION ) {
			return array();
		}
		$table = self::table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT post_id, clicks FROM {$table} WHERE lang = %s AND qkey = %s ORDER BY clicks DESC LIMIT 50", $lang, $key ),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['post_id'] ] = (int) $row['clicks'];
		}
		return $out;
	}

	/**
	 * Lo más abierto desde el chat (para el panel).
	 *
	 * @param int $limit Cuántos.
	 * @return array[] lang, qkey, post_id, clicks
	 */
	public static function top_clicks( $limit = 15 ) {
		global $wpdb;
		if ( (int) get_option( self::TABLE_VERSION_OPTION ) !== self::TABLE_VERSION ) {
			return array();
		}
		$table = self::table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT lang, qkey, post_id, clicks FROM {$table} ORDER BY clicks DESC LIMIT %d", (int) $limit ), ARRAY_A );
	}

	/**
	 * Guarda una pregunta que no tuvo buena respuesta, agrupada por su clave.
	 *
	 * @param string $message Mensaje (se recorta; sin datos del visitante).
	 * @param string $lang    Idioma.
	 * @param string $key     Clave de la búsqueda.
	 * @param string $kind    'none' (sin respuesta) o 'weak' (respuesta aproximada).
	 */
	public static function log_question( $message, $lang, $key, $kind ) {
		if ( '' === $key ) {
			return;
		}
		$questions = self::questions();
		$id        = md5( $lang . '|' . $key );
		$text      = function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 120 ) : substr( $message, 0, 120 );
		if ( isset( $questions[ $id ] ) ) {
			$questions[ $id ]['count']++;
			$questions[ $id ]['at']   = time();
			$questions[ $id ]['q']    = $text;
			$questions[ $id ]['kind'] = 'none' === $kind ? 'none' : $questions[ $id ]['kind'];
		} else {
			$questions[ $id ] = array(
				'q'     => $text,
				'key'   => $key,
				'lang'  => $lang,
				'kind'  => $kind,
				'count' => 1,
				'at'    => time(),
			);
		}
		// Se quedan las más recientes.
		if ( count( $questions ) > self::QUESTIONS_MAX ) {
			uasort(
				$questions,
				function ( $a, $b ) {
					return $b['at'] - $a['at'];
				}
			);
			$questions = array_slice( $questions, 0, self::QUESTIONS_MAX, true );
		}
		update_option( self::QUESTIONS_OPTION, $questions, false );
	}

	/**
	 * Preguntas guardadas: las más repetidas primero.
	 *
	 * @return array<string,array>
	 */
	public static function questions() {
		$questions = get_option( self::QUESTIONS_OPTION, array() );
		return is_array( $questions ) ? $questions : array();
	}

	/**
	 * Quita una pregunta de la lista.
	 *
	 * @param string $id ID.
	 */
	public static function dismiss( $id ) {
		$questions = self::questions();
		unset( $questions[ $id ] );
		update_option( self::QUESTIONS_OPTION, $questions, false );
	}

	/**
	 * Respuestas enseñadas.
	 *
	 * @return array<string,array<string,array>> lang => clave => [q, ids, at]
	 */
	public static function learned() {
		$learned = get_option( self::LEARNED_OPTION, array() );
		return is_array( $learned ) ? $learned : array();
	}

	/**
	 * Mapa clave => IDs de un idioma (lo usa el diálogo).
	 *
	 * @param string $lang Idioma.
	 * @return array<string,int[]>
	 */
	public static function learned_for( $lang ) {
		$learned = self::learned();
		$map     = array();
		foreach ( isset( $learned[ $lang ] ) ? $learned[ $lang ] : array() as $key => $entry ) {
			$map[ $key ] = array_map( 'intval', (array) $entry['ids'] );
		}
		return $map;
	}

	/**
	 * Enseña qué contenido responde una pregunta.
	 *
	 * @param string $lang Idioma.
	 * @param string $key  Clave.
	 * @param string $q    Pregunta como se escribió.
	 * @param int[]  $ids  Contenido que la responde.
	 */
	public static function teach( $lang, $key, $q, array $ids ) {
		$ids = array_slice( array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) ), 0, 8 );
		if ( '' === $key || ! $ids ) {
			return;
		}
		$learned                  = self::learned();
		$learned[ $lang ][ $key ] = array( 'q' => $q, 'ids' => $ids, 'at' => time() );
		if ( count( $learned[ $lang ] ) > self::LEARNED_MAX ) {
			$learned[ $lang ] = array_slice( $learned[ $lang ], -self::LEARNED_MAX, null, true );
		}
		update_option( self::LEARNED_OPTION, $learned, false );
		self::dismiss( md5( $lang . '|' . $key ) );
	}

	/**
	 * Olvida una respuesta enseñada.
	 *
	 * @param string $lang Idioma.
	 * @param string $key  Clave.
	 */
	public static function forget( $lang, $key ) {
		$learned = self::learned();
		unset( $learned[ $lang ][ $key ] );
		if ( empty( $learned[ $lang ] ) ) {
			unset( $learned[ $lang ] );
		}
		update_option( self::LEARNED_OPTION, $learned, false );
	}

	/**
	 * ¿Puede registrar otro clic este visitante? (evita que alguien infle resultados a mano).
	 *
	 * @return bool
	 */
	public static function click_allowed() {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key   = 'asi_ck_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= self::CLICKS_PER_MINUTE ) {
			return false;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}
}
