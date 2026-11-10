<?php
/**
 * Acceso del motor al índice en la base de datos de WordPress.
 *
 * Todas las consultas filtran por el idioma del contexto (y con él el país) antes que nada.
 * Las filas sin idioma ('') son de tipos de contenido que el plugin multilingüe no traduce:
 * se consideran del sitio entero.
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || exit;

class ASI_Index_Repository implements ASI_Repository {

	const VOCAB_TTL      = 12 * HOUR_IN_SECONDS;
	const VOCAB_PREFIX   = 'asi_vocab_';
	const BODY_MAX_CHARS = 8000;
	const LIKE_MAX_WORDS = 12;

	/** @var array<string,array> Vocabularios ya cargados en esta petición. */
	private $vocab_cache = array();

	public function candidates( $lang, array $words, array $codes, array $roles, $limit ) {
		global $wpdb;
		$table = ASI_Index_Table::name();

		$roles = array_values( array_filter( $roles, array( $this, 'is_valid_role' ) ) );
		if ( ! $roles ) {
			return array();
		}
		$role_placeholders = implode( ',', array_fill( 0, count( $roles ), '%s' ) );

		$match_sql = $this->match_condition( $words, $codes, $params_match );
		if ( '' === $match_sql ) {
			return array();
		}

		$select = "SELECT post_id, post_type, role, lang, title, title_norm, terms_norm, codes, LEFT(body_norm, " . self::BODY_MAX_CHARS . ") AS body_norm, card FROM {$table}";
		$sql    = "{$select} WHERE lang IN (%s, '') AND role IN ({$role_placeholders}) AND ({$match_sql}) LIMIT %d";
		$params = array_merge( array( $lang ), $roles, $params_match, array( (int) $limit ) );

		return $this->decode_rows( $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) );
	}

	public function rows( $lang, array $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$table        = ASI_Index_Table::name();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, post_type, role, lang, title, title_norm, terms_norm, codes, LEFT(body_norm, " . self::BODY_MAX_CHARS . ") AS body_norm, card
				FROM {$table} WHERE lang IN (%s, '') AND post_id IN ({$placeholders})",
				array_merge( array( $lang ), $ids )
			),
			ARRAY_A
		);

		// Mismo orden que los IDs pedidos.
		$by_id = array();
		foreach ( $this->decode_rows( $rows ) as $row ) {
			$by_id[ (int) $row['post_id'] ] = $row;
		}
		$ordered = array();
		foreach ( $ids as $id ) {
			if ( isset( $by_id[ $id ] ) ) {
				$ordered[] = $by_id[ $id ];
			}
		}
		return $ordered;
	}

	public function by_post_types( $lang, array $types, $limit ) {
		global $wpdb;
		$types = array_values( array_filter( array_map( 'sanitize_key', $types ) ) );
		if ( ! $types ) {
			return array();
		}
		$table        = ASI_Index_Table::name();
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		return $this->decode_rows(
			$wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, post_type, role, lang, title, title_norm, terms_norm, codes, '' AS body_norm, card
					FROM {$table} WHERE lang IN (%s, '') AND post_type IN ({$placeholders}) ORDER BY title ASC LIMIT %d",
					array_merge( array( $lang ), $types, array( (int) $limit ) )
				),
				ARRAY_A
			)
		);
	}

	/**
	 * Qué parte del catálogo tiene cada sello (0 a 1). Se guarda 6 horas.
	 *
	 * @param string $lang Idioma.
	 * @return array<string,float>
	 */
	public static function flag_shares( $lang ) {
		$key    = 'asi_flag_shares_' . md5( (string) $lang );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$table  = ASI_Index_Table::name();
		$cards  = $wpdb->get_col( $wpdb->prepare( "SELECT card FROM {$table} WHERE lang IN (%s, '') AND role = 'catalog'", $lang ) );
		$counts = array();
		foreach ( (array) $cards as $raw ) {
			$card = json_decode( (string) $raw, true );
			foreach ( isset( $card['flags'] ) ? array_unique( (array) $card['flags'] ) : array() as $flag ) {
				$counts[ (string) $flag ] = ( isset( $counts[ $flag ] ) ? $counts[ $flag ] : 0 ) + 1;
			}
		}
		$total  = max( 1, count( (array) $cards ) );
		$shares = array();
		foreach ( $counts as $flag => $count ) {
			$shares[ $flag ] = round( $count / $total, 4 );
		}
		// También los sellos que hoy nadie tiene (para poder activarlos en el panel).
		$schema = ASI_Schema_Discovery::get_schema();
		foreach ( isset( $schema['post_types'] ) ? (array) $schema['post_types'] : array() as $type ) {
			foreach ( isset( $type['fields'] ) ? (array) $type['fields'] : array() as $field ) {
				if ( 'flag' === $field['role'] && ! isset( $shares[ $field['label'] ] ) ) {
					$shares[ $field['label'] ] = 0.0;
				}
			}
		}
		arsort( $shares );
		set_transient( $key, $shares, 6 * HOUR_IN_SECONDS );
		return $shares;
	}

	public function catalog( $lang, $limit ) {
		global $wpdb;
		$table = ASI_Index_Table::name();
		return $this->decode_rows(
			$wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, post_type, role, lang, title, title_norm, terms_norm, codes, '' AS body_norm, card
					FROM {$table} WHERE lang IN (%s, '') AND role = 'catalog' LIMIT %d",
					$lang,
					(int) $limit
				),
				ARRAY_A
			)
		);
	}

	public function details( $lang, array $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$table        = ASI_Index_Table::name();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare( "SELECT post_id, details FROM {$table} WHERE lang IN (%s, '') AND post_id IN ({$placeholders})", array_merge( array( $lang ), $ids ) ),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$details = json_decode( (string) $row['details'], true );
			$out[ (int) $row['post_id'] ] = array(
				'facts' => isset( $details['facts'] ) && is_array( $details['facts'] ) ? $details['facts'] : array(),
				'text'  => isset( $details['text'] ) && is_string( $details['text'] ) ? $details['text'] : '',
			);
		}
		return $out;
	}

	/**
	 * Contenido del índice cuyo nombre contiene el texto (para el panel).
	 *
	 * @param string $lang  Idioma.
	 * @param string $text  Texto.
	 * @param int    $limit Máximo.
	 * @return array[] id, title, type
	 */
	public function find_titles( $lang, $text, $limit ) {
		global $wpdb;
		$normal = ASI_Normalizer::normalize( $text );
		if ( strlen( $normal ) < 2 ) {
			return array();
		}
		$table = ASI_Index_Table::name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, title, post_type, role FROM {$table} WHERE lang IN (%s, '') AND (title_norm LIKE %s OR codes LIKE %s) ORDER BY role = 'catalog' DESC, CHAR_LENGTH(title) ASC LIMIT %d",
				$lang,
				'%' . $wpdb->esc_like( ASI_Lexicon::normal_form( $text ) ) . '%',
				'%' . $wpdb->esc_like( ASI_Normalizer::compact_code( $text ) ) . '%',
				(int) $limit
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$type  = get_post_type_object( $row['post_type'] );
			$out[] = array(
				'id'    => (int) $row['post_id'],
				'title' => $row['title'],
				'type'  => $type ? $type->labels->singular_name : $row['post_type'],
			);
		}
		return $out;
	}

	public function boosts( $lang, $key ) {
		return ASI_Learning::boosts( $lang, $key );
	}

	public function vocabulary( $lang ) {
		if ( isset( $this->vocab_cache[ $lang ] ) ) {
			return $this->vocab_cache[ $lang ];
		}
		$key   = self::VOCAB_PREFIX . md5( (string) $lang );
		$vocab = get_transient( $key );

		if ( ! is_array( $vocab ) ) {
			global $wpdb;
			$table = ASI_Index_Table::name();
			$rows  = $wpdb->get_results(
				$wpdb->prepare( "SELECT role, title_norm, terms_norm, codes, facets FROM {$table} WHERE lang IN (%s, '') LIMIT 50000", $lang ),
				ARRAY_A
			);
			$vocab = ASI_Vocabulary::build( (array) $rows );
			set_transient( $key, $vocab, self::VOCAB_TTL );
		}

		$this->vocab_cache[ $lang ] = $vocab;
		return $vocab;
	}

	/**
	 * Borra el vocabulario guardado de un idioma (o de todos).
	 *
	 * @param string|null $lang Idioma; null = todos.
	 */
	public static function flush_vocabulary( $lang = null ) {
		if ( null !== $lang ) {
			delete_transient( self::VOCAB_PREFIX . md5( (string) $lang ) );
			return;
		}
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . self::VOCAB_PREFIX ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . self::VOCAB_PREFIX ) . '%'
			)
		);
	}

	/**
	 * Condición SQL de coincidencia: FULLTEXT en modo booleano si está disponible, si no LIKE.
	 *
	 * @param string[] $words  Formas normales.
	 * @param string[] $codes  Códigos compactos.
	 * @param array    $params Parámetros de la condición (salida).
	 * @return string
	 */
	private function match_condition( array $words, array $codes, &$params ) {
		$params = array();
		$parts  = array();
		$words  = array_values( array_filter( array_map( array( $this, 'clean_word' ), $words ) ) );
		$codes  = array_values( array_filter( array_map( array( $this, 'clean_word' ), $codes ) ) );

		$boolean = array();
		foreach ( $words as $word ) {
			if ( false !== strpos( $word, ' ' ) ) {
				$boolean[] = '"' . $word . '"';
			} elseif ( strlen( $word ) >= 3 ) {
				$boolean[] = $word . '*';
			}
		}
		foreach ( $codes as $code ) {
			if ( strlen( $code ) >= 3 ) {
				$boolean[] = $code . '*';
			}
		}

		if ( get_option( 'asi_fulltext' ) && $boolean ) {
			$parts[]  = 'MATCH(title_norm, terms_norm, codes, body_norm) AGAINST (%s IN BOOLEAN MODE)';
			$params[] = implode( ' ', array_unique( $boolean ) );
		} else {
			foreach ( array_slice( $words, 0, self::LIKE_MAX_WORDS ) as $word ) {
				$like     = '%' . $GLOBALS['wpdb']->esc_like( $word ) . '%';
				$parts[]  = '(title_norm LIKE %s OR terms_norm LIKE %s OR body_norm LIKE %s)';
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
			}
		}

		// Los códigos también por LIKE: FULLTEXT ignora tokens cortos y los prefijos de códigos cortos.
		foreach ( array_slice( $codes, 0, 6 ) as $code ) {
			$parts[]  = 'codes LIKE %s';
			$params[] = '%' . $GLOBALS['wpdb']->esc_like( $code ) . '%';
		}

		return implode( ' OR ', $parts );
	}

	/**
	 * Solo letras, dígitos, ñ y espacios (las formas normales ya lo son; esto protege el modo booleano).
	 *
	 * @param string $word Palabra.
	 * @return string
	 */
	private function clean_word( $word ) {
		return trim( preg_replace( '/[^a-z0-9ñ ]+/u', '', (string) $word ) );
	}

	private function is_valid_role( $role ) {
		return in_array( $role, array( 'catalog', 'info', 'article' ), true );
	}

	/**
	 * Decodifica la tarjeta JSON de cada fila.
	 *
	 * @param array|null $rows Filas.
	 * @return array[]
	 */
	/**
	 * Red de seguridad: solo lo que sigue publicado y sin contraseña en este momento. Si algo se
	 * despublicó sin pasar por WordPress (p. ej. directo en la base), se saca del índice aquí mismo.
	 * Una sola consulta por respuesta.
	 *
	 * @param array[] $rows Filas.
	 * @return array[]
	 */
	private function only_published( array $rows ) {
		global $wpdb;
		$ids = array_values( array_unique( array_map( 'intval', array_column( $rows, 'post_id' ) ) ) );
		if ( ! $ids ) {
			return $rows;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$live         = array_flip(
			array_map(
				'intval',
				$wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID IN ({$placeholders}) AND post_status = 'publish' AND post_password = ''", $ids ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			)
		);
		$out = array();
		foreach ( $rows as $row ) {
			if ( isset( $live[ (int) $row['post_id'] ] ) ) {
				$out[] = $row;
			} else {
				ASI_Index_Table::delete( (int) $row['post_id'] );
			}
		}
		return $out;
	}

	private function decode_rows( $rows ) {
		$rows = $this->only_published( (array) $rows );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$card        = json_decode( (string) $row['card'], true );
			$row['card'] = is_array( $card ) ? $card : array( 'title' => $row['title'], 'url' => get_permalink( (int) $row['post_id'] ) );
			$out[]       = $row;
		}
		return $out;
	}
}
