<?php
/**
 * Reportería: qué busca la gente en el chat, qué ve, qué cotiza y qué compara, desde el chat y
 * desde el sitio.
 *
 * - Contadores por día en una tabla pequeña (día, origen, tipo, campo, valor, contenido → veces),
 *   sin datos personales. Un INSERT … ON DUPLICATE KEY por evento.
 * - Chat: conversaciones, búsquedas y cada campo que pide (tipo, marca, características,
 *   presupuesto en rangos); cuenta lo que cambió, no lo que ya pedía.
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
							'in'  => array( 'type' => 'integer', 'default' => 0 ),
						),
					)
				);
			}
		);
		add_action( 'wp_footer', array( __CLASS__, 'print_beacon' ), 99 );
		add_action( self::PURGE_HOOK, array( __CLASS__, 'purge' ) );
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
	 * Si llegó desde otra página del mismo sitio (le dio clic a su tarjeta en el inventario, la
	 * portada o una ficha), también es un clic del sitio; si llegó de Google o un enlace, solo vista.
	 */
	public static function print_beacon() {
		if ( is_admin() || ! is_singular( self::tracked_types() ) ) {
			return;
		}
		$id  = (int) get_queried_object_id();
		$url = rest_url( ASI_REST_Controller::NAMESPACE_V1 . '/view' );
		?>
<script>(function(){try{var id=<?php echo (int) $id; ?>,src='site';try{var m=JSON.parse(localStorage.getItem('asi_from_chat')||'{}');if(m.id===id&&Date.now()-m.t<1800000){src='chat';}localStorage.removeItem('asi_from_chat');}catch(e){}var ins=0;try{var r=new URL(document.referrer);ins=r.host===location.host&&r.pathname!==location.pathname?1:0;}catch(e){}var b=JSON.stringify({id:id,src:src,'in':ins}),u=<?php echo wp_json_encode( $url ); ?>;if(navigator.sendBeacon){navigator.sendBeacon(u,new Blob([b],{type:'application/json'}));}else{fetch(u,{method:'POST',body:b,keepalive:true,headers:{'Content-Type':'application/json'}});}}catch(e){}})();</script>
		<?php
	}

	public static function view_endpoint( WP_REST_Request $request ) {
		$id  = (int) $request->get_param( 'id' );
		$src = 'chat' === $request->get_param( 'src' ) ? 'chat' : 'site';
		if ( $id && 'publish' === get_post_status( $id ) && in_array( get_post_type( $id ), self::tracked_types(), true ) ) {
			self::view( $id, $src );
			// Clic en su tarjeta: en el chat (resultado abierto) o en el sitio (desde otra página).
			if ( 'chat' === $src || (int) $request->get_param( 'in' ) ) {
				self::click( $id, $src );
			}
		}
		$rest = rest_ensure_response( array( 'ok' => true ) );
		$rest->header( 'Cache-Control', 'no-store' );
		return $rest;
	}

	/** Un clic en la tarjeta de un contenido (una vez al día por visitante y contenido). */
	public static function click( $post_id, $source ) {
		if ( self::once( 'click|' . (int) $post_id ) ) {
			self::add( $source, 'click', '', '', (int) $post_id );
		}
	}

	/** Una vista (una vez al día por visitante y contenido). */
	public static function view( $post_id, $source ) {
		if ( self::once( 'view|' . (int) $post_id ) ) {
			self::add( $source, 'view', '', '', (int) $post_id );
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
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ua   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 200 ) : '';
		$rate = 'asi_rp_' . md5( $ip );
		$n    = (int) get_transient( $rate );
		if ( $n >= self::PER_MINUTE ) {
			return false;
		}
		set_transient( $rate, $n + 1, MINUTE_IN_SECONDS );
		$key = 'asi_ro_' . md5( wp_salt( 'nonce' ) . '|' . $ip . '|' . $ua . '|' . current_time( 'Y-m-d' ) . '|' . $what );
		if ( get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, 1, DAY_IN_SECONDS );
		return true;
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

		$report = array(
			'period'   => $period,
			'counts'   => array(
				'conversation' => 0,
				'search'       => 0,
				'chat_leads'   => 0,
				'view'         => array( 'chat' => 0, 'site' => 0 ),
				'click'        => array( 'chat' => 0, 'site' => 0 ),
				'quote'        => array( 'chat' => 0, 'site' => 0 ),
				'compare'      => array( 'chat' => 0, 'site' => 0 ),
			),
			'criteria' => array(),
			'items'    => array( 'click' => array(), 'view' => array(), 'quote' => array(), 'compare' => array() ),
			'leads'    => 'chat',
		);
		$names = self::dim_names();

		foreach ( $rows as $row ) {
			$n = (int) $row['n'];
			switch ( $row['kind'] ) {
				case 'conversation':
				case 'search':
					$report['counts'][ $row['kind'] ] += $n;
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
						$report['criteria'][ $dim ][ $row['label'] ] = 0;
					}
					$report['criteria'][ $dim ][ $row['label'] ] += $n;
					break;
				case 'view':
				case 'click':
					$report['counts'][ $row['kind'] ][ $row['source'] ] += $n;
					self::add_item( $report['items'][ $row['kind'] ], (int) $row['post_id'], '', $row['source'], $n );
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

		foreach ( $report['criteria'] as $dim => $labels ) {
			arsort( $labels );
			$report['criteria'][ $dim ] = array_slice( $labels, 0, self::MAX_ROWS, true );
		}
		uasort(
			$report['criteria'],
			function ( $a, $b ) {
				return array_sum( $b ) - array_sum( $a );
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
		$put( array( 'Cotizaciones', $pick( $c['quote'] ) ) );
		$put( array( 'Comparaciones', $pick( $c['compare'] ) ) );
		if ( 'site' !== $scope && $c['conversation'] ) {
			$put( array( 'Conversaciones que terminaron en cotización o comparación', round( 100 * min( 1, $c['chat_leads'] / $c['conversation'] ), 1 ) . '%' ) );
		}
		if ( 'site' !== $scope && $report['criteria'] ) {
			$put( array() );
			$put( array( 'Lo más buscado en el chat' ) );
			$put( array( 'Campo', 'Valor', 'Veces' ) );
			foreach ( $report['criteria'] as $dim => $labels ) {
				foreach ( $labels as $label => $n ) {
					$put( array( $dim, $label, $n ) );
				}
			}
		}
		$titles = array( 'click' => 'Lo más buscado: vehículos más cliqueados', 'view' => 'Lo más visto', 'quote' => 'Lo más cotizado', 'compare' => 'Lo más comparado' );
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
