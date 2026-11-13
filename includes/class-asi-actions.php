<?php
/**
 * Acciones desde el chat (WordPress): cotización y comparación en PDF, cuotas.
 *
 * - El PDF lo genera el conector del sitio; se guarda con nombre aleatorio y se entrega por un
 *   enlace firmado que vence en 24 h (GET /wp-json/asi/v1/file).
 * - Al cliente se le envía con el correo del sitio (conector) o, si no hay, con wp_mail.
 * - El lead se guarda donde el sitio guarda los de su formulario y se avisa a ventas.
 * - Modo prueba (por defecto): el aviso a ventas solo va al correo de prueba del panel.
 * - Límite: 6 acciones por visitante cada hora.
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || exit;

final class ASI_Actions implements ASI_Action_Runner {

	const DIR          = 'asi-files';
	const TTL          = DAY_IN_SECONDS;
	const LIMIT_PER_HR = 6;

	/**
	 * Rutas y limpieza.
	 */
	public static function register() {
		add_action(
			'rest_api_init',
			static function () {
				register_rest_route(
					'asi/v1',
					'/file',
					array(
						'methods'             => 'GET',
						'callback'            => array( __CLASS__, 'serve' ),
						'permission_callback' => '__return_true',
						'args'                => array(
							't' => array( 'type' => 'string', 'required' => true ),
							'e' => array( 'type' => 'integer', 'required' => true ),
							's' => array( 'type' => 'string', 'required' => true ),
						),
					)
				);
			}
		);
	}

	public function available() {
		if ( 'off' === self::mode() ) {
			return array();
		}
		$out = array();
		foreach ( ASI_Extensions::active_connectors() as $connector ) {
			$out = array_merge( $connector->actions(), $out );
		}
		return $out;
	}

	public function run( $action, array $item_ids, array $customer ) {
		if ( 'off' === self::mode() ) {
			return new WP_Error( 'asi_actions_off', 'Las acciones del chat están apagadas.' );
		}
		$connector = ASI_Extensions::connector_for( $action );
		if ( ! $connector ) {
			return new WP_Error( 'asi_no_connector', 'Esta acción no está disponible en el sitio.' );
		}
		$customer = self::clean_customer( $customer );
		if ( ! is_email( $customer['email'] ) || '' === $customer['name'] ) {
			return new WP_Error( 'asi_customer', 'Faltan datos del cliente.' );
		}
		$item_ids = array_values( array_filter( array_map( 'absint', $item_ids ), static function ( $id ) {
			return 'publish' === get_post_status( $id );
		} ) );
		if ( ! $item_ids ) {
			return new WP_Error( 'asi_no_items', 'Los productos ya no están publicados.' );
		}
		if ( ! self::within_limit() ) {
			return new WP_Error( 'asi_limit', 'Demasiadas solicitudes seguidas.' );
		}

		$file = $connector->run( $action, $item_ids, $customer );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$url = self::store( $file );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$test    = 'test' === self::mode();
		$emailed = $connector->email_customer( $action, $item_ids, $customer, $file );
		if ( null === $emailed ) {
			$emailed = self::email_file( $customer['email'], $file, 'compare' === $action ? 'Tu comparación' : 'Tu cotización', $item_ids, $customer );
		}

		$lead   = $connector->save_lead( $action, $item_ids, $customer, $test );
		ASI_Reports::chat_action( $action, $item_ids, $test );
		$notify = $test ? ASI_Settings::email_list( (string) ASI_Settings::all()['test_email'] ) : (array) $lead['notify'];
		if ( $notify ) {
			self::notify_sales( $notify, $action, $item_ids, $customer, $test );
		}

		return array(
			'url'      => $url,
			'filename' => $file['filename'],
			'emailed'  => true === $emailed,
			'test'     => $test,
		);
	}

	public function preview( $action, array $item_ids ) {
		if ( 'off' === self::mode() ) {
			return new WP_Error( 'asi_actions_off', 'Las acciones del chat están apagadas.' );
		}
		$connector = ASI_Extensions::connector_for( $action );
		if ( ! $connector ) {
			return new WP_Error( 'asi_no_connector', 'Esta acción no está disponible en el sitio.' );
		}
		$item_ids = array_values( array_filter( array_map( 'absint', $item_ids ), static function ( $id ) {
			return 'publish' === get_post_status( $id );
		} ) );
		if ( ! $item_ids || ! self::within_limit( 'preview', 20 ) ) {
			return new WP_Error( 'asi_preview', 'No se pudo generar la vista previa.' );
		}
		$file = $connector->run( $action, $item_ids, array() );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$url = self::store( $file );
		return is_wp_error( $url ) ? $url : array( 'url' => $url, 'filename' => $file['filename'] );
	}

	public function financing( $item_id ) {
		$connector = null;
		foreach ( ASI_Extensions::active_connectors() as $candidate ) {
			$rules = $candidate->financing( (int) $item_id );
			if ( $rules ) {
				return $rules;
			}
		}
		$settings = ASI_Settings::all();
		if ( empty( $settings['finance_rate'] ) ) {
			return null;
		}
		return array(
			'rate'         => (float) $settings['finance_rate'] / 100,
			'down_min'     => (float) $settings['finance_down_min'] / 100,
			'down_max'     => 0.9,
			'down_default' => (float) $settings['finance_down_min'] / 100,
			'terms'        => array( 12, 24, 36, 48, 60, 72 ),
		);
	}

	/** off / test / live. */
	public static function mode() {
		$mode = (string) ASI_Settings::all()['actions_mode'];
		return in_array( $mode, array( 'off', 'test', 'live' ), true ) ? $mode : 'test';
	}

	// ---------- Archivos ----------

	private static function dir() {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . self::DIR;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			// Nada se lista ni se sirve directo (Apache); en Nginx el nombre aleatorio lo protege.
			file_put_contents( $dir . '/index.php', "<?php\n// Silence.\n" );
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
		}
		return $dir;
	}

	/**
	 * Guarda el archivo y devuelve el enlace firmado. De paso borra los vencidos.
	 */
	private static function store( array $file ) {
		$dir   = self::dir();
		$token = bin2hex( random_bytes( 16 ) );
		$name  = $token . '.pdf';
		if ( false === file_put_contents( $dir . '/' . $name, $file['binary'] ) ) {
			return new WP_Error( 'asi_store', 'No se pudo guardar el archivo.' );
		}
		update_option( 'asi_file_' . $token, sanitize_file_name( $file['filename'] ), false );
		foreach ( (array) glob( $dir . '/*.pdf' ) as $old ) {
			if ( filemtime( $old ) < time() - self::TTL ) {
				delete_option( 'asi_file_' . basename( $old, '.pdf' ) );
				wp_delete_file( $old );
			}
		}
		$expires = time() + self::TTL;
		return add_query_arg(
			array(
				't' => $token,
				'e' => $expires,
				's' => self::sign( $token, $expires ),
			),
			rest_url( 'asi/v1/file' )
		);
	}

	private static function sign( $token, $expires ) {
		return substr( hash_hmac( 'sha256', $token . '|' . (int) $expires, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * GET /asi/v1/file: entrega el PDF en el navegador (se ve dentro del chat o se descarga).
	 */
	public static function serve( WP_REST_Request $request ) {
		$token   = (string) $request->get_param( 't' );
		$expires = (int) $request->get_param( 'e' );
		$sig     = (string) $request->get_param( 's' );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $token ) || $expires < time() || ! hash_equals( self::sign( $token, $expires ), $sig ) ) {
			return new WP_Error( 'asi_file', 'El enlace venció o no es válido.', array( 'status' => 403 ) );
		}
		$path = self::dir() . '/' . $token . '.pdf';
		if ( ! is_file( $path ) ) {
			return new WP_Error( 'asi_file', 'El archivo ya no está disponible.', array( 'status' => 404 ) );
		}
		$filename = (string) get_option( 'asi_file_' . $token, 'documento.pdf' );
		$download = (bool) $request->get_param( 'download' );
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: ' . ( $download ? 'attachment' : 'inline' ) . '; filename="' . str_replace( '"', '', $filename ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	// ---------- Correo ----------

	/**
	 * Respaldo cuando el sitio no tiene su propio correo: la plantilla del asistente con el PDF.
	 */
	private static function email_file( $to, array $file, $subject, array $item_ids, array $customer ) {
		$path = trailingslashit( get_temp_dir() ) . sanitize_file_name( $file['filename'] );
		file_put_contents( $path, $file['binary'] );
		$sent = ASI_Mailer::send(
			$to,
			$subject,
			array(
				'title' => $subject,
				'intro' => sprintf( 'Hola %s, gracias por tu interés. Te adjuntamos el documento que pediste; si tienes cualquier duda, responde a este correo.', strtok( $customer['name'], ' ' ) ),
				'items' => self::mail_items( $item_ids ),
			),
			array(),
			array( $path )
		);
		wp_delete_file( $path );
		return $sent ? true : new WP_Error( 'asi_mail', 'No se pudo enviar el correo.' );
	}

	/**
	 * Aviso a ventas con la imagen del sitio: quién es, cómo contactarlo y qué le interesó.
	 */
	private static function notify_sales( array $to, $action, array $item_ids, array $customer, $test ) {
		$what  = 'compare' === $action ? 'una comparación' : ( count( $item_ids ) > 1 ? 'una cotización de ' . count( $item_ids ) . ' vehículos' : 'una cotización' );
		$rows  = array(
			'Nombre'   => $customer['name'],
			'Correo'   => array( 'text' => $customer['email'], 'url' => 'mailto:' . $customer['email'] ),
			'Teléfono' => '' !== $customer['phone'] ? array( 'text' => $customer['phone'], 'url' => 'tel:' . preg_replace( '/[^0-9+]/', '', $customer['phone'] ) ) : '',
			'Pidió'    => ucfirst( $what ) . ' en PDF (ya la recibió en su correo)',
			'Fecha'    => wp_date( 'j \d\e F, H:i' ),
		);
		$subject = ( $test ? '[PRUEBA] ' : '' ) . 'Nuevo interesado desde el chat: ' . $customer['name'];
		ASI_Mailer::send(
			$to,
			$subject,
			array(
				'badge'  => $test ? 'PRUEBA · ESTE AVISO NO LLEGÓ A VENTAS' : '',
				'title'  => 'Nuevo interesado desde el chat',
				'intro'  => $customer['name'] . ' pidió ' . $what . ' al asistente del sitio. Estos son sus datos y lo que le interesó.',
				'rows'   => $rows,
				'items'  => self::mail_items( $item_ids ),
				'button' => array(
					'label' => 'Responder a ' . strtok( $customer['name'], ' ' ),
					'url'   => 'mailto:' . $customer['email'] . '?subject=' . rawurlencode( 'Tu ' . ( 'compare' === $action ? 'comparación' : 'cotización' ) . ' en ' . ASI_Mailer::site_name() ),
				),
				'note'   => 'Enviado por el asistente del sitio.',
			),
			array( 'Reply-To: ' . $customer['name'] . ' <' . $customer['email'] . '>' )
		);
	}

	/**
	 * Productos para el correo: foto, precio con su moneda y datos clave del índice.
	 */
	private static function mail_items( array $item_ids ) {
		global $wpdb;
		$money = ASI_Settings::money();
		$items = array();
		foreach ( $item_ids as $id ) {
			$card = json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT card FROM ' . ASI_Index_Table::name() . ' WHERE post_id = %d LIMIT 1', $id ) ), true ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$card  = is_array( $card ) ? $card : array();
			$price = ASI_Money::card_price( $card, $money );
			$specs = array();
			foreach ( isset( $card['specs'] ) ? array_slice( (array) $card['specs'], 0, 3 ) : array() as $spec ) {
				$specs[] = $spec['value'];
			}
			$items[] = array(
				'title' => wp_strip_all_tags( get_the_title( $id ) ),
				'url'   => get_permalink( $id ),
				'image' => get_the_post_thumbnail_url( $id, 'medium' ),
				'price' => $price['amount'] ? ASI_Money::format( $price['amount'], '' !== $price['currency'] ? $price['currency'] : $money['base'] ) : '',
				'meta'  => implode( ' · ', array_filter( array_merge( array( isset( $card['category'] ) ? $card['category'] : '' ), $specs ) ) ),
			);
		}
		return $items;
	}

	// ---------- Utilidades ----------

	private static function clean_customer( array $customer ) {
		return array(
			'name'  => sanitize_text_field( isset( $customer['name'] ) ? (string) $customer['name'] : '' ),
			'email' => sanitize_email( isset( $customer['email'] ) ? (string) $customer['email'] : '' ),
			'phone' => preg_replace( '/[^0-9+ -]/', '', isset( $customer['phone'] ) ? (string) $customer['phone'] : '' ),
		);
	}

	private static function within_limit( $kind = 'action', $max = self::LIMIT_PER_HR ) {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'cli';
		$key = 'asi_' . ( 'action' === $kind ? 'act' : substr( $kind, 0, 4 ) ) . '_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= $max && ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		return true;
	}
}
