<?php
/**
 * Registro privado de las conversaciones del chat.
 *
 * Cada conversación queda como un JSON en la base de datos (nunca en un archivo público): qué
 * escribió la persona y qué respondió el chat, turno por turno. Sirve para dos cosas:
 *  - el resumen que llega al asesor con una solicitud de cita ("buscó pickups diésel hasta Q250 mil,
 *    vio la BT-50, calculó cuotas…"), para que no tenga que volver a empezar;
 *  - analizar después qué pregunta y qué cotiza la gente, y mejorar el chat (se descarga en JSON
 *    desde el panel, pestaña Aprendizaje).
 *
 * Sin correos ni teléfonos (se tapan antes de guardar). Se guarda KEEP_DAYS días y lo más viejo se
 * borra solo.
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || defined( 'ASI_CLI_TESTS' ) || exit;

class ASI_Chat_Log {

	const TABLE_VERSION        = 1;
	const TABLE_VERSION_OPTION = 'asi_chats_db_version';
	const KEEP_DAYS            = 180;
	const MAX_TURNS            = 120; // Por conversación (las muy largas guardan las últimas).
	const SUMMARY_LINES        = 10;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'asi_chats';
	}

	public static function maybe_install() {
		if ( (int) get_option( self::TABLE_VERSION_OPTION ) === self::TABLE_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				cid varchar(32) NOT NULL DEFAULT '',
				lang varchar(20) NOT NULL DEFAULT '',
				started datetime NOT NULL,
				updated datetime NOT NULL,
				turns smallint(5) unsigned NOT NULL DEFAULT 0,
				data longtext NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY cid (cid),
				KEY updated (updated)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( self::TABLE_VERSION_OPTION, self::TABLE_VERSION, true );
	}

	/** ¿Se guardan las conversaciones? (un sitio puede apagarlo con este filtro). */
	public static function enabled() {
		return (bool) apply_filters( 'asi_chat_log', true );
	}

	/** Identificador nuevo de conversación (va en el estado del chat). */
	public static function new_id() {
		return strtolower( wp_generate_password( 24, false, false ) );
	}

	public static function valid_id( $cid ) {
		return is_string( $cid ) && (bool) preg_match( '/^[a-z0-9]{12,32}$/', $cid );
	}

	/**
	 * Un turno: lo que escribió y lo que respondió el chat (lo justo para entenderlo y analizarlo).
	 *
	 * @param string $message  Mensaje.
	 * @param array  $before   Estado antes del turno.
	 * @param array  $response Respuesta (con su estado).
	 * @param string $lang     Idioma/país del chat.
	 * @param array  $criteria Lo que buscó, si la búsqueda cambió ("Tipo: Pickup").
	 */
	public static function record( $message, array $before, array $response, $lang, array $criteria = array() ) {
		$after = isset( $response['state'] ) ? (array) $response['state'] : array();
		$cid   = isset( $after['cid'] ) ? $after['cid'] : '';
		if ( ! self::enabled() || ! self::valid_id( $cid ) || '' === trim( (string) $message ) ) {
			return;
		}
		$turn = self::turn( $message, $before, $response, $criteria );

		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, data FROM {$table} WHERE cid = %s", $cid ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $row ) {
			$data            = json_decode( (string) $row['data'], true );
			$data            = is_array( $data ) && isset( $data['turns'] ) ? $data : array( 'turns' => array() );
			$data['turns'][] = $turn;
			$data['turns']   = array_slice( $data['turns'], -self::MAX_TURNS );
			$wpdb->update( $table, array( 'updated' => $now, 'turns' => count( $data['turns'] ), 'data' => wp_json_encode( $data ) ), array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'cid'     => $cid,
				'lang'    => substr( (string) $lang, 0, 20 ),
				'started' => $now,
				'updated' => $now,
				'turns'   => 1,
				'data'    => wp_json_encode( array( 'turns' => array( $turn ) ) ),
			)
		);
	}

	/**
	 * Lo que se guarda de un turno (también lo usan las pruebas).
	 */
	public static function turn( $message, array $before, array $response, array $criteria = array() ) {
		$after  = isset( $response['state'] ) ? (array) $response['state'] : array();
		$text   = isset( $response['text'] ) ? ( is_array( $response['text'] ) ? implode( ' ', $response['text'] ) : (string) $response['text'] ) : '';
		$titles = array();
		foreach ( isset( $response['cards'] ) ? (array) $response['cards'] : array() as $card ) {
			if ( ! empty( $card['title'] ) ) {
				$titles[] = self::short( wp_strip_all_tags( (string) $card['title'] ), 80 );
			}
		}
		$old_last = isset( $before['last'] ) ? (string) $before['last'] : '';
		$new_last = isset( $after['last'] ) ? (string) $after['last'] : '';
		$turn     = array(
			't' => time(),
			'u' => self::short( self::scrub( $message ), 300 ),
			'k' => isset( $response['kind'] ) ? (string) $response['kind'] : '',
			'r' => self::short( self::scrub( wp_strip_all_tags( $text ) ), 300 ),
			'n' => count( $titles ),
			'c' => array_slice( $titles, 0, 4 ),
		);
		if ( $criteria ) {
			$turn['s'] = array_values( array_slice( $criteria, 0, 8 ) );
		}
		// Cotización, comparación o cuotas que se hicieron en este turno.
		if ( '' !== $new_last && ( $new_last !== $old_last || in_array( $turn['k'], array( 'file', 'finance' ), true ) ) ) {
			$turn['a'] = $new_last;
		}
		if ( ! empty( $response['ai'] ) || ! empty( $response['ai_model'] ) ) {
			$turn['ai'] = 1; // La IA ayudó a entenderlo.
		}
		return $turn;
	}

	/**
	 * Resumen para el asesor: lo más importante de la conversación, en orden y en pocas líneas.
	 *
	 * @return array { lines: string[], budget: string, last_seen: string }
	 */
	public static function summary( $cid ) {
		$out = array( 'lines' => array(), 'budget' => '', 'last_seen' => '' );
		if ( ! self::valid_id( $cid ) ) {
			return $out;
		}
		global $wpdb;
		$table = self::table();
		$data  = $wpdb->get_var( $wpdb->prepare( "SELECT data FROM {$table} WHERE cid = %s", $cid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$data  = json_decode( (string) $data, true );
		return self::summarize( is_array( $data ) && isset( $data['turns'] ) ? (array) $data['turns'] : array() );
	}

	/**
	 * El resumen a partir de los turnos guardados (sin base de datos: lo prueban las pruebas).
	 */
	public static function summarize( array $turns ) {
		$lines  = array();
		$budget = '';
		$seen   = '';
		$list   = function ( array $titles ) {
			$titles = array_values( array_unique( array_filter( $titles ) ) );
			if ( count( $titles ) > 3 ) {
				return implode( ', ', array_slice( $titles, 0, 3 ) ) . ' y ' . ( count( $titles ) - 3 ) . ' más';
			}
			return 1 === count( $titles ) ? $titles[0] : implode( ', ', array_slice( $titles, 0, -1 ) ) . ' y ' . end( $titles );
		};
		foreach ( $turns as $turn ) {
			$kind   = isset( $turn['k'] ) ? $turn['k'] : '';
			$titles = isset( $turn['c'] ) ? (array) $turn['c'] : array();
			$asked  = isset( $turn['u'] ) ? self::short( (string) $turn['u'], 90 ) : '';
			$line   = '';
			if ( ! empty( $turn['s'] ) ) {
				foreach ( (array) $turn['s'] as $criterion ) {
					if ( 0 === strpos( (string) $criterion, 'Presupuesto: ' ) ) {
						$budget = substr( (string) $criterion, strlen( 'Presupuesto: ' ) );
					}
				}
				$line = 'Buscó: ' . implode( ' · ', array_map( array( __CLASS__, 'value_of' ), (array) $turn['s'] ) );
			}
			if ( ! empty( $turn['a'] ) && $titles ) {
				$names = array( 'quote' => 'Pidió la cotización de ', 'compare' => 'Pidió la comparativa de ', 'finance' => 'Calculó cuotas de ' );
				$line  = ( isset( $names[ $turn['a'] ] ) ? $names[ $turn['a'] ] : 'Pidió ' ) . $list( $titles );
			} elseif ( 'detail' === $kind && $titles ) {
				$line = 'Vio la ficha de ' . $titles[0];
				$seen = $titles[0];
			} elseif ( 'fact' === $kind && $titles ) {
				$line = 'Preguntó «' . $asked . '» sobre ' . $list( $titles );
				$seen = 1 === count( $titles ) ? $titles[0] : $seen;
			} elseif ( 'compare' === $kind && $titles ) {
				$line = 'Comparó ' . $list( $titles );
			} elseif ( 'none' === $kind && '' === $line ) {
				$line = 'Buscó «' . $asked . '» y no había opciones';
			} elseif ( 'info' === $kind && '' === $line ) {
				$line = 'Preguntó «' . $asked . '»';
			}
			if ( '' !== $line && ( ! $lines || end( $lines ) !== $line ) ) {
				$lines[] = $line;
			}
		}
		// Muchas cosas: el principio (qué buscaba) y lo último (en qué terminó).
		if ( count( $lines ) > self::SUMMARY_LINES ) {
			$lines = array_merge( array_slice( $lines, 0, 2 ), array_slice( $lines, -( self::SUMMARY_LINES - 2 ) ) );
		}
		return array( 'lines' => $lines, 'budget' => $budget, 'last_seen' => $seen );
	}

	/** "Tipo: Pickup" → "Pickup" (en el resumen, el nombre del dato sobra). */
	public static function value_of( $criterion ) {
		$parts = explode( ': ', (string) $criterion, 2 );
		return isset( $parts[1] ) ? $parts[1] : (string) $criterion;
	}

	/**
	 * Conversaciones de los últimos días, para descargar (JSON).
	 */
	public static function export( $days ) {
		global $wpdb;
		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) . ' -' . max( 1, (int) $days ) . ' days' ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT cid, lang, started, updated, data FROM {$table} WHERE updated >= %s ORDER BY updated DESC LIMIT 5000", $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();
		foreach ( (array) $rows as $row ) {
			$data  = json_decode( (string) $row['data'], true );
			$out[] = array(
				'id'      => $row['cid'],
				'lang'    => $row['lang'],
				'started' => $row['started'],
				'updated' => $row['updated'],
				'summary' => self::summarize( isset( $data['turns'] ) ? (array) $data['turns'] : array() )['lines'],
				'turns'   => isset( $data['turns'] ) ? $data['turns'] : array(),
			);
		}
		return $out;
	}

	/** Cuántas conversaciones hay guardadas en los últimos días. */
	public static function count_since( $days ) {
		global $wpdb;
		$table = self::table();
		$since = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) . ' -' . max( 1, (int) $days ) . ' days' ) );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE updated >= %s", $since ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Borra lo que tiene más de KEEP_DAYS días (corre con la limpieza diaria de Reportería). */
	public static function purge() {
		global $wpdb;
		$limit = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) . ' -' . self::KEEP_DAYS . ' days' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE updated < %s', $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Sin correos, enlaces ni teléfonos. */
	private static function scrub( $text ) {
		return class_exists( 'ASI_AI_Interpreter' ) ? ASI_AI_Interpreter::scrub( (string) $text ) : (string) $text;
	}

	private static function short( $text, $max ) {
		$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $max ) {
			return rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…';
		}
		return $text;
	}
}
