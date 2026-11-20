<?php
/**
 * Solicitudes de cita desde el chat, sin calendario.
 *
 * El chat no da fecha ni hora: toma nombre, correo y cuándo le gustaría al cliente, y lo pasa a
 * los asesores igual que una cotización (queda en los Envíos de Elementor y llega un aviso por
 * correo). Se enciende por sitio en el panel (pestaña Contacto); apagado, el chat responde con los
 * datos de contacto. Otro plugin (p. ej. el de HubSpot) puede tomar cada solicitud con la acción
 * `asi_appointment_request`.
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || defined( 'ASI_CLI_TESTS' ) || exit;

class ASI_Appointments {

	const MODES        = array( 'off', 'request' );
	const LIMIT_PER_HR = 4;

	/** ¿El sitio recibe solicitudes de cita por el chat? */
	public function enabled() {
		return 'request' === self::mode();
	}

	public static function mode() {
		$c = ASI_Settings::all()['contact'];
		return isset( $c['appointments'] ) && in_array( $c['appointments'], self::MODES, true ) ? $c['appointments'] : 'off';
	}

	/**
	 * Envía la solicitud a los asesores.
	 *
	 * @param array  $customer name, email, phone.
	 * @param string $when     Cuándo le gustaría (tal como lo escribió).
	 * @param int    $item_id  Vehículo o producto que estaba viendo (0 = ninguno).
	 * @param array  $extra    cid (conversación, para el resumen) y kind ('' cita, 'test_drive' prueba de manejo).
	 * @return array|WP_Error { test: bool }
	 */
	public function submit( array $customer, $when, $item_id = 0, array $extra = array() ) {
		if ( ! $this->enabled() ) {
			return new WP_Error( 'asi_appt_off', 'Las solicitudes de cita están apagadas.' );
		}
		$customer = array(
			'name'  => sanitize_text_field( isset( $customer['name'] ) ? (string) $customer['name'] : '' ),
			'email' => sanitize_email( isset( $customer['email'] ) ? (string) $customer['email'] : '' ),
			'phone' => sanitize_text_field( isset( $customer['phone'] ) ? (string) $customer['phone'] : '' ),
		);
		$when = sanitize_text_field( (string) $when );
		if ( '' === $customer['name'] || ! is_email( $customer['email'] ) || '' === $when ) {
			return new WP_Error( 'asi_customer', 'Faltan datos del cliente.' );
		}
		if ( ! ASI_Actions::within_limit( 'appt', self::LIMIT_PER_HR ) ) {
			return new WP_Error( 'asi_limit', 'Demasiadas solicitudes seguidas.' );
		}
		$item_id = absint( $item_id );
		$item_id = $item_id && 'publish' === get_post_status( $item_id ) ? $item_id : 0;
		$test    = 'test' === ASI_Actions::mode();
		$drive   = isset( $extra['kind'] ) && 'test_drive' === $extra['kind'];
		// Lo más importante de la conversación, para que el asesor no empiece de cero.
		$summary = class_exists( 'ASI_Chat_Log' ) && ! empty( $extra['cid'] ) ? ASI_Chat_Log::summary( (string) $extra['cid'] ) : array( 'lines' => array(), 'budget' => '', 'last_seen' => '' );

		self::save_submission( $customer, $when, $item_id, $test, $drive, $summary );
		ASI_Reports::add( 'chat', 'lead', $test ? 'test' : '', $drive ? 'test_drive' : 'appointment' );
		$to = $test ? ASI_Settings::email_list( (string) ASI_Settings::all()['test_email'] ) : self::recipients();
		if ( $to ) {
			self::notify( $to, $customer, $when, $item_id, $test, $drive, $summary );
		}
		/**
		 * Una solicitud de cita del chat (para mandarla a un CRM como HubSpot).
		 *
		 * @param array $request name, email, phone, when, item_id, item_title, test.
		 */
		do_action(
			'asi_appointment_request',
			array_merge(
				$customer,
				array(
					'when'       => $when,
					'item_id'    => $item_id,
					'item_title' => $item_id ? wp_strip_all_tags( get_the_title( $item_id ) ) : '',
					'kind'       => $drive ? 'test_drive' : 'appointment',
					'summary'    => $summary['lines'],
					'budget'     => $summary['budget'],
					'test'       => $test,
				)
			)
		);
		return array( 'test' => $test );
	}

	/** A quién avisar: los correos de las citas; si no hay, los de contacto; si no, el del sitio. */
	public static function recipients() {
		$c = ASI_Settings::all()['contact'];
		foreach ( array( isset( $c['appointments_notify'] ) ? $c['appointments_notify'] : '', $c['email'] ) as $list ) {
			$emails = ASI_Settings::email_list( (string) $list );
			if ( $emails ) {
				return $emails;
			}
		}
		return array( get_option( 'admin_email' ) );
	}

	/** Queda en los Envíos de Elementor, junto a los formularios del sitio. */
	private static function save_submission( array $customer, $when, $item_id, $test, $drive = false, array $summary = array() ) {
		if ( ! class_exists( '\ElementorPro\Modules\Forms\Submissions\Database\Query' ) ) {
			return false;
		}
		$title  = $item_id ? wp_strip_all_tags( get_the_title( $item_id ) ) : '';
		$fields = array(
			array( 'id' => 'nombre', 'type' => 'text', 'value' => $customer['name'] ),
			array( 'id' => 'correo', 'type' => 'email', 'value' => $customer['email'] ),
			array( 'id' => 'telefono', 'type' => 'text', 'value' => $customer['phone'] ),
			array( 'id' => 'cuando', 'type' => 'text', 'value' => $when ),
			array( 'id' => 'tipo_consulta', 'type' => 'hidden', 'value' => $drive ? 'Prueba de manejo (chat)' : 'Solicitud de cita (chat)' ),
			array( 'id' => 'vehiculo_interes', 'type' => 'hidden', 'value' => $title ),
			array( 'id' => 'presupuesto', 'type' => 'hidden', 'value' => isset( $summary['budget'] ) ? $summary['budget'] : '' ),
			array( 'id' => 'resumen_chat', 'type' => 'textarea', 'value' => ! empty( $summary['lines'] ) ? '• ' . implode( "\n• ", $summary['lines'] ) : '' ),
		);
		try {
			$query = \ElementorPro\Modules\Forms\Submissions\Database\Query::get_instance();
			return (bool) $query->add_submission(
				array(
					'main_meta_id'            => 0,
					'post_id'                 => $item_id,
					'element_id'              => 'asi-cita',
					'form_name'               => ( $drive ? 'Prueba de manejo · chat' : 'Solicitud de cita · chat' ) . ( $test ? ' (prueba)' : '' ),
					'referer'                 => $item_id ? get_permalink( $item_id ) : home_url( '/' ),
					'referer_title'           => '' !== $title ? $title : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
					'campaign_id'             => 0,
					'user_id'                 => get_current_user_id(),
					'user_ip'                 => '',
					'user_agent'              => 'Asesor Inteligente (chat)',
					'is_read'                 => 0,
					'actions_count'           => 1,
					'actions_succeeded_count' => 1,
					'meta'                    => wp_json_encode( array( 'source' => 'asesor-inteligente' ) ),
				),
				$fields
			);
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	private static function notify( array $to, array $customer, $when, $item_id, $test, $drive = false, array $summary = array() ) {
		$what = $drive ? 'prueba de manejo' : 'cita';
		$rows = array(
			'Nombre'         => $customer['name'],
			'Correo'         => array( 'text' => $customer['email'], 'url' => 'mailto:' . $customer['email'] ),
			'Teléfono'       => '' !== $customer['phone'] ? array( 'text' => $customer['phone'], 'url' => 'tel:' . preg_replace( '/[^0-9+]/', '', $customer['phone'] ) ) : '',
			'Le gustaría'    => $when,
			'Le interesa'    => $item_id ? array( 'text' => wp_strip_all_tags( get_the_title( $item_id ) ), 'url' => get_permalink( $item_id ) ) : ( ! empty( $summary['last_seen'] ) ? $summary['last_seen'] : '' ),
			'Presupuesto'    => ! empty( $summary['budget'] ) ? $summary['budget'] : '',
			'Fecha'          => wp_date( 'j \d\e F, H:i' ),
		);
		ASI_Mailer::send(
			$to,
			( $test ? '[PRUEBA] ' : '' ) . 'Solicitud de ' . $what . ' desde el chat: ' . $customer['name'],
			array(
				'badge'   => $test ? 'PRUEBA · ESTE AVISO NO LLEGÓ A VENTAS' : '',
				'title'   => 'Solicitud de ' . $what . ' desde el chat',
				'intro'   => $customer['name'] . ' quiere una ' . $what . '. El chat no le dio fecha ni hora: hay que contactarle para confirmarla.',
				'rows'    => $rows,
				// Lo que habló con el chat antes de pedirla (lo más importante, en orden).
				'summary' => ! empty( $summary['lines'] ) ? array( 'title' => 'Lo que habló con el chat', 'lines' => $summary['lines'] ) : array(),
				'button' => array(
					'label' => 'Responder a ' . strtok( $customer['name'], ' ' ),
					'url'   => 'mailto:' . $customer['email'] . '?subject=' . rawurlencode( 'Tu ' . $what . ' en ' . ASI_Mailer::site_name() ),
				),
				'note'   => 'Enviado por el asistente del sitio.',
			),
			array( ASI_Actions::reply_to( $customer ) )
		);
	}
}
