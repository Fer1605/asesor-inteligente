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
				return $this->appointment_reply( 'appt_cancelled', array(), array( 'pending' => array(), 'appt' => '' ) );
			}
			list( $given, $when ) = $this->appointment_data( $message, $normalized, true, $state );
			// No dio datos sino que pidió otra cosa ("mejor muéstrame pickups"): se deja la cita.
			if ( ! $given && '' === $when ) {
				$state['pending'] = array();
				$state['appt']    = '';
				$reply            = $this->route( $message, $normalized, $state );
				$reply['state']   = array_merge( isset( $reply['state'] ) ? $reply['state'] : array(), array( 'pending' => array(), 'appt' => '' ) );
				return $reply;
			}
			$state['customer'] = array_merge( $state['customer'], $given );
			if ( '' !== $when ) {
				$state['appt'] = $when;
			}
			return $this->appointment_next( $state, $service );
		}

		if ( ! $this->words_in( $normalized, 'appointment_words' ) || str_word_count( $normalized ) > 25 ) {
			return null;
		}
		// El sitio no recibe solicitudes: los datos de contacto para coordinarla.
		if ( ! $service || ! $service->enabled() ) {
			$reply            = $this->appointment_reply( 'appt_off', array( 'site' => $this->context['site_name'] ), array() );
			$reply['contact'] = $this->contact_block( 'appt_contact_title' );
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
		$state['appt']     = $when;
		$state['pending']  = array( 'action' => 'appointment', 'ids' => $state['focus'] ? array( (int) $state['focus'] ) : array() );
		$next              = $this->appointment_next( $state, $service, true );
		return $next;
	}

	/**
	 * Pide lo que falta (correo, nombre, cuándo) o envía la solicitud.
	 */
	private function appointment_next( array $state, $service, $first = false ) {
		$customer = $state['customer'];
		$vars     = array(
			'name'  => ! empty( $customer['name'] ) ? strtok( $customer['name'], ' ' ) : '',
			'email' => isset( $customer['email'] ) ? $customer['email'] : '',
			'when'  => (string) $state['appt'],
			'site'  => $this->context['site_name'],
		);
		$keep = array( 'pending' => $state['pending'], 'customer' => $customer, 'appt' => (string) $state['appt'] );
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
				$reply             = $this->appointment_reply( 'appt_start', array_merge( $vars, array( 'missing' => $this->responder->join_list( $missing, 'and' ) ) ), $keep );
				$reply['followup'] = $this->responder->say( 'appt_consent', $vars );
				$reply['contact']  = $this->contact_block( 'appt_contact_title' );
				return $reply;
			}
			$key = empty( $customer['email'] ) ? 'appt_ask_email' : ( empty( $customer['name'] ) ? 'appt_ask_name' : 'appt_ask_when' );
			return $this->appointment_reply( $key, $vars, $keep );
		}
		$ids    = isset( $state['pending']['ids'] ) ? (array) $state['pending']['ids'] : array();
		$result = $service ? $service->submit( $customer, (string) $state['appt'], $ids ? (int) $ids[0] : 0 ) : null;
		$clear  = array( 'pending' => array(), 'appt' => '', 'customer' => $customer );
		if ( ! is_array( $result ) ) {
			$code             = is_object( $result ) && method_exists( $result, 'get_error_code' ) ? $result->get_error_code() : '';
			$reply            = $this->appointment_reply( 'asi_limit' === $code ? 'action_limit' : 'appt_failed', $vars, $clear );
			$reply['contact'] = $this->contact_block( 'appt_contact_title' );
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

	/** Patrón que acepta la palabra del léxico con o sin tildes ("sabado" encuentra "sábado"). */
	private function loose( $word ) {
		$map = array( 'a' => '[aáàä]', 'e' => '[eéèë]', 'i' => '[iíìï]', 'o' => '[oóòö]', 'u' => '[uúùü]', 'n' => '[nñ]', ' ' => '\s+' );
		$out = '';
		foreach ( preg_split( '//u', (string) $word, -1, PREG_SPLIT_NO_EMPTY ) as $char ) {
			$out .= isset( $map[ $char ] ) ? $map[ $char ] : preg_quote( $char, '/' );
		}
		return $out;
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
