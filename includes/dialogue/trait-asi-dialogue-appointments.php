<?php
/**
 * "Quiero agendar una cita": el chat no da fecha ni hora. Si el sitio recibe solicitudes (panel,
 * pestaña Contacto), toma nombre, correo y cuándo le gustaría, y la pasa a los asesores. Si no,
 * responde con los datos de contacto. Nunca se queda sin respuesta.
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || defined( 'ASI_CLI_TESTS' ) || exit;

trait ASI_Dialogue_Appointments {

	/** El servicio de citas del sitio (o null). */
	private function appointments() {
		$service = isset( $this->context['appointments'] ) ? $this->context['appointments'] : null;
		return is_object( $service ) && method_exists( $service, 'enabled' ) ? $service : null;
	}

	private function appointment_flow( $message, $normalized, array $state ) {
		$service = $this->appointments();

		// Juntando los datos de la solicitud.
		if ( isset( $state['pending']['action'] ) && 'appointment' === $state['pending']['action'] ) {
			if ( $this->words_in( $normalized, 'cancel_words' ) && str_word_count( $normalized ) <= 4 ) {
				return $this->appointment_reply( 'appt_cancelled', array(), array( 'pending' => array(), 'appt' => '', 'appt_kind' => '' ) );
			}
			list( $given, $when ) = $this->appointment_data( $message, $normalized, true, $state );
			// No dio datos sino que pidió otra cosa ("mejor muéstrame pickups"): se deja la cita.
			if ( ! $given && '' === $when ) {
				$state['pending'] = array();
				$state['appt']    = '';
				$reply            = $this->route( $message, $normalized, $state );
				$reply['state']   = array_merge( isset( $reply['state'] ) ? $reply['state'] : array(), array( 'pending' => array(), 'appt' => '', 'appt_kind' => '' ) );
				return $reply;
			}
			$state['customer'] = array_merge( $state['customer'], $given );
			if ( $this->words_in( $normalized, 'test_drive_words' ) ) {
				$state['appt_kind'] = 'test_drive'; // "Mejor una prueba de manejo".
			}
			if ( '' !== $when ) {
				// El día concreto ("viernes 27 de noviembre a las 8:00"); si ya pasó o no dice qué día, se pregunta.
				$check = $this->when_check( $when );
				if ( '' === $check['label'] ) {
					return $this->when_retry( $check, $state );
				}
				$state['appt'] = $check['label'];
			}
			return $this->appointment_next( $state, $service );
		}

		if ( ! $this->words_in( $normalized, 'appointment_words' ) || str_word_count( $normalized ) > 25 ) {
			return null;
		}
		// El sitio no recibe solicitudes: los datos de contacto para coordinarla.
		// "Quiero hacer una prueba de manejo": lo mismo que una cita, con sus palabras.
		$state['appt_kind'] = $this->words_in( $normalized, 'test_drive_words' ) ? 'test_drive' : '';
		if ( ! $service || ! $service->enabled() ) {
			$reply            = $this->appointment_reply( 'appt_off', $this->appointment_words_for( $state ), array() );
			$reply['contact'] = $this->contact_block( 'appt_contact_title', $this->appointment_words_for( $state ) );
			if ( ! $reply['contact'] ) {
				$page = $this->info_page( 'contact' );
				if ( $page ) {
					$reply['links'] = $this->links( array( $page ) );
				}
			}
			return $reply;
		}
		// "Quiero una cita el sábado, soy Ana, ana@…": lo que ya dio cuenta.
		list( $given, $when ) = $this->appointment_data( $message, $normalized, false, $state );
		$state['customer'] = array_merge( $state['customer'], $given );
		$check             = '' !== $when ? $this->when_check( $when ) : array( 'label' => '', 'past' => false );
		$state['appt']     = $check['label'];
		$state['pending']  = array( 'action' => 'appointment', 'ids' => $state['focus'] ? array( (int) $state['focus'] ) : array() );
		$next              = $this->appointment_next( $state, $service, true );
		if ( ! empty( $check['past'] ) ) {
			$next['text'] = $this->responder->say( 'appt_when_past_note', array( 'when' => $check['past_label'] ) ) . ' ' . $next['text'];
		}
		return $next;
	}

	/**
	 * Pide lo que falta (correo, nombre, cuándo) o envía la solicitud.
	 */
	private function appointment_next( array $state, $service, $first = false ) {
		$customer = $state['customer'];
		$vars     = array_merge(
			$this->appointment_words_for( $state ),
			array(
				'name'  => ! empty( $customer['name'] ) ? strtok( $customer['name'], ' ' ) : '',
				'email' => isset( $customer['email'] ) ? $customer['email'] : '',
				'when'  => (string) $state['appt'],
			)
		);
		$keep = array( 'pending' => $state['pending'], 'customer' => $customer, 'appt' => (string) $state['appt'], 'appt_kind' => (string) $state['appt_kind'] );
		if ( empty( $customer['email'] ) || empty( $customer['name'] ) || '' === (string) $state['appt'] ) {
			if ( $first ) {
				$missing = array();
				foreach ( array( 'name' => 'appt_need_name', 'email' => 'appt_need_email' ) as $key => $phrase ) {
					if ( empty( $customer[ $key ] ) ) {
						$missing[] = $this->responder->say( $phrase );
					}
				}
				if ( '' === (string) $state['appt'] ) {
					$missing[] = $this->responder->say( 'appt_need_when' );
				}
				$reply             = $this->appointment_reply( 'test_drive' === $state['appt_kind'] ? 'appt_start_drive' : 'appt_start', array_merge( $vars, array( 'missing' => $this->responder->join_list( $missing, 'and' ) ) ), $keep );
				$reply['followup'] = $this->responder->say( 'appt_consent', $vars );
				$reply['contact']  = $this->contact_block( 'appt_contact_title', $vars );
				return $reply;
			}
			$key = empty( $customer['email'] ) ? 'appt_ask_email' : ( empty( $customer['name'] ) ? 'appt_ask_name' : 'appt_ask_when' );
			return $this->appointment_reply( $key, $vars, $keep );
		}
		$ids    = isset( $state['pending']['ids'] ) ? (array) $state['pending']['ids'] : array();
		// Con el resumen de la conversación (lo arma el servicio con el registro privado del chat).
		$result = $service ? $service->submit( $customer, (string) $state['appt'], $ids ? (int) $ids[0] : 0, array( 'cid' => (string) $state['cid'], 'kind' => (string) $state['appt_kind'] ) ) : null;
		$clear  = array( 'pending' => array(), 'appt' => '', 'appt_kind' => '', 'customer' => $customer );
		if ( ! is_array( $result ) ) {
			$code             = is_object( $result ) && method_exists( $result, 'get_error_code' ) ? $result->get_error_code() : '';
			$reply            = $this->appointment_reply( 'asi_limit' === $code ? 'action_limit' : 'appt_failed', $vars, $clear );
			$reply['contact'] = $this->contact_block( 'appt_contact_title', $vars );
			return $reply;
		}
		$reply = $this->appointment_reply( 'appt_done', $vars, $clear );
		if ( ! empty( $result['test'] ) ) {
			$reply['text'] .= ' ' . $this->responder->say( 'appt_test_note' );
		}
		$reply['followup'] = $this->responder->say( 'appt_after' );
		return $reply;
	}

	/**
	 * Datos del mensaje: nombre, correo, teléfono y cuándo le gustaría la cita.
	 * Lo de "cuándo" se separa primero para que "el sábado" no se lea como nombre.
	 *
	 * @return array { array $customer, string $when }
	 */
	private function appointment_data( $message, $normalized, $waiting, array $state ) {
		$parts = preg_split( '/[,;\n]+/u', (string) $message );
		$when  = array();
		$rest  = array();
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			$clean = $this->when_text( $part );
			if ( '' !== $clean ) {
				$when[] = $clean;
			} else {
				$rest[] = $part;
			}
		}
		$given = array_filter( $this->parse_customer( implode( ', ', $rest ), $waiting && $rest ) );
		$when  = implode( ', ', $when );
		// Ya tiene nombre y correo y el chat le preguntó cuándo: lo que escriba es la respuesta
		// ("cuando puedan", "lo antes posible").
		if ( '' === $when && $waiting && ! $given && ! empty( $state['customer']['name'] ) && ! empty( $state['customer']['email'] ) && '' === (string) $state['appt'] && str_word_count( $normalized ) <= 12 && ! $this->words_in( $normalized, 'not_data_words' ) ) {
			$when = trim( (string) $message );
		}
		return array( $given, $this->short( $when, 80 ) );
	}

	/**
	 * La parte del texto que dice cuándo ("el sábado en la tarde", "mañana a las 10"); '' si no dice.
	 */
	private function when_text( $text ) {
		$normalized = ASI_Normalizer::normalize( $text );
		$hit        = ASI_Lexicon::find_phrase( $normalized, (array) $this->lexicon->get_list( 'when_words' ) );
		$clock      = preg_match( '/(^|\s)[0-9]{1,2}(:[0-9]{2})?\s*(am|pm|hrs|h)(\s|$)|(^|\s)[0-9]{1,2}\/[0-9]{1,2}(\s|$)|a las [0-9]/', $normalized );
		if ( '' === $hit && ! $clock ) {
			return '';
		}
		// "Quiero agendar una cita para el sábado": solo lo que viene después de la cita.
		$out = trim( (string) $text );
		foreach ( (array) $this->lexicon->get_list( 'appointment_words' ) as $word ) {
			$pattern = '/^.*?(?<![\p{L}])' . $this->loose( $word ) . '(?![\p{L}])/iu';
			if ( preg_match( $pattern, $out ) ) {
				$out = preg_replace( $pattern, '', $out, 1 );
				break;
			}
		}
		$out = preg_replace( '/^\s*((para|el|la|los|las|on|for)\s+)+/iu', '', trim( $out ) );
		return trim( $out, " \t.!¿?" );
	}

	/**
	 * El día de la cita, concreto. label '' si ya pasó o no dice qué día ("en la tarde").
	 * "Lo antes posible" / "cuando puedan" se acepta tal cual: el asesor propone.
	 *
	 * @return array { label: string, past: bool, past_label: string }
	 */
	private function when_check( $when ) {
		$cal = (array) $this->lexicon->get_list( 'when_calendar' );
		if ( ! $cal ) {
			return array( 'label' => (string) $when, 'past' => false, 'past_label' => '' );
		}
		$r = ASI_When::resolve( $when, $this->now(), $cal );
		if ( $r['past'] ) {
			return array( 'label' => '', 'past' => true, 'past_label' => $r['label'] );
		}
		if ( '' === $r['date'] ) {
			return array( 'label' => $r['asap'] ? $this->short( (string) $when, 80 ) : '', 'past' => false, 'past_label' => '' );
		}
		return array( 'label' => $r['label'], 'past' => false, 'past_label' => '' );
	}

	/** Pide otro día (ya pasó o no dijo cuál), con los siguientes días como botones. */
	private function when_retry( array $check, array $state ) {
		$keep  = array( 'pending' => $state['pending'], 'customer' => $state['customer'], 'appt' => '', 'appt_kind' => (string) $state['appt_kind'] );
		$reply = $this->appointment_reply( $check['past'] ? 'appt_when_past' : 'appt_when_day', array( 'when' => $check['past_label'] ), $keep );
		$reply['suggestions'] = ASI_When::next_days( $this->now(), (array) $this->lexicon->get_list( 'when_calendar' ) );
		return $reply;
	}

	/** Ahora, en la hora del sitio. */
	private function now() {
		return isset( $this->context['now'] ) && $this->context['now'] ? (int) $this->context['now'] : time();
	}

	/** Patrón que acepta la palabra del léxico con o sin tildes ("sabado" encuentra "sábado"). */
	private function loose( $word ) {
		$map = array( 'a' => '[aáàä]', 'e' => '[eéèë]', 'i' => '[iíìï]', 'o' => '[oóòö]', 'u' => '[uúùü]', 'n' => '[nñ]', ' ' => '\s+' );
		$out = '';
		foreach ( preg_split( '//u', (string) $word, -1, PREG_SPLIT_NO_EMPTY ) as $char ) {
			$out .= isset( $map[ $char ] ) ? $map[ $char ] : preg_quote( $char, '/' );
		}
		return $out;
	}

	/**
	 * Cómo se llama lo que pide: "la cita" o "la prueba de manejo" (y del vehículo que estaba viendo).
	 */
	private function appointment_words_for( array $state ) {
		$drive = 'test_drive' === ( isset( $state['appt_kind'] ) ? $state['appt_kind'] : '' );
		$focus = ! empty( $state['focus'] ) ? $this->focus_row( $state ) : null;
		return array(
			'thing'   => $this->responder->say( $drive ? 'appt_thing_drive' : 'appt_thing' ),
			'things'  => $this->responder->say( $drive ? 'appt_things_drive' : 'appt_things' ),
			'vehicle' => $focus && $drive ? ' ' . $this->responder->say( 'appt_of_vehicle', array( 'title' => $focus['card']['title'] ) ) : '',
			'site'    => $this->context['site_name'],
		);
	}

	private function appointment_reply( $key, array $vars, array $state_changes ) {
		return array(
			'kind'        => 'smalltalk',
			'text'        => $this->responder->say( $key, $vars ),
			'cards'       => array(),
			'followup'    => '',
			'links'       => array(),
			'suggestions' => array(),
			'more'        => false,
			'state'       => $state_changes,
		);
	}
}
