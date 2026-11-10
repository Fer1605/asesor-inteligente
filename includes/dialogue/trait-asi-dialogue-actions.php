<?php
/**
 * Cerrar la venta en el chat: cuotas, cotización y comparación en PDF, con los datos del cliente
 * pedidos en la conversación.
 *
 * Estado: pending (acción esperando datos), customer (nombre, correo, teléfono ya dados),
 * offer (lo último que ofreció el chat, para que "sí" lo acepte).
 *
 * @package AsesorInteligente
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'ASI_CLI_TESTS' ) ) {
	exit;
}

trait ASI_Dialogue_Actions {

	private function runner() {
		return isset( $this->context['actions'] ) && $this->context['actions'] instanceof ASI_Action_Runner ? $this->context['actions'] : null;
	}

	private function words_in( $normalized, $list ) {
		return '' !== ASI_Lexicon::find_phrase( $normalized, (array) $this->lexicon->get_list( $list ) );
	}

	/**
	 * ¿El mensaje es para cerrar la venta? Si sí, responde; si no, null.
	 */
	private function action_flow( $message, $normalized, array $state ) {
		$runner = $this->runner();
		if ( ! $runner ) {
			return null;
		}
		$available = $runner->available();

		// 1. Esperando los datos del cliente.
		if ( ! empty( $state['pending']['action'] ) ) {
			if ( $this->words_in( $normalized, 'cancel_words' ) && str_word_count( $normalized ) <= 4 ) {
				return $this->action_reply( 'action_cancelled', array(), $state, array( 'pending' => array(), 'offer' => array() ) );
			}
			$given = array_filter( $this->parse_customer( $message, true ) );
			// No dio sus datos sino que pidió otra cosa ("quiero ver más opciones", "sugiéreme
			// vehículos"): se deja la cotización y se atiende lo que pidió.
			if ( ! $given || $this->words_in( $normalized, 'not_data_words' ) ) {
				$state['pending'] = array();
				$state['offer']   = array();
				$reply            = $this->route( $message, $normalized, $state );
				$reply['state']   = array_merge( isset( $reply['state'] ) ? $reply['state'] : array(), array( 'pending' => array() ) );
				return $reply;
			}
			$customer = array_merge( $state['customer'], $given );
			return $this->continue_action( $state['pending']['action'], $state['pending']['ids'], $customer, $state );
		}

		// 2. "Sí" a lo último que ofreció el chat.
		if ( ! empty( $state['offer']['action'] ) ) {
			// "El tercero", "la Frontier y la MG RX9" responden a "¿cuál te cotizo?"; después de otras
			// ofertas sigue el flujo normal.
			// Solo en respuestas cortas: un mensaje largo es otra cosa (aclarar, contar su situación).
			if ( 'quote' === $state['offer']['action'] && count( $state['offer']['ids'] ) > 1 && ( str_word_count( $normalized ) <= 8 || $this->words_in( $normalized, 'quote_words' ) ) && ! $this->words_in( $normalized, 'not_data_words' ) ) {
				$picked = $this->action_targets( $message, $normalized, $state );
				if ( $picked ) {
					return $this->start_action( 'quote', $picked, $state );
				}
			}
			if ( $this->words_in( $normalized, 'yes_words' ) && str_word_count( $normalized ) <= 5 ) {
				if ( 'finance' === $state['offer']['action'] ) {
					$finance = $this->finance_flow( (int) $state['offer']['ids'][0], $message, $normalized, $state );
					if ( $finance ) {
						return $finance;
					}
				}
				if ( 'compare_text' === $state['offer']['action'] ) {
					return $this->compare_flow( 'comparalas', array_merge( $state, array( 'results' => $state['offer']['ids'] ) ) );
				}
				return $this->start_action( $state['offer']['action'], $state['offer']['ids'], $state );
			}
		}

		// 3. Cuotas.
		if ( $this->words_in( $normalized, 'finance_words' ) ) {
			$target = $this->action_target( $normalized, $state );
			if ( $target ) {
				$finance = $this->finance_flow( $target, $message, $normalized, $state );
				if ( $finance ) {
					return $finance;
				}
			}
		}

		// 4. Comparación en PDF.
		if ( isset( $available['compare'] ) && $this->words_in( $normalized, 'compare_pdf_words' ) && count( $state['results'] ) >= 2 ) {
			$max = isset( $available['compare']['max_items'] ) ? (int) $available['compare']['max_items'] : 4;
			return $this->start_action( 'compare', array_slice( $state['results'], 0, $max ), $state );
		}

		// 5. Cotización.
		if ( isset( $available['quote'] ) && $this->words_in( $normalized, 'quote_words' ) ) {
			$max     = isset( $available['quote']['max_items'] ) ? (int) $available['quote']['max_items'] : 1;
			$targets = array_slice( $this->action_targets( $message, $normalized, $state ), 0, max( 1, $max ) );
			if ( $targets ) {
				return $this->start_action( 'quote', $targets, $state );
			}
			if ( count( $state['results'] ) > 1 ) {
				return $this->ask_which( 'quote', $state );
			}
		}
		return null;
	}

	/**
	 * El producto del que habla: "el segundo", el que está viendo, el que nombra, o el único.
	 */
	private function action_target( $normalized, array $state ) {
		$ref = $this->detect_reference( $normalized, $state );
		if ( $ref ) {
			return (int) $ref;
		}
		// "¿Cuánto pagaría al mes por el segundo?": un ordinal escrito en una frase larga.
		$ordinals = (array) $this->lexicon->get_list( 'ordinals' );
		foreach ( explode( ' ', $normalized ) as $token ) {
			if ( isset( $ordinals[ $token ] ) && ! ctype_digit( $token ) && $state['results'] ) {
				$index = (int) $ordinals[ $token ];
				$index = -1 === $index ? count( $state['results'] ) - 1 : $index - 1;
				if ( isset( $state['results'][ $index ] ) ) {
					return (int) $state['results'][ $index ];
				}
			}
		}
		// Sin las palabras de la acción, ¿nombra un producto? ("cotízame la hilux srv").
		$rest = $normalized;
		foreach ( array( 'quote_words', 'finance_words', 'yes_words' ) as $list ) {
			foreach ( (array) $this->lexicon->get_list( $list ) as $phrase ) {
				$rest = trim( preg_replace( '/(^|\s)' . preg_quote( ASI_Normalizer::normalize( $phrase ), '/' ) . '(\s|$)/', ' ', ' ' . $rest . ' ' ) );
			}
		}
		$rest = trim( preg_replace( '/\b(el|la|lo|los|las|de|del|un|una|me|mi|por|favor|para|que|cuanto|a|al|en|y|con)\b/', ' ', $rest ) );
		if ( preg_match( '/[a-z]{3,}/', $rest ) ) {
			$named = $this->resolve_product( $rest, $state );
			if ( $named ) {
				return (int) $named['post_id'];
			}
		}
		$focus = $this->focus_row( $state );
		if ( $focus ) {
			return (int) $focus['post_id'];
		}
		return 1 === count( $state['results'] ) ? (int) $state['results'][0] : 0;
	}

	/**
	 * Uno o varios productos de un mensaje: por nombre ("la Frontier y la MG RX9"), por número
	 * ("el primero y el tercero") o en grupo ("las dos", "estos 3", "todas").
	 *
	 * @return int[]
	 */
	private function action_targets( $message, $normalized, array $state ) {
		// 1. Por nombre: cada parte separada por "y", coma, "con"…
		$named = array();
		$rest  = ' ' . $normalized . ' ';
		foreach ( array( 'quote_words', 'finance_words', 'yes_words', 'target_fillers' ) as $list ) {
			foreach ( (array) $this->lexicon->get_list( $list ) as $phrase ) {
				$rest = preg_replace( '/\s' . preg_quote( ASI_Normalizer::normalize( $phrase ), '/' ) . '(?=\s)/', ' ', $rest );
			}
		}
		foreach ( preg_split( '/\s(?:y|e|con|mas|and|o)\s|,|\//', $rest ) as $chunk ) {
			$id = $this->product_by_name( trim( $chunk ), $state );
			if ( $id && ! in_array( $id, $named, true ) ) {
				$named[] = $id;
			}
		}
		if ( count( $named ) >= 2 ) {
			return $named;
		}

		// 2. Por número: todos los ordinales del mensaje, en orden.
		$ordinals = (array) $this->lexicon->get_list( 'ordinals' );
		$markers  = (array) $this->lexicon->get_list( 'reference_markers' );
		$tokens   = explode( ' ', $normalized );
		$by_order = array();
		foreach ( $tokens as $position => $token ) {
			if ( ! isset( $ordinals[ $token ] ) || ! $state['results'] ) {
				continue;
			}
			$previous = $position > 0 ? $tokens[ $position - 1 ] : '';
			if ( ctype_digit( $token ) && ! in_array( $previous, $markers, true ) && ! in_array( $previous, array( 'y', 'e', 'el', 'la' ), true ) ) {
				continue;
			}
			$index = (int) $ordinals[ $token ];
			$index = -1 === $index ? count( $state['results'] ) - 1 : $index - 1;
			if ( isset( $state['results'][ $index ] ) && ! in_array( (int) $state['results'][ $index ], $by_order, true ) ) {
				$by_order[] = (int) $state['results'][ $index ];
			}
		}
		if ( $by_order ) {
			return $by_order;
		}

		// 3. En grupo: "las dos", "ambas", "estos 3", "todas".
		if ( $state['results'] && preg_match( '/(?:^|\s)(?:(?:los|las|estos|estas|esos|esas|esas)\s+)?(ambos|ambas|todos|todas|dos|2|tres|3|cuatro|4)(?:\s+(?:primeros|primeras|opciones|carros|vehiculos))?(?:\s|$)/', $normalized, $m ) && preg_match( '/(ambos|ambas|todos|todas|los|las|estos|estas|esos|esas)/', $m[0] ) ) {
			$counts = array( 'ambos' => 2, 'ambas' => 2, 'dos' => 2, '2' => 2, 'tres' => 3, '3' => 3, 'cuatro' => 4, '4' => 4, 'todos' => 8, 'todas' => 8 );
			return array_map( 'intval', array_slice( $state['results'], 0, $counts[ $m[1] ] ) );
		}

		if ( $named ) {
			return $named;
		}
		$one = $this->action_target( $normalized, $state );
		return $one ? array( $one ) : array();
	}

	/**
	 * Un producto por su nombre, primero entre los que ya se mostraron en la conversación
	 * ("la suv mg rx9" → MG RX9 ELEGANCE), luego en el catálogo.
	 */
	private function product_by_name( $chunk, array $state ) {
		$words = array();
		foreach ( explode( ' ', ASI_Lexicon::normal_form( $chunk ) ) as $word ) {
			if ( strlen( $word ) >= 2 && ! $this->lexicon->is_stopword( $word ) ) {
				$words[] = $word;
			}
		}
		if ( ! $words ) {
			return 0;
		}
		$recent = array_reverse( array_slice( array_values( array_unique( array_merge( $state['shown'], $state['results'] ) ) ), -40 ) );
		if ( $recent ) {
			$best  = 0;
			$score = 0;
			foreach ( $this->repository->rows( $this->context['lang'], $recent ) as $row ) {
				$title = ' ' . $row['title_norm'] . ' ';
				$hits  = 0;
				foreach ( $words as $word ) {
					$hits += false !== strpos( $title, ' ' . $word . ' ' ) ? 1 : 0;
				}
				// La mayoría de las palabras y al menos una que no sea un número.
				$named = (bool) array_filter(
					$words,
					static function ( $w ) use ( $title ) {
						return ! ctype_digit( $w ) && strlen( $w ) >= 3 && false !== strpos( $title, ' ' . $w . ' ' );
					}
				);
				$ratio = $hits / count( $words );
				// Los más recientes ganan en empate (la lista va del más nuevo al más viejo).
				if ( $named && $ratio >= 0.6 && $ratio > $score ) {
					$best  = (int) $row['post_id'];
					$score = $ratio;
				}
			}
			if ( $best ) {
				return $best;
			}
		}
		if ( count( $words ) < 1 || ! preg_match( '/[a-z]{3,}/', implode( ' ', $words ) ) ) {
			return 0;
		}
		$row = $this->resolve_product( implode( ' ', $words ), $state );
		if ( $row ) {
			return (int) $row['post_id'];
		}
		// Varias versiones del mismo modelo ("MG RX9 Elegance" y "MG RX9 Premier"): la más relevante.
		$query  = $this->analyzer->analyze( implode( ' ', $words ) );
		$result = $query['groups'] ? $this->search->search( $this->context['lang'], $query, array( 'roles' => array( 'catalog' ), 'limit' => 4 ) ) : array( 'items' => array() );
		if ( count( $result['items'] ) >= 2 && 'exact' === $result['quality'] && self::model_key( $result['items'][0] ) === self::model_key( $result['items'][1] ) ) {
			return (int) $result['items'][0]['post_id'];
		}
		return 0;
	}

	private function ask_which( $action, array $state ) {
		$rows   = $this->repository->rows( $this->context['lang'], array_slice( $state['results'], 0, 4 ) );
		$titles = array_map(
			function ( $row ) {
				return $this->short( $row['card']['title'], 40 );
			},
			$rows
		);
		$reply = $this->action_reply( 'action_which', array(), $state, array( 'offer' => array( 'action' => $action, 'ids' => array_slice( $state['results'], 0, 4 ) ) ) );
		$reply['suggestions'] = (array) $this->lexicon->get_list( 'which_chips' );
		$reply['followup']    = $this->responder->join_list( $titles, 'or' ) . '.';
		return $reply;
	}

	/**
	 * Empieza una acción: si ya tiene los datos del cliente, la ejecuta; si no, los pide.
	 */
	private function start_action( $action, array $ids, array $state ) {
		return $this->continue_action( $action, $ids, $state['customer'], $state );
	}

	private function continue_action( $action, array $ids, array $customer, array $state ) {
		$ids     = array_values( array_filter( array_map( 'intval', $ids ) ) );
		$rows    = $this->repository->rows( $this->context['lang'], $ids );
		$titles  = array_map(
			static function ( $row ) {
				return $row['card']['title'];
			},
			$rows
		);
		$pending = array( 'action' => $action, 'ids' => $ids );
		$vars    = array(
			'title'  => $titles ? $titles[0] : '',
			'titles' => $this->responder->join_list( $titles, 'and' ),
			'name'   => isset( $customer['name'] ) ? strtok( $customer['name'], ' ' ) : '',
			'site'   => $this->context['site_name'],
		);
		if ( ! $rows ) {
			return $this->action_reply( 'action_failed', $vars, $state, array( 'pending' => array() ) );
		}
		if ( empty( $customer['name'] ) && empty( $customer['email'] ) ) {
			$ask   = 'compare' === $action ? 'action_ask_compare' : ( count( $rows ) > 1 ? 'action_ask_quote_many' : 'action_ask_quote' );
			$reply = $this->action_reply( $ask, $vars, $state, array( 'pending' => $pending, 'customer' => $customer ) );
			$reply['followup'] = $this->responder->say( 'action_consent', $vars );
			return $reply;
		}
		if ( empty( $customer['email'] ) ) {
			return $this->action_reply( 'action_ask_email', $vars, $state, array( 'pending' => $pending, 'customer' => $customer ) );
		}
		if ( empty( $customer['name'] ) ) {
			return $this->action_reply( 'action_ask_name', $vars, $state, array( 'pending' => $pending, 'customer' => $customer ) );
		}
		return $this->execute_action( $action, $ids, $customer, $rows, $vars, $state );
	}

	private function execute_action( $action, array $ids, array $customer, array $rows, array $vars, array $state ) {
		$result = $this->runner()->run( $action, $ids, $customer );
		$clear  = array( 'pending' => array(), 'offer' => array(), 'customer' => $customer );
		if ( ! is_array( $result ) || empty( $result['url'] ) ) {
			$code  = is_object( $result ) && method_exists( $result, 'get_error_code' ) ? $result->get_error_code() : '';
			$reply = $this->action_reply( 'asi_limit' === $code ? 'action_limit' : 'action_failed', $vars, $state, $clear );
			$reply['links'] = $this->links( array_slice( $rows, 0, 1 ) );
			return $reply;
		}
		$vars['email'] = $customer['email'];
		$text          = array( $this->responder->say( 'compare' === $action ? 'action_done_compare' : ( count( $rows ) > 1 ? 'action_done_quote_many' : 'action_done_quote' ), $vars ) );
		$text[]        = $this->responder->say( $result['emailed'] ? 'action_emailed' : 'action_not_emailed', $vars );
		if ( ! empty( $result['test'] ) ) {
			$text[] = $this->responder->say( 'action_test_note' );
		}
		$offer = 'quote' === $action && 1 === count( $ids ) ? array( 'action' => 'finance', 'ids' => array( $ids[0] ) ) : array();
		return array(
			'kind'        => 'file',
			'text'        => $this->sentences( $text ),
			'file'        => array(
				'url'      => (string) $result['url'],
				'name'     => (string) $result['filename'],
				'label'    => $this->responder->say( 'compare' === $action ? 'file_label_compare' : 'file_label_quote' ),
				'open'     => $this->responder->say( 'file_open' ),
				'download' => $this->responder->say( 'file_download' ),
			),
			'cards'       => array(),
			'followup'    => $this->responder->say( 'compare' === $action ? 'action_after_compare' : 'action_after_quote' ),
			'links'       => array(),
			'suggestions' => 'quote' === $action ? (array) $this->lexicon->get_list( 'after_quote_chips' ) : array(),
			'more'        => false,
			'state'       => array_merge( $clear, array( 'focus' => $ids[0], 'offer' => $offer ) ),
		);
	}

	/**
	 * Cuotas con las reglas del sitio (o las del panel).
	 */
	private function finance_flow( $id, $message, $normalized, array $state ) {
		$rules = $this->runner()->financing( $id );
		$rows  = $this->repository->rows( $this->context['lang'], array( $id ) );
		if ( ! $rules || ! $rows ) {
			return null;
		}
		$card  = (array) $rows[0]['card'];
		$money = ASI_Money::config( (array) $this->context['money'] );
		$price = ASI_Money::card_price( $card, $money );
		if ( ! $price['amount'] ) {
			return null;
		}
		$amount   = (float) $price['amount'];
		$currency = '' !== $price['currency'] ? $price['currency'] : $money['base'];
		$fmt      = static function ( $value ) use ( $currency ) {
			return ASI_Money::format( round( $value ), $currency );
		};

		// Enganche: "con 50 mil de enganche", "enganche de Q40,000", "30 %".
		$pct  = (float) $rules['down_default'];
		$note = '';
		$text = ASI_Money::fold( $message );
		if ( preg_match( '/([0-9]{1,2}(?:[.,][0-9])?)\s*%/', $text, $m ) ) {
			$pct = (float) str_replace( ',', '.', $m[1] ) / 100;
		} elseif ( preg_match( '/(?:enganche|prima|inicial|down payment)\D{0,12}?((?:q|\$)?\s*[0-9][0-9.,]*\s*(?:mil|k)?)|((?:q|\$)?\s*[0-9][0-9.,]*\s*(?:mil|k)?)\s*(?:de\s+)?(?:enganche|prima|inicial)/', $text, $m ) ) {
			$raw    = '' !== trim( $m[1] ) ? $m[1] : $m[2];
			$value  = (float) ASI_Money::parse_display_price( $raw ) * ( preg_match( '/(mil|k)\s*$/', trim( $raw ) ) ? 1000 : 1 );
			$pct    = $value > 0 ? $value / $amount : $pct;
		}
		if ( $pct < $rules['down_min'] || $pct > $rules['down_max'] ) {
			$asked = $pct;
			$pct   = max( (float) $rules['down_min'], min( (float) $rules['down_max'], $pct ) );
			$note  = $this->responder->say( $asked < $rules['down_min'] ? 'finance_down_min' : 'finance_down_max', array( 'pct' => round( $pct * 100 ) . ' %', 'down' => $fmt( $amount * $pct ) ) );
		}
		$down      = $amount * $pct;
		$principal = $amount - $down;
		$monthly   = (float) $rules['rate'] / 12;
		$terms     = (array) $rules['terms'];
		if ( preg_match( '/([0-9]{2})\s*(?:meses|months)/', $text, $m ) ) {
			$terms = array( (int) $m[1] );
		} elseif ( preg_match( '/([1-9])\s*(?:anos|ano|years?)/', $text, $m ) ) {
			$terms = array( 12 * (int) $m[1] );
		}
		$table = array();
		foreach ( $terms as $months ) {
			$months  = max( 1, (int) $months );
			$payment = $monthly > 0 ? ( $monthly * $principal ) / ( 1 - pow( 1 + $monthly, -$months ) ) : $principal / $months;
			$table[] = array( $this->responder->say( 'finance_term', array( 'n' => $months ) ), $fmt( $payment ) );
		}
		$vars = array(
			'title' => $card['title'],
			'price' => $fmt( $amount ),
			'down'  => $fmt( $down ),
			'pct'   => round( $pct * 100 ) . ' %',
			'rate'  => rtrim( rtrim( number_format( $rules['rate'] * 100, 2 ), '0' ), '.' ) . ' %',
		);
		$intro = array( $this->responder->say( 1 === count( $table ) ? 'finance_single' : 'finance_table', $vars + array( 'term' => $table[0][0], 'payment' => $table[0][1] ) ) );
		if ( $note ) {
			$intro[] = $note;
		}
		$available = $this->runner()->available();
		return array(
			'kind'        => 'finance',
			'text'        => $this->sentences( $intro ),
			'table'       => 1 === count( $table ) ? array() : array( 'head' => array( $this->responder->say( 'finance_head_term' ), $this->responder->say( 'finance_head_payment' ) ), 'rows' => $table ),
			'cards'       => $this->cards( $rows ),
			'followup'    => $this->responder->say( isset( $available['quote'] ) ? 'finance_offer_quote' : 'finance_disclaimer', $vars ),
			'links'       => array(),
			'suggestions' => isset( $available['quote'] ) ? (array) $this->lexicon->get_list( 'finance_chips' ) : array(),
			'more'        => false,
			// Se conservan los resultados: después se puede seguir con "compara el primero y el segundo".
			'state'       => array(
				'focus' => (int) $id,
				'offer' => isset( $available['quote'] ) ? array( 'action' => 'quote', 'ids' => array( (int) $id ) ) : array(),
			),
		);
	}

	/**
	 * Nombre, correo y teléfono de un mensaje ("soy Ana López, ana@x.com, 5555 1234").
	 *
	 * @param string $message      Mensaje.
	 * @param bool   $waiting_data El chat acaba de pedir los datos (un nombre suelto cuenta).
	 * @return array{name:string,email:string,phone:string}
	 */
	private function parse_customer( $message, $waiting_data ) {
		$out  = array( 'name' => '', 'email' => '', 'phone' => '' );
		$text = (string) $message;
		if ( preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $m ) ) {
			$out['email'] = strtolower( $m[0] );
			$text         = str_replace( $m[0], ' ', $text );
		}
		if ( preg_match( '/\+?[0-9][0-9 \-]{6,16}[0-9]/', $text, $m ) && strlen( preg_replace( '/[^0-9]/', '', $m[0] ) ) >= 8 ) {
			$out['phone'] = trim( $m[0] );
			$text         = str_replace( $m[0], ' ', $text );
		}
		$letters = '[A-Za-zÁÉÍÓÚÜÑáéíóúüñ]+';
		if ( preg_match( '/(?:me llamo|mi nombre es|soy|a nombre de|my name is|i am|i\'m)\s+(' . $letters . '(?:\s+' . $letters . '){0,3})/iu', $text, $m ) ) {
			$out['name'] = $m[1];
		} elseif ( $waiting_data ) {
			$rest  = preg_replace( '/\b(mi|mis|correo|email|e-mail|mail|es|son|telefono|teléfono|tel|cel|celular|numero|número|whatsapp|nombre|y|datos|aqui|aquí|van|estan|están|los|el|la|phone|and|is|name)\b/iu', ' ', $text );
			$rest  = trim( preg_replace( '/[^A-Za-zÁÉÍÓÚÜÑáéíóúüñ ]+/u', ' ', $rest ) );
			$words = array_values( array_filter( explode( ' ', $rest ), 'strlen' ) );
			if ( $words && count( $words ) <= 4 && false === strpos( $text, '?' ) && $this->looks_like_name( $words ) ) {
				$out['name'] = implode( ' ', $words );
			}
		}
		if ( '' !== $out['name'] ) {
			$out['name'] = function_exists( 'mb_convert_case' ) ? mb_convert_case( trim( $out['name'] ), MB_CASE_TITLE, 'UTF-8' ) : ucwords( trim( $out['name'] ) );
		}
		return $out;
	}

	/**
	 * ¿Estas palabras son un nombre? No si alguna es una palabra común del idioma, del sitio
	 * ("vehículos", "SUV") o de pedido ("sugiéreme", "quiero").
	 */
	private function looks_like_name( array $words ) {
		$not = array_map( array( 'ASI_Normalizer', 'normalize' ), array_merge( (array) $this->lexicon->get_list( 'yes_words' ), (array) $this->lexicon->get_list( 'not_name_words' ) ) );
		foreach ( $words as $word ) {
			$normal = ASI_Normalizer::normalize( $word );
			if ( strlen( $normal ) < 2 || $this->lexicon->is_stopword( $normal ) || in_array( $normal, $not, true ) || isset( $this->vocab['tokens'][ ASI_Lexicon::normal_form( $normal ) ] ) ) {
				return false;
			}
			foreach ( array( 'sugier', 'suguier', 'suger', 'recomiend', 'muestr', 'ensen', 'busc', 'quier', 'necesit', 'opcion', 'vehicul', 'carro', 'favor' ) as $stem ) {
				if ( 0 === strpos( $normal, $stem ) ) {
					return false;
				}
			}
		}
		return true;
	}

	private function action_reply( $key, array $vars, array $state, array $state_changes ) {
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
