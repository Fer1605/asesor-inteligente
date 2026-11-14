<?php
/**
 * Reportería: qué busca la gente en el chat, qué ve, qué cotiza y qué compara, desde el chat y
 * desde el sitio.
 *
 * - Contadores por día en una tabla pequeña (día, origen, tipo, campo, valor, contenido → veces),
 *   sin datos personales. Un INSERT … ON DUPLICATE KEY por evento.
 * - Chat: conversaciones, búsquedas y cada campo que pide (tipo, marca, características,
 *   presupuesto en rangos); cuenta lo que cambió, no lo que ya pedía.
 * - Sitio: lo que filtra en el listado o busca con el buscador de WordPress, con los mismos
 *   nombres que el chat. Se leen solos los filtros de Better Post Filter; otro plugin de filtros
 *   puede avisar con do_action( 'asi_report_site_filter', $picks, $lang ). Cada valor se nombra
 *   con lo que el plugin sabe del sitio: el pack (características), el rol de la taxonomía o del
 *   campo (marca, categoría, precio) o su nombre.
 * - Vistas: la ficha del contenido avisa al abrirse (funciona con la caché de página); si se abrió
 *   desde el chat cuenta como chat. También cuenta como vista del chat el detalle que muestra el chat.
 *   Cada visitante cuenta una vez al día por contenido.
 * - Cotizaciones y comparaciones: si el conector del sitio sabe leer sus envíos (report_leads),
 *   salen de ahí, con historial y separadas en chat y sitio; si no, se cuentan las del chat.
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || exit;

class ASI_Reports {

	const TABLE_VERSION        = 1;
	const TABLE_VERSION_OPTION = 'asi_stats_db_version';
	const PER_MINUTE           = 60;
	const KEEP_DAYS            = 365; // Se guarda un año; lo más viejo se borra solo.
	const MAX_ROWS             = 20;  // Filas por tabla en el panel y en el CSV.
	const PURGE_HOOK           = 'asi_stats_purge';
	const VISITORS_SINCE       = 'asi_stats_visitors_since'; // Desde cuándo se cuentan los visitantes del sitio.
	const DEMO_OPTION          = 'asi_stats_demo';           // Hay datos de ejemplo cargados (para enseñar el panel).

	/** Nombre de los tipos de término del sitio. */
	const TERM_NAMES = array(
		'category' => 'Categoría',
		'brand'    => 'Marca',
		'flag'     => 'Característica',
		'tag'      => 'Etiqueta',
		'location' => 'Ubicación',
	);

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'asi_stats';
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
				day date NOT NULL,
				source varchar(8) NOT NULL DEFAULT '',
				kind varchar(16) NOT NULL DEFAULT '',
				dim varchar(60) NOT NULL DEFAULT '',
				label varchar(190) NOT NULL DEFAULT '',
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				n int(10) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (day, source, kind, dim, label, post_id),
				KEY kind_day (kind, day)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( self::TABLE_VERSION_OPTION, self::TABLE_VERSION, true );
	}

	/**
	 * Rutas y aviso de vista en las fichas.
	 */
	public static function register() {
		add_action(
			'rest_api_init',
			static function () {
				register_rest_route(
					ASI_REST_Controller::NAMESPACE_V1,
					'/view',
					array(
						'methods'             => 'POST',
						'callback'            => array( __CLASS__, 'view_endpoint' ),
						'permission_callback' => '__return_true',
						'args'                => array(
							'id'  => array( 'type' => 'integer', 'required' => true ),
							'src' => array( 'type' => 'string', 'default' => 'site' ),
						),
					)
				);
			}
		);
		add_action( 'wp_footer', array( __CLASS__, 'print_beacon' ), 99 );
		add_action( self::PURGE_HOOK, array( __CLASS__, 'purge' ) );
		// Lo que se filtra o busca en el sitio.
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'watch_filter_request' ), 10, 3 );
		add_action( 'asi_report_site_filter', array( __CLASS__, 'site_filter' ), 10, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'watch_wp_search' ), 20 );
		if ( ! wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK );
		}
	}

	/** Borra lo que tiene más de un año. */
	public static function purge() {
		global $wpdb;
		$limit = gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . self::KEEP_DAYS . ' days' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE day < %s', $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Tipos de contenido que cuentan vistas: los que el asistente indexa, menos las páginas. */
	public static function tracked_types() {
		$schema = ASI_Schema_Discovery::get_schema();
		$types  = array();
		foreach ( (array) ( isset( $schema['post_types'] ) ? $schema['post_types'] : array() ) as $type => $record ) {
			if ( ! empty( $record['enabled'] ) && 'page' !== $type ) {
				$types[] = $type;
			}
		}
		return (array) apply_filters( 'asi_report_view_types', $types );
	}

	/**
	 * En la ficha de un contenido: avisa la vista. Va en el HTML (sirve con la caché de página);
	 * si el visitante llegó desde el chat (marca que deja el chat al abrir un resultado), es "chat".
	 */
	public static function print_beacon() {
		if ( is_admin() || ! is_singular( self::tracked_types() ) ) {
			return;
		}
		$id  = (int) get_queried_object_id();
		$url = rest_url( ASI_REST_Controller::NAMESPACE_V1 . '/view' );
		?>
<script>(function(){try{var id=<?php echo (int) $id; ?>,src='site';try{var m=JSON.parse(localStorage.getItem('asi_from_chat')||'{}');if(m.id===id&&Date.now()-m.t<1800000){src='chat';}localStorage.removeItem('asi_from_chat');}catch(e){}var b=JSON.stringify({id:id,src:src}),u=<?php echo wp_json_encode( $url ); ?>;if(navigator.sendBeacon){navigator.sendBeacon(u,new Blob([b],{type:'application/json'}));}else{fetch(u,{method:'POST',body:b,keepalive:true,headers:{'Content-Type':'application/json'}});}}catch(e){}})();</script>
		<?php
	}

	public static function view_endpoint( WP_REST_Request $request ) {
		$id  = (int) $request->get_param( 'id' );
		$src = 'chat' === $request->get_param( 'src' ) ? 'chat' : 'site';
		if ( $id && 'publish' === get_post_status( $id ) && in_array( get_post_type( $id ), self::tracked_types(), true ) ) {
			self::view( $id, $src );
		}
		$rest = rest_ensure_response( array( 'ok' => true ) );
		$rest->header( 'Cache-Control', 'no-store' );
		return $rest;
	}

	/** Una vista (una vez al día por visitante y contenido). */
	public static function view( $post_id, $source ) {
		if ( self::once( 'view|' . (int) $post_id ) ) {
			self::add( $source, 'view', '', '', (int) $post_id );
		}
		if ( 'site' === $source ) {
			self::site_visitor();
		}
	}

	/**
	 * Una persona que buscó o vio contenido en el sitio (sin el chat), una vez al día: es la base para
	 * comparar cuántos cotizan en el sitio contra cuántos cotizan desde el chat.
	 */
	public static function site_visitor() {
		if ( self::once( 'visitor' ) ) {
			self::add( 'site', 'visitor' );
			add_option( self::VISITORS_SINCE, current_time( 'Y-m-d' ), '', false );
		}
	}

	/**
	 * Lo que pasó en un turno del chat: conversación nueva, búsqueda nueva y lo que pidió de nuevo,
	 * y el detalle de un contenido mostrado en el chat.
	 *
	 * @param ASI_Dialogue $dialogue Diálogo ya usado en este turno.
	 * @param array        $before   Estado que llegó.
	 * @param array        $response Respuesta final.
	 */
	public static function chat_turn( ASI_Dialogue $dialogue, array $before, array $response ) {
		if ( ! apply_filters( 'asi_report_track', true ) ) {
			return;
		}
		$after = isset( $response['state'] ) ? (array) $response['state'] : array();
		if ( empty( $before['turns'] ) ) {
			self::add( 'chat', 'conversation' );
		}
		$old = isset( $before['q'] ) && is_string( $before['q'] ) ? $before['q'] : '';
		$new = isset( $after['q'] ) && is_string( $after['q'] ) ? $after['q'] : '';
		if ( '' !== $new && $new !== $old ) {
			self::add( 'chat', 'search' );
			$had = array();
			foreach ( $dialogue->report_criteria( $old ) as $c ) {
				$had[ $c['dim'] . '|' . $c['label'] ] = true;
			}
			foreach ( $dialogue->report_criteria( $new ) as $c ) {
				if ( ! isset( $had[ $c['dim'] . '|' . $c['label'] ] ) ) {
					self::add( 'chat', 'criterion', $c['dim'], $c['label'] );
				}
			}
		}
		if ( isset( $response['kind'] ) && 'detail' === $response['kind'] && ! empty( $after['focus'] ) ) {
			self::view( (int) $after['focus'], 'chat' );
		}
	}

	/** Cotización o comparación hecha en el chat (respaldo si el conector no lee sus envíos). */
	public static function chat_action( $action, array $item_ids, $test ) {
		// Una por acción (para la conversión), aparte de una por vehículo.
		self::add( 'chat', 'lead', $test ? 'test' : '', 'compare' === $action ? 'compare' : 'quote' );
		foreach ( $item_ids as $id ) {
			self::add( 'chat', 'compare' === $action ? 'compare' : 'quote', $test ? 'test' : '', '', (int) $id );
		}
	}

	public static function add( $source, $kind, $dim = '', $label = '', $post_id = 0 ) {
		global $wpdb;
		$label = function_exists( 'mb_substr' ) ? mb_substr( (string) $label, 0, 190 ) : substr( (string) $label, 0, 190 );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'INSERT INTO ' . self::table() . ' (day, source, kind, dim, label, post_id, n) VALUES (%s, %s, %s, %s, %s, %d, 1) ON DUPLICATE KEY UPDATE n = n + 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				current_time( 'Y-m-d' ),
				(string) $source,
				(string) $kind,
				substr( (string) $dim, 0, 60 ),
				$label,
				(int) $post_id
			)
		);
	}

	/** Una vez al día por visitante (huella de IP y navegador, sin guardarlos) y con tope por minuto. */
	private static function once( $what ) {
		if ( ! self::within_rate() ) {
			return false;
		}
		$key = 'asi_ro_' . md5( wp_salt( 'nonce' ) . '|' . self::visitor() . '|' . current_time( 'Y-m-d' ) . '|' . $what );
		if ( get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, 1, DAY_IN_SECONDS );
		return true;
	}

	/** Huella del visitante (IP y navegador); nunca se guarda tal cual. */
	private static function visitor() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 200 ) : '';
		return $ip . '|' . $ua;
	}

	/** Tope de eventos por minuto y visitante. */
	private static function within_rate() {
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$rate = 'asi_rp_' . md5( $ip );
		$n    = (int) get_transient( $rate );
		if ( $n >= self::PER_MINUTE ) {
			return false;
		}
		set_transient( $rate, $n + 1, MINUTE_IN_SECONDS );
		return true;
	}

	// ---------- Lo que se busca en el sitio ----------

	/**
	 * Better Post Filter (plugin de filtros de listados): lee la petición de filtrado, sin
	 * cambiarla. Taxonomías por ID, campos por valor, rangos numéricos y texto buscado.
	 */
	public static function watch_filter_request( $response, $handler, $request ) {
		if ( ! $request instanceof WP_REST_Request || '/bpfwe/v1/filter' !== $request->get_route() || is_wp_error( $response ) ) {
			return $response;
		}
		try {
			$params = $request->get_params();
			$list   = function ( $key ) use ( $params ) {
				$value = isset( $params[ $key ] ) ? $params[ $key ] : array();
				if ( is_string( $value ) ) {
					$value = json_decode( $value, true );
				}
				return is_array( $value ) ? $value : array();
			};
			$terms = function ( $row ) {
				return isset( $row['terms'] ) ? array_values( array_filter( array_map( 'strval', (array) $row['terms'] ), 'strlen' ) ) : array();
			};
			$picks = array();
			foreach ( $list( 'taxonomy_output' ) as $row ) {
				foreach ( $terms( $row ) as $term ) {
					$picks[] = array( 'taxonomy' => isset( $row['taxonomy'] ) ? (string) $row['taxonomy'] : '', 'term' => (int) $term );
				}
			}
			foreach ( array( 'custom_field_output', 'custom_field_like_output', 'custom_field_relational_output' ) as $key ) {
				foreach ( $list( $key ) as $row ) {
					foreach ( $terms( $row ) as $value ) {
						$picks[] = array( 'field' => isset( $row['taxonomy'] ) ? (string) $row['taxonomy'] : '', 'value' => $value );
					}
				}
			}
			foreach ( $list( 'numeric_output' ) as $row ) {
				$range = $terms( $row );
				if ( $range ) {
					$picks[] = array( 'field' => isset( $row['taxonomy'] ) ? (string) $row['taxonomy'] : '', 'min' => (float) $range[0], 'max' => isset( $range[1] ) ? (float) $range[1] : null );
				}
			}
			$text = isset( $params['search_query'] ) ? trim( (string) $params['search_query'] ) : '';
			if ( '' !== $text ) {
				$picks[] = array( 'text' => $text );
			}
			self::site_filter( $picks, isset( $params['lang'] ) ? (string) $params['lang'] : '' );
		} catch ( \Throwable $e ) {
			// La reportería nunca rompe el filtro del sitio.
		}
		return $response;
	}

	/** Buscador de WordPress (?s=) cuando se ven los resultados del sitio. */
	public static function watch_wp_search() {
		if ( is_admin() || ! is_search() || is_paged() ) {
			return;
		}
		$text = trim( (string) get_search_query( false ) );
		if ( '' !== $text ) {
			self::site_filter( array( array( 'text' => $text ) ) );
		}
	}

	/**
	 * Lo que alguien filtra o busca en el sitio. Cuenta lo que agregó a sus filtros (si después
	 * solo agrega "gasolina", la marca no se vuelve a contar), como en el chat.
	 *
	 * @param array[] $picks { taxonomy, term } | { field, value } | { field, min, max } | { text }.
	 * @param string  $lang  Idioma (vacío: el del sitio).
	 */
	public static function site_filter( $picks, $lang = '' ) {
		if ( ! is_array( $picks ) || ! $picks || ! apply_filters( 'asi_report_track', true ) || ! self::within_rate() ) {
			return;
		}
		$plugin = ASI_Plugin::instance();
		if ( ! $plugin->languages ) {
			return;
		}
		$context = ASI_REST_Controller::dialogue_context_for( ASI_Context::resolve( (string) $lang, $plugin->languages ), $plugin->languages );
		$now     = array();
		foreach ( self::name_picks( $picks, $context ) as $c ) {
			$now[ $c['dim'] . '|' . $c['label'] ] = $c;
		}
		if ( ! $now ) {
			return;
		}
		self::site_visitor();
		$key = 'asi_rf_' . md5( wp_salt( 'nonce' ) . '|' . self::visitor() );
		$had = get_transient( $key );
		$had = is_array( $had ) ? $had : array();
		set_transient( $key, array_fill_keys( array_keys( $now ), 1 ), 30 * MINUTE_IN_SECONDS );
		$new = array_diff_key( $now, $had );
		if ( ! $new ) {
			return;
		}
		self::add( 'site', 'search' );
		foreach ( $new as $c ) {
			self::add( 'site', 'criterion', $c['dim'], $c['label'] );
		}
	}

	/**
	 * Nombre de cada filtro, igual que en el chat: primero lo que el pack reconoce (características),
	 * luego el rol que el plugin descubrió para la taxonomía o el campo, y si no, su nombre.
	 */
	private static function name_picks( array $picks, array $context ) {
		$schema = ASI_Schema_Discovery::get_schema();
		$taxes  = array();
		$fields = array();
		foreach ( (array) ( isset( $schema['post_types'] ) ? $schema['post_types'] : array() ) as $record ) {
			if ( empty( $record['enabled'] ) ) {
				continue;
			}
			$taxes  += isset( $record['taxonomies'] ) ? (array) $record['taxonomies'] : array();
			$fields += isset( $record['fields'] ) ? (array) $record['fields'] : array();
		}
		$out   = array();
		$named = function ( $text, $role_dim, $fallback_dim ) use ( $context ) {
			$text = trim( wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) ) );
			if ( '' === $text ) {
				return null;
			}
			$parsed = ASI_Attributes::parse( (array) $context['packs'], $text, $context['language'] );
			if ( $parsed['constraints'] && isset( $parsed['labels'][0] ) ) {
				$keys = array_keys( $parsed['constraints'] );
				return array( 'dim' => 'attr:' . $keys[0], 'label' => self::cap( preg_replace( '/^(?:de|del|of)\s+/u', '', (string) $parsed['labels'][0] ) ) );
			}
			return array( 'dim' => '' !== $role_dim ? $role_dim : $fallback_dim, 'label' => self::cap( ASI_Query_Analyzer::display_name( $text ) ) );
		};
		foreach ( $picks as $pick ) {
			$pick = (array) $pick;
			if ( isset( $pick['text'] ) ) {
				$dialogue = new ASI_Dialogue( new ASI_Index_Repository(), $context );
				$out      = array_merge( $out, $dialogue->report_criteria( (string) $pick['text'] ) );
				continue;
			}
			if ( ! empty( $pick['taxonomy'] ) && ! empty( $pick['term'] ) ) {
				$tax  = sanitize_key( $pick['taxonomy'] );
				$term = get_term( (int) $pick['term'], $tax );
				if ( ! $term || is_wp_error( $term ) ) {
					continue;
				}
				$role  = isset( $taxes[ $tax ]['role'] ) && in_array( $taxes[ $tax ]['role'], array( 'brand' ), true ) ? 'term:' . $taxes[ $tax ]['role'] : '';
				$label = isset( $taxes[ $tax ]['label'] ) ? (string) $taxes[ $tax ]['label'] : ( get_taxonomy( $tax ) ? get_taxonomy( $tax )->labels->singular_name : $tax );
				$c     = $named( $term->name, $role, 'tax:' . $label );
				if ( $c ) {
					$out[] = $c;
				}
				continue;
			}
			if ( ! empty( $pick['field'] ) ) {
				$key   = (string) $pick['field'];
				$info  = isset( $fields[ $key ] ) ? (array) $fields[ $key ] : array();
				$role  = isset( $info['role'] ) ? (string) $info['role'] : '';
				$label = isset( $info['label'] ) ? (string) $info['label'] : $key;
				if ( array_key_exists( 'min', $pick ) ) {
					// Rango numérico: si es el precio, en los mismos rangos que el presupuesto del chat.
					if ( 'price' === $role ) {
						$sheet = array_merge( ASI_Search_Sheet::blank(), array( 'min' => $pick['min'] > 0 ? (float) $pick['min'] : null, 'max' => isset( $pick['max'] ) && $pick['max'] > 0 ? (float) $pick['max'] : null, 'currency' => isset( $context['money']['base'] ) ? (string) $context['money']['base'] : '' ) );
						$bucket = ASI_Search_Sheet::budget_bucket( $sheet );
						if ( '' !== $bucket ) {
							$out[] = array( 'dim' => 'budget', 'label' => $bucket );
						}
					}
					continue;
				}
				$c = $named( isset( $pick['value'] ) ? $pick['value'] : '', 'brand' === $role ? 'term:brand' : '', 'field:' . $label );
				if ( $c ) {
					$out[] = $c;
				}
			}
		}
		return $out;
	}

	private static function cap( $text ) {
		$text = trim( (string) $text );
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $text, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_substr( $text, 1, null, 'UTF-8' ) : ucfirst( $text );
	}

	// ---------- Lectura ----------

	/**
	 * Periodo del panel.
	 *
	 * @param string $period 7|30|90|365|custom (máximo un año).
	 * @param string $from   Y-m-d (custom).
	 * @param string $to     Y-m-d (custom).
	 * @return array { from, to, label } (fechas incluidas)
	 */
	public static function period( $period, $from = '', $to = '' ) {
		$today = current_time( 'Y-m-d' );
		$valid = function ( $d ) {
			return is_string( $d ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ? $d : '';
		};
		if ( 'custom' === $period && $valid( $from ) && $valid( $to ) ) {
			if ( $from > $to ) {
				list( $from, $to ) = array( $to, $from );
			}
			$to = min( $to, $today );
			// Un reporte abarca como máximo un año (lo que se guarda).
			$oldest = gmdate( 'Y-m-d', strtotime( $to . ' -' . ( self::KEEP_DAYS - 1 ) . ' days' ) );
			return array( 'from' => max( $from, $oldest ), 'to' => $to, 'key' => 'custom' );
		}
		$days = in_array( (string) $period, array( '7', '30', '90', '365' ), true ) ? (int) $period : 30;
		return array( 'from' => gmdate( 'Y-m-d', strtotime( $today . ' -' . ( $days - 1 ) . ' days' ) ), 'to' => $today, 'key' => (string) $days );
	}

	/**
	 * Todo el reporte de un periodo.
	 *
	 * @param array $period       De period().
	 * @param bool  $include_test Contar las pruebas del chat (modo prueba).
	 * @return array
	 */
	public static function build( array $period, $include_test ) {
		global $wpdb;
		$table = self::table();
		$rows  = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT source, kind, dim, label, post_id, SUM(n) AS n FROM {$table} WHERE day BETWEEN %s AND %s GROUP BY source, kind, dim, label, post_id", $period['from'], $period['to'] ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$days  = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT day, kind, dim, SUM(n) AS n FROM {$table} WHERE day BETWEEN %s AND %s AND kind IN ('conversation','lead','visitor') GROUP BY day, kind, dim", $period['from'], $period['to'] ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$report = array(
			'period'   => $period,
			'daily'    => array(),
			'counts'   => array(
				'conversation' => 0,
				'search'       => 0,
				'chat_leads'   => 0,
				'view'         => array( 'chat' => 0, 'site' => 0 ),
				'search_site'  => 0,
				'visitor'      => 0,
				'quote'        => array( 'chat' => 0, 'site' => 0 ),
				'compare'      => array( 'chat' => 0, 'site' => 0 ),
			),
			'criteria' => array(),
			'items'    => array( 'view' => array(), 'quote' => array(), 'compare' => array() ),
			'leads'    => 'chat',
		);
		$names = self::dim_names();

		// Un renglón por día (para las gráficas): conversaciones, visitantes del sitio y cotizaciones o
		// comparaciones de cada lado.
		$day = strtotime( $period['from'] . ' 12:00:00' );
		$end = strtotime( $period['to'] . ' 12:00:00' );
		while ( $day <= $end ) {
			$report['daily'][ gmdate( 'Y-m-d', $day ) ] = array( 'conversation' => 0, 'visitor' => 0, 'chat' => 0, 'site' => 0 );
			$day += DAY_IN_SECONDS;
		}
		foreach ( $days as $row ) {
			if ( ! isset( $report['daily'][ $row['day'] ] ) ) {
				continue;
			}
			if ( 'lead' === $row['kind'] ) {
				if ( 'test' !== $row['dim'] || $include_test ) {
					$report['daily'][ $row['day'] ]['lead'] = ( isset( $report['daily'][ $row['day'] ]['lead'] ) ? $report['daily'][ $row['day'] ]['lead'] : 0 ) + (int) $row['n'];
				}
			} else {
				$report['daily'][ $row['day'] ][ $row['kind'] ] += (int) $row['n'];
			}
		}

		foreach ( $rows as $row ) {
			$n = (int) $row['n'];
			switch ( $row['kind'] ) {
				case 'conversation':
					$report['counts']['conversation'] += $n;
					break;
				case 'search':
					$report['counts'][ 'site' === $row['source'] ? 'search_site' : 'search' ] += $n;
					break;
				case 'visitor':
					$report['counts']['visitor'] += $n;
					break;
				case 'lead':
					// Cotizaciones y comparaciones del chat contadas desde que hay reportería (las
					// mismas fechas que las conversaciones): con esto se calcula la conversión.
					if ( 'test' !== $row['dim'] || $include_test ) {
						$report['counts']['chat_leads'] += $n;
					}
					break;
				case 'criterion':
					$dim = isset( $names[ $row['dim'] ] ) ? $names[ $row['dim'] ] : ucfirst( preg_replace( '/^[a-z]+:/', '', $row['dim'] ) );
					if ( ! isset( $report['criteria'][ $dim ][ $row['label'] ] ) ) {
						$report['criteria'][ $dim ][ $row['label'] ] = array( 'chat' => 0, 'site' => 0 );
					}
					$report['criteria'][ $dim ][ $row['label'] ][ 'site' === $row['source'] ? 'site' : 'chat' ] += $n;
					break;
				case 'view':
					$report['counts']['view'][ $row['source'] ] += $n;
					self::add_item( $report['items']['view'], (int) $row['post_id'], '', $row['source'], $n );
					break;
			}
		}

		// Cotizaciones y comparaciones: de los envíos del sitio si el conector los sabe leer.
		$leads = null;
		if ( class_exists( 'ASI_Extensions' ) ) {
			foreach ( ASI_Extensions::active_connectors() as $connector ) {
				if ( method_exists( $connector, 'report_leads' ) ) {
					$leads = (array) $connector->report_leads( $period['from'], $period['to'] );
					break;
				}
			}
		}
		if ( null !== $leads ) {
			$report['leads'] = 'site';
			foreach ( $leads as $lead ) {
				if ( ! empty( $lead['test'] ) && ! $include_test ) {
					continue;
				}
				$action = 'compare' === $lead['action'] ? 'compare' : 'quote';
				$source = 'chat' === $lead['source'] ? 'chat' : 'site';
				$report['counts'][ $action ][ $source ]++;
				if ( isset( $lead['day'] ) && isset( $report['daily'][ $lead['day'] ] ) ) {
					$report['daily'][ $lead['day'] ][ $source ]++;
				}
				foreach ( (array) $lead['items'] as $item ) {
					self::add_item( $report['items'][ $action ], isset( $item['id'] ) ? (int) $item['id'] : 0, isset( $item['title'] ) ? (string) $item['title'] : '', $source, 1 );
				}
			}
		} else {
			foreach ( $rows as $row ) {
				if ( in_array( $row['kind'], array( 'quote', 'compare' ), true ) && ( 'test' !== $row['dim'] || $include_test ) ) {
					self::add_item( $report['items'][ $row['kind'] ], (int) $row['post_id'], '', 'chat', (int) $row['n'] );
					$report['counts'][ $row['kind'] ]['chat'] += (int) $row['n'];
				}
			}
		}

		// Del lado del chat hay dos cuentas de lo mismo: los envíos (con historial) y lo que la reportería
		// anotó en cada acción del chat. Se queda la mayor, para no perder ninguna de las dos (y la misma
		// en las tarjetas y en la gráfica por día).
		$from_sent = 0;
		$from_note = 0;
		foreach ( $report['daily'] as $row ) {
			$from_sent += $row['chat'];
			$from_note += isset( $row['lead'] ) ? $row['lead'] : 0;
		}
		if ( $from_note > $from_sent || null === $leads ) {
			foreach ( $report['daily'] as $d => $row ) {
				$report['daily'][ $d ]['chat'] = isset( $row['lead'] ) ? $row['lead'] : 0;
			}
		}
		if ( null !== $leads && $from_note > $from_sent ) {
			$noted = array( 'quote' => 0, 'compare' => 0 );
			$items = array( 'quote' => array(), 'compare' => array() );
			foreach ( $rows as $row ) {
				if ( 'test' === $row['dim'] && ! $include_test ) {
					continue;
				}
				if ( 'lead' === $row['kind'] && isset( $noted[ $row['label'] ] ) ) {
					$noted[ $row['label'] ] += (int) $row['n'];
				} elseif ( isset( $items[ $row['kind'] ] ) ) {
					self::add_item( $items[ $row['kind'] ], (int) $row['post_id'], '', 'chat', (int) $row['n'] );
				}
			}
			foreach ( $noted as $kind => $n ) {
				$report['counts'][ $kind ]['chat'] = $n;
				foreach ( $report['items'][ $kind ] as $key => $item ) {
					$report['items'][ $kind ][ $key ]['chat'] = 0;
				}
				foreach ( $items[ $kind ] as $item ) {
					self::add_item( $report['items'][ $kind ], $item['id'], $item['title'], 'chat', $item['chat'] );
				}
			}
		}

		// ¿Da resultados el chat? De cada 100 que usan el chat, cuántos cotizan o comparan; de cada 100
		// que buscan o ven contenido en el sitio (sin el chat), cuántos. Solo los días en que se contaron
		// ambos lados, para que no se mezcle el historial de envíos con días sin visitantes contados.
		$since = (string) get_option( self::VISITORS_SINCE, '' );
		$base  = array( 'conversation' => 0, 'chat' => 0, 'visitor' => 0, 'site' => 0 );
		foreach ( $report['daily'] as $d => $row ) {
			$base['conversation'] += $row['conversation'];
			$base['chat']         += isset( $row['lead'] ) ? $row['lead'] : 0;
			if ( '' !== $since && $d >= $since ) {
				$base['visitor'] += $row['visitor'];
				$base['site']    += $row['site'];
			}
		}
		$report['rate'] = array(
			'chat'  => $base['conversation'] ? min( 1, $base['chat'] / $base['conversation'] ) : null,
			'site'  => $base['visitor'] ? min( 1, $base['site'] / $base['visitor'] ) : null,
			'base'  => $base,
			'since' => $since,
		);

		$sum = function ( array $labels ) {
			$total = 0;
			foreach ( $labels as $pair ) {
				$total += $pair['chat'] + $pair['site'];
			}
			return $total;
		};
		foreach ( $report['criteria'] as $dim => $labels ) {
			uasort(
				$labels,
				function ( $a, $b ) {
					return ( $b['chat'] + $b['site'] ) - ( $a['chat'] + $a['site'] );
				}
			);
			$report['criteria'][ $dim ] = $labels;
		}
		uasort(
			$report['criteria'],
			function ( $a, $b ) use ( $sum ) {
				return $sum( $b ) - $sum( $a );
			}
		);
		self::name_items( $report['items'] );
		$report['totals'] = array();
		foreach ( $report['items'] as $kind => $items ) {
			$report['totals'][ $kind ] = count( $items );
			// Se guardan los 20 primeros de cada fuente, para que "solo chat" y "solo sitio" tengan los suyos.
			$keep = array();
			foreach ( array( 'all', 'chat', 'site' ) as $scope ) {
				foreach ( self::top( $items, $scope ) as $item ) {
					$keep[ ASI_Normalizer::normalize( $item['title'] ) ] = $item;
				}
			}
			$report['items'][ $kind ] = self::top( array_values( $keep ), 'all', count( $keep ) );
		}
		return $report;
	}

	/**
	 * Los primeros de una lista para una fuente (all = chat + sitio), sin los que no tienen ninguno.
	 *
	 * @param array[] $items Lista.
	 * @param string  $scope all|chat|site.
	 * @param int     $max   Cuántos.
	 * @return array[]
	 */
	public static function top( array $items, $scope, $max = self::MAX_ROWS ) {
		$n = function ( $item ) use ( $scope ) {
			return 'all' === $scope ? $item['chat'] + $item['site'] : $item[ $scope ];
		};
		$items = array_values( array_filter( $items, function ( $item ) use ( $n ) {
			return $n( $item ) > 0;
		} ) );
		usort(
			$items,
			function ( $a, $b ) use ( $n ) {
				return $n( $b ) - $n( $a ) ?: strcmp( $a['title'], $b['title'] );
			}
		);
		return array_slice( $items, 0, $max );
	}

	/** Nombre de cada campo: términos del sitio, atributos de los packs y presupuesto. */
	public static function dim_names() {
		$names = array( 'budget' => 'Presupuesto' );
		foreach ( self::TERM_NAMES as $kind => $name ) {
			$names[ 'term:' . $kind ] = $name;
		}
		if ( class_exists( 'ASI_Extensions' ) ) {
			foreach ( ASI_Extensions::active_packs() as $pack ) {
				if ( method_exists( $pack, 'attribute_names' ) ) {
					foreach ( (array) $pack->attribute_names() as $key => $name ) {
						$names[ 'attr:' . $key ] = $name;
					}
				}
			}
		}
		return $names;
	}

	/** Suma a un contenido; los que llegan solo con título (envíos del sitio) se juntan por título. */
	private static function add_item( array &$list, $post_id, $title, $source, $n ) {
		$key = $post_id ? 'id:' . $post_id : 't:' . ASI_Normalizer::normalize( $title );
		if ( 't:' === $key ) {
			return;
		}
		if ( ! isset( $list[ $key ] ) ) {
			$list[ $key ] = array( 'id' => $post_id, 'title' => $title, 'chat' => 0, 'site' => 0 );
		}
		$list[ $key ][ $source ] += $n;
	}

	/** Títulos de los que llegan por ID, y une los que llegan por título con su contenido. */
	private static function name_items( array &$lists ) {
		$ids = array();
		foreach ( $lists as $list ) {
			foreach ( $list as $item ) {
				if ( $item['id'] ) {
					$ids[] = $item['id'];
				}
			}
		}
		$titles = array();
		$ids    = array_values( array_unique( $ids ) );
		if ( $ids ) {
			foreach ( get_posts( array( 'post__in' => $ids, 'post_type' => 'any', 'post_status' => 'any', 'numberposts' => count( $ids ), 'suppress_filters' => true ) ) as $post ) {
				$titles[ $post->ID ] = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
			}
		}
		// Los que llegan solo con título (envíos del sitio): su ficha, si existe con ese mismo título.
		$by_title = array();
		$names    = array();
		foreach ( $lists as $list ) {
			foreach ( $list as $item ) {
				if ( ! $item['id'] && '' !== $item['title'] ) {
					$names[ $item['title'] ] = true;
				}
			}
		}
		$types = self::tracked_types();
		if ( $names && $types ) {
			global $wpdb;
			foreach ( array_chunk( array_keys( $names ), 200 ) as $chunk ) {
				$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"SELECT ID, post_title FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ') AND post_title IN (' . implode( ',', array_fill( 0, count( $chunk ), '%s' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						array_merge( $types, $chunk )
					)
				);
				foreach ( $rows as $row ) {
					$by_title[ ASI_Normalizer::normalize( $row->post_title ) ] = (int) $row->ID;
				}
			}
		}
		foreach ( $lists as $kind => $list ) {
			$merged = array();
			foreach ( $list as $item ) {
				$title = $item['id'] && isset( $titles[ $item['id'] ] ) ? $titles[ $item['id'] ] : ( '' !== $item['title'] ? $item['title'] : '#' . $item['id'] );
				$key   = ASI_Normalizer::normalize( $title );
				if ( ! isset( $merged[ $key ] ) ) {
					$merged[ $key ] = array( 'id' => $item['id'], 'title' => $title, 'chat' => 0, 'site' => 0 );
				}
				$merged[ $key ]['chat'] += $item['chat'];
				$merged[ $key ]['site'] += $item['site'];
				if ( ! $merged[ $key ]['id'] ) {
					$merged[ $key ]['id'] = $item['id'] ? $item['id'] : ( isset( $by_title[ $key ] ) ? $by_title[ $key ] : 0 );
				}
			}
			uasort(
				$merged,
				function ( $a, $b ) {
					return ( $b['chat'] + $b['site'] ) - ( $a['chat'] + $a['site'] ) ?: strcmp( $a['title'], $b['title'] );
				}
			);
			$lists[ $kind ] = array_values( $merged );
		}
	}

	/**
	 * El reporte en CSV (se abre en Excel).
	 *
	 * @param array  $report De build().
	 * @param string $scope  all|chat|site.
	 * @return string
	 */
	public static function csv( array $report, $scope ) {
		$scope = in_array( $scope, array( 'chat', 'site' ), true ) ? $scope : 'all';
		$names = array( 'all' => 'Global (chat y sitio)', 'chat' => 'Solo chat', 'site' => 'Solo sitio' );
		$out   = fopen( 'php://temp', 'r+' );
		fwrite( $out, "\xEF\xBB\xBF" );
		$put = function ( array $row ) use ( $out ) {
			fputcsv( $out, $row );
		};
		$c   = $report['counts'];
		$pick = function ( array $pair ) use ( $scope ) {
			return 'all' === $scope ? $pair['chat'] + $pair['site'] : $pair[ $scope ];
		};
		$put( array( 'Reporte del asesor inteligente', $names[ $scope ] ) );
		$put( array( 'Periodo', $report['period']['from'] . ' a ' . $report['period']['to'] ) );
		$put( array() );
		$put( array( 'Resumen', 'Total' ) );
		if ( 'site' !== $scope ) {
			$put( array( 'Conversaciones en el chat', $c['conversation'] ) );
			$put( array( 'Búsquedas en el chat', $c['search'] ) );
		}
		if ( 'chat' !== $scope ) {
			$put( array( 'Búsquedas y filtros en el sitio', $c['search_site'] ) );
		}
		$put( array( 'Cotizaciones', $pick( $c['quote'] ) ) );
		$put( array( 'Comparaciones', $pick( $c['compare'] ) ) );
		if ( 'site' !== $scope && $c['conversation'] ) {
			$put( array( 'Conversaciones que terminaron en cotización o comparación', round( 100 * min( 1, $c['chat_leads'] / $c['conversation'] ), 1 ) . '%' ) );
		}
		if ( $report['criteria'] ) {
			$put( array() );
			$put( array( 'Lo más buscado' ) );
			$put( 'all' === $scope ? array( 'Campo', 'Valor', 'Total', 'Chat', 'Sitio' ) : array( 'Campo', 'Valor', 'Veces' ) );
			foreach ( $report['criteria'] as $dim => $labels ) {
				$rows = array();
				foreach ( $labels as $label => $pair ) {
					$rows[] = array( 'title' => (string) $label, 'chat' => $pair['chat'], 'site' => $pair['site'] );
				}
				foreach ( self::top( $rows, $scope ) as $row ) {
					$put( 'all' === $scope ? array( $dim, $row['title'], $row['chat'] + $row['site'], $row['chat'], $row['site'] ) : array( $dim, $row['title'], $pick( $row ) ) );
				}
			}
		}
		$titles = array( 'view' => 'Lo más visto', 'quote' => 'Lo más cotizado', 'compare' => 'Lo más comparado' );
		foreach ( $titles as $kind => $title ) {
			$put( array() );
			$put( array( $title ) );
			$put( 'all' === $scope ? array( 'Contenido', 'Total', 'Chat', 'Sitio' ) : array( 'Contenido', 'Veces' ) );
			foreach ( self::top( $report['items'][ $kind ], $scope ) as $item ) {
				$n = $pick( $item );
				$put( 'all' === $scope ? array( $item['title'], $n, $item['chat'], $item['site'] ) : array( $item['title'], $n ) );
			}
		}
		rewind( $out );
		$csv = stream_get_contents( $out );
		fclose( $out );
		return $csv;
	}
}
