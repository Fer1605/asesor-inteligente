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
		// Cualquier forma de "cotizar" (cotizarme, cotízamelas, cotizaciones…).
		if ( 'quote_words' === $list && preg_match( '/(^|\s)(cotiz|quot)[a-z]*/', $normalized ) ) {
			return true;
		}
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
				$state['was_pending'] = (array) $state['pending']['ids']; // Solo para esta respuesta.
				$state['pending']     = array();
				$state['offer']   = array();
				$reply            = $this->route( $message, $normalized, $state );
				$reply['state']   = array_merge( isset( $reply['state'] ) ? $reply['state'] : array(), array( 'pending' => array() ) );
				return $reply;
			}
			$customer = array_merge( $state['customer'], $given );
			return $this->continue_action( $state['pending']['action'], $state['pending']['ids'], $customer, $state );
		}

		// 1b. El chat preguntó "¿cuáles comparo?": esta es la respuesta ("la 1 y la 3", "la tank y la
		// d-max", "los que me acabas de cotizar").
		if ( 'compare' === $state['awaiting'] ) {
			$ids = $this->action_targets( $message, $normalized, $state, false );
			if ( count( $ids ) < 2 ) {
				foreach ( array( $state['selected'], array_slice( $state['quoted'], -2 ), $state['results'] ) as $candidates ) {
					if ( count( $candidates ) >= 2 ) {
						$ids = $candidates;
						break;
					}
				}
			}
			if ( count( $ids ) >= 2 ) {
				$state['awaiting'] = '';
				$reply             = $this->compare_flow( 'comparalos', array_merge( $state, array( 'selected' => array_slice( $ids, 0, 2 ) ) ) );
				if ( $reply ) {
					$reply['state'] = array_merge( isset( $reply['state'] ) ? $reply['state'] : array(), array( 'awaiting' => '' ) );
					return $reply;
				}
			}
		}

		// 2. "Sí" a lo último que ofreció el chat.
		if ( ! empty( $state['offer']['action'] ) ) {
			// "El tercero", "la Frontier y la MG RX9" responden a "¿cuál te cotizo?"; después de otras
			// ofertas sigue el flujo normal.
			// Solo en respuestas cortas: un mensaje largo es otra cosa (aclarar, contar su situación).
			$comparing = (bool) preg_match( '/(^|\s)compar[a-z]*(\s|$)/', $normalized );
			// "Dame las cuotas de todas" tras "¿cuál te cotizo?" pide cuotas, no la cotización.
			$financing = $this->words_in( $normalized, 'finance_words' ) && ! $this->words_in( $normalized, 'quote_words' );
			if ( ! $comparing && ! $financing && 'quote' === $state['offer']['action'] && count( $state['offer']['ids'] ) > 1 && ( str_word_count( $normalized ) <= 8 || $this->words_in( $normalized, 'quote_words' ) ) && ! $this->words_in( $normalized, 'not_data_words' ) ) {
				// Solo lo que nombra de verdad (nombre, número, grupo), nunca "el que está en foco".
				$picked = $this->action_targets( $message, $normalized, $state, false );
				if ( $picked ) {
					$state['with_finance'] = $this->words_in( $normalized, 'finance_words' );
				$state['finance_text'] = $this->finance_terms_text( $message );
					return $this->start_action( 'quote', $picked, $state );
				}
			}
			if ( ! $comparing && $this->words_in( $normalized, 'yes_words' ) && str_word_count( $normalized ) <= 5 ) {
				if ( 'finance' === $state['offer']['action'] ) {
					$finance = $this->finance_flow( $state['offer']['ids'], $message, $normalized, $state );
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

		// 3. Seguimiento de lo que está viendo: "y las cuotas de la otra", "y el del pickup??".
		$follow = $this->selection_follow_up( $message, $normalized, $state );
		if ( $follow ) {
			return $follow;
		}

		// 4. Cuotas (de uno o de varios: los que nombra o los que está viendo).
		// "A 48 meses", "y a 5 años" justo después de una tabla de cuotas: cambiar el plazo de esa tabla.
		$tweak = in_array( $state['last'], array( 'finance', 'quote' ), true ) && str_word_count( $normalized ) <= 8 && preg_match( '/(^|\s)([0-9]{2}\s*(meses|months)|[1-9]\s*(anos|ano|years?)|todos los plazos|otros plazos)(\s|$)/', $normalized );
		if ( ( $tweak || $this->words_in( $normalized, 'finance_words' ) ) && ! $this->words_in( $normalized, 'quote_words' ) ) {
			// Lo que nombra; si no nombra nada, todo lo que está viendo; si no, el de la conversación.
			$targets = $this->action_targets( $message, $normalized, $state, false );
			// "Dije cuotas" justo cuando el chat pedía los datos para cotizar: las de esos vehículos.
			if ( ! $targets && ! empty( $state['was_pending'] ) ) {
				$targets = array_map( 'intval', $state['was_pending'] );
			}
			if ( ! $targets && count( $state['selected'] ) > 1 ) {
				$targets = $state['selected'];
			}
			if ( ! $targets ) {
				$one     = $this->action_target( $normalized, $state );
				$targets = $one ? array( $one ) : array();
			}
			// "¿Cómo es el financiamiento?" con opciones a la vista: las cuotas de esas opciones.
			if ( ! $targets && count( $state['results'] ) > 1 ) {
				$targets = $state['results'];
			}
			if ( $targets ) {
				$finance = $this->finance_flow( $targets, $message, $normalized, $state );
				if ( $finance ) {
					return $finance;
				}
			}
		}

		// 5. Comparación en PDF.
		if ( isset( $available['compare'] ) && $this->words_in( $normalized, 'compare_pdf_words' ) && count( $state['results'] ) >= 2 ) {
			$max = isset( $available['compare']['max_items'] ) ? (int) $available['compare']['max_items'] : 4;
			return $this->start_action( 'compare', array_slice( $state['results'], 0, $max ), $state );
		}

		// 6. Cotización ("con cuotas": el PDF y la tabla de cuotas en la misma respuesta).
		$past = (bool) preg_match( '/(acabas de cotiz|ya me cotiz|me cotizaste|que cotice|los cotizados|las cotizadas|ya cotice|que ya me|de los que me|de las que me)/', $normalized );
		if ( ! $past && isset( $available['quote'] ) && $this->words_in( $normalized, 'quote_words' ) ) {
			$max     = isset( $available['quote']['max_items'] ) ? (int) $available['quote']['max_items'] : 1;
			$targets = array_slice( $this->action_targets( $message, $normalized, $state ), 0, max( 1, $max ) );
			if ( $targets ) {
				$state['with_finance'] = $this->words_in( $normalized, 'finance_words' );
				$state['finance_text'] = $this->finance_terms_text( $message );
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
	private function action_targets( $message, $normalized, array $state, $fallback = true ) {
		// 1. Por nombre: cada parte separada por "y", coma, "con"…
		$named = array();
		$rest  = ' ' . $normalized . ' ';
		$rest = preg_replace( '/\s(cotiz|quot)[a-z]*(?=\s)/', ' ', $rest );
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
			// Un número suelto es una tarjeta si va con "la/el/y" o si el mensaje es para cotizar
			// ("cotízame 1 y 3"), y solo hasta la cantidad de tarjetas mostradas.
			if ( ctype_digit( $token ) && ( (int) $token > count( $state['results'] ) || ( ! in_array( $previous, $markers, true ) && ! in_array( $previous, array( 'y', 'e', 'el', 'la', 'los', 'las', 'numero', 'num' ), true ) && ! $this->words_in( $normalized, 'quote_words' ) ) ) ) {
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

		// 4. Todo lo que está viendo: "de cada una", "de todas", "de estas", "de estos carros".
		if ( $state['results'] && $this->means_all_visible( $normalized ) && count( $named ) < 2 ) {
			// "De estos" justo después de cotizar o calcular dos: esos dos. "De cada una", todas.
			if ( count( $state['selected'] ) >= 2 && ! preg_match( '/(^|\s)(todas|todos|cada|all|each)(\s|$)/', $normalized ) ) {
				return array_map( 'intval', $state['selected'] );
			}
			return array_map( 'intval', $state['results'] );
		}

		if ( $named ) {
			return $named;
		}
		if ( ! $fallback ) {
			return array();
		}
		$one = $this->action_target( $normalized, $state );
		return $one ? array( $one ) : array();
	}

	/**
	 * ¿Habla de todas las opciones a la vista? "cada una", "cada uno", "de todas", "de estas".
	 */
	private function means_all_visible( $normalized ) {
		return (bool) preg_match( '/(?:^|\s)(?:cada\s+un[oa]|(?:de|a|para|con)\s+(?:todas|todos|estas|estos|esas|esos|ellas|ellos)|todas\s+(?:las|estas|esas)\s+opciones|each\s+(?:one|of\s+them)|(?:of|for)\s+(?:all|these|those|them))(?:\s|$)/', $normalized );
	}

	/**
	 * Un producto por su nombre, primero entre los que ya se mostraron en la conversación
	 * ("la suv mg rx9" → MG RX9 ELEGANCE), luego en el catálogo.
	 */
	private function product_by_name( $chunk, array $state ) {
		// Por precio o por año, entre lo ya mostrado: "la de 169 mil", "la de Q 199,990", "la 2023".
		$by_detail = $this->product_by_detail( $chunk, $state );
		if ( $by_detail ) {
			return $by_detail;
		}
		$words   = array();
		$generic = array_map( array( 'ASI_Lexicon', 'normal_form' ), (array) $this->lexicon->get_list( 'generic_product_words' ) );
		foreach ( explode( ' ', ASI_Lexicon::normal_form( $chunk ) ) as $word ) {
			if ( strlen( $word ) >= 2 && ! $this->lexicon->is_stopword( $word ) && ! in_array( $word, $generic, true ) ) {
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
				$named = false;
				foreach ( $words as $word ) {
					if ( self::word_in_title( $word, $title ) ) {
						$hits++;
						// Al menos una que no sea un número.
						$named = $named || ( ! ctype_digit( $word ) && strlen( $word ) >= 3 );
					}
				}
				$ratio = $hits / count( $words );
				// Basta con la mayoría de las palabras o con dos que coinciden ("mmm puedes … mitsubishi
				// outlander"). Los más recientes ganan en empate (la lista va del más nuevo al más viejo).
				$ratio = $hits >= 2 ? max( $ratio, 0.6 + 0.01 * $hits ) : $ratio;
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
		if ( ! empty( $state['with_finance'] ) ) {
			// El enganche y el plazo que pidió ("a un 45 % de enganche") viajan con la cotización.
			$state['pending'] = array( 'action' => $action, 'ids' => $ids, 'with' => 'finance', 'fin' => isset( $state['finance_text'] ) ? (string) $state['finance_text'] : '' );
		}
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
		$pending = array( 'action' => $action, 'ids' => $ids, 'with' => isset( $state['pending']['with'] ) ? $state['pending']['with'] : '', 'fin' => isset( $state['pending']['fin'] ) ? (string) $state['pending']['fin'] : '' );
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
		$seen          = ! empty( $state['previewed']['action'] ) && $action === $state['previewed']['action']
			&& ! array_diff( $ids, (array) $state['previewed']['ids'] ) && ! array_diff( (array) $state['previewed']['ids'], $ids );
		if ( $seen && $result['emailed'] ) {
			// El PDF ya está arriba en el chat: solo se confirma que se envió.
			$text = array( $this->responder->say( 'compare' === $action ? 'action_sent_compare' : 'action_sent_quote', $vars ) );
		} else {
			$text   = array( $this->responder->say( 'compare' === $action ? 'action_done_compare' : ( count( $rows ) > 1 ? 'action_done_quote_many' : 'action_done_quote' ), $vars ) );
			$text[] = $this->responder->say( $result['emailed'] ? 'action_emailed' : 'action_not_emailed', $vars );
			$seen   = false;
		}
		if ( ! empty( $result['test'] ) ) {
			$text[] = $this->responder->say( 'action_test_note' );
		}
		$offer  = 'quote' === $action ? array( 'action' => 'finance', 'ids' => $ids ) : array();
		$table  = array();
		$chips  = array();
		$with   = isset( $state['pending']['with'] ) && 'finance' === $state['pending']['with'];
		if ( $with ) {
			$block = $this->finance_block( $ids, isset( $state['pending']['fin'] ) ? (string) $state['pending']['fin'] : '', '' );
			if ( $block ) {
				$text[] = $block['text'];
				$table  = $block['table'];
				$chips  = $block['chips'];
				$offer  = array();
			}
		}
		return array(
			'kind'        => 'file',
			'text'        => $this->sentences( $text ),
			'file'        => $seen ? null : array(
				'url'      => (string) $result['url'],
				'name'     => (string) $result['filename'],
				'label'    => $this->responder->say( 'compare' === $action ? 'file_label_compare' : 'file_label_quote' ),
				'open'     => $this->responder->say( 'file_open' ),
				'download' => $this->responder->say( 'file_download' ),
			),
			'cards'       => array(),
			'table'       => $table,
			'followup'    => $table ? $this->responder->say( 'finance_other_down', array( 'rate' => '' ) ) : $this->responder->say( 'compare' === $action ? 'action_after_compare' : 'action_after_quote' ),
			'links'       => array(),
			'suggestions' => $table ? $chips : ( 'quote' === $action ? (array) $this->lexicon->get_list( 'after_quote_chips' ) : array() ),
			'more'        => false,
			'state'       => array_merge(
				$clear,
				array(
					'focus'    => $ids[0],
					'offer'    => $offer,
					'selected' => $ids,
					'last'     => $with ? 'finance' : $action,
					'quoted'   => array_values( array_unique( array_merge( $state['quoted'], 'quote' === $action ? $ids : array() ) ) ),
					'previewed' => array( 'action' => $action, 'ids' => $ids ),
				)
			),
		);
	}

	/**
	 * Cuotas con las reglas del sitio (o las del panel), de uno o de varios productos.
	 *
	 * @param int|int[] $ids Productos.
	 */
	private function finance_flow( $ids, $message, $normalized, array $state ) {
		$ids   = array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
		// Hasta 4 columnas: más no se leen en el chat. Las demás, si las pide.
		// (Se cuentan solo los que se pueden financiar: con precio y reglas de crédito.)
		$block = $this->finance_block( $ids, $message, $normalized );
		if ( ! $block ) {
			return null;
		}
		$left = max( 0, count( $block['ids'] ) - self::FINANCE_COLUMNS );
		if ( $left ) {
			$block = $this->finance_block( array_slice( $block['ids'], 0, self::FINANCE_COLUMNS ), $message, $normalized );
		}
		// "¿Cómo es el financiamiento?": además de las cuotas, la página del sitio.
		$links = array();
		if ( preg_match( '/(^|\s)(financ|credit)[a-z]*/', $normalized ) ) {
			$page  = $this->info_page( 'financing' );
			$links = $page ? array( $page ) : array();
		}
		$available = $this->runner()->available();
		$quoted    = ! array_diff( $block['ids'], $state['quoted'] );
		$offer     = isset( $available['quote'] ) && ! $quoted;
		return array(
			'kind'        => 'finance',
			'text'        => $block['text'],
			'table'       => $block['table'],
			'cards'       => $this->cards( $block['rows'] ),
			'followup'    => $this->sentences( array( $left ? $this->responder->say( 'finance_more_columns', array( 'n' => $left ) ) : '', $this->responder->say( 'finance_other_down', $block['vars'] ), $offer ? $this->responder->say( 'finance_offer_quote_short' ) : '' ) ),
			'links'       => $links,
			'suggestions' => array_merge( $offer ? array( $this->responder->say( 'finance_quote_chip' ) ) : array(), $block['chips'] ),
			'more'        => false,
			// Se conservan los resultados: después se puede seguir con "compara el primero y el segundo".
			'state'       => array(
				'focus'    => $block['ids'][0],
				'offer'    => $offer ? array( 'action' => 'quote', 'ids' => $block['ids'] ) : array(),
				'selected' => count( $block['ids'] ) > 1 ? $block['ids'] : ( $state['selected'] ? $state['selected'] : $block['ids'] ),
				'last'     => 'finance',
			),
		);
	}

	/**
	 * Tabla de cuotas: una columna por producto. El enganche y el plazo que pida el cliente
	 * ("con 100 mil de enganche", "30 %", "a 48 meses") se aplican a todos.
	 *
	 * @return array|null { text, table, rows, ids, vars }
	 */
	/**
	 * Solo la parte del mensaje con el enganche y el plazo ("45 %", "100 mil de enganche",
	 * "48 meses"), para recordarla mientras el chat pide los datos de la cotización. Lo calcula igual
	 * el servidor con el precio del sitio y respeta el enganche mínimo.
	 */
	private function finance_terms_text( $message ) {
		$text  = ASI_Money::fold( (string) $message );
		$parts = array();
		$patterns = array(
			'/[0-9]{1,2}(?:[.,][0-9])?\s*%/',
			'/(?:enganche|prima|inicial|down payment)\D{0,12}?(?:q|\$)?\s*[0-9][0-9.,]*\s*(?:mil|k)?|(?:q|\$)?\s*[0-9][0-9.,]*\s*(?:mil|k)?\s*(?:de\s+)?(?:enganche|prima|inicial)/',
			'/[0-9]{2}\s*(?:meses|months)|[1-9]\s*(?:anos|ano|years?)/',
		);
		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $text, $m ) ) {
				$parts[] = trim( $m[0] );
			}
		}
		return substr( preg_replace( '/[^a-z0-9 %.,$]/', '', implode( ' ', $parts ) ), 0, 60 );
	}

	private function finance_block( array $ids, $message, $normalized ) {
		$money = ASI_Money::config( (array) $this->context['money'] );
		$text  = ASI_Money::fold( (string) $message );
		$items = array();
		foreach ( $this->repository->rows( $this->context['lang'], $ids ) as $row ) {
			$rules = $this->runner()->financing( (int) $row['post_id'] );
			$price = ASI_Money::card_price( (array) $row['card'], $money );
			if ( $rules && $price['amount'] ) {
				$items[ (int) $row['post_id'] ] = array( $row, $rules, $price );
			}
		}
		// En el orden que los pidió.
		$ordered = array();
		foreach ( $ids as $id ) {
			if ( isset( $items[ $id ] ) ) {
				$ordered[] = $items[ $id ];
			}
		}
		if ( ! $ordered ) {
			return null;
		}

		$wanted_pct = null;
		$wanted_amt = null;
		if ( preg_match( '/([0-9]{1,2}(?:[.,][0-9])?)\s*%/', $text, $m ) ) {
			$wanted_pct = (float) str_replace( ',', '.', $m[1] ) / 100;
		} elseif ( preg_match( '/(?:enganche|prima|inicial|down payment)\D{0,12}?((?:q|\$)?\s*[0-9][0-9.,]*\s*(?:mil|k)?)|((?:q|\$)?\s*[0-9][0-9.,]*\s*(?:mil|k)?)\s*(?:de\s+)?(?:enganche|prima|inicial)/', $text, $m ) ) {
			$raw        = '' !== trim( $m[1] ) ? $m[1] : $m[2];
			$wanted_amt = (float) ASI_Money::parse_display_price( $raw ) * ( preg_match( '/(mil|k)\s*$/', trim( $raw ) ) ? 1000 : 1 );
		}
		$terms = null;
		if ( preg_match( '/([0-9]{2})\s*(?:meses|months)/', $text, $m ) ) {
			$terms = array( (int) $m[1] );
		} elseif ( preg_match( '/([1-9])\s*(?:anos|ano|years?)/', $text, $m ) ) {
			$terms = array( 12 * (int) $m[1] );
		}

		// Memoria: lo que dijo antes sigue valiendo ("al 45 %" vale también para el siguiente vehículo);
		// lo que dice ahora lo reemplaza.
		$remembered = array();
		if ( null === $wanted_pct && ! $wanted_amt ) {
			if ( isset( $this->fin_memory['pct'] ) ) {
				$wanted_pct   = $this->fin_memory['pct'] / 100;
				$remembered[] = 'down';
			} elseif ( isset( $this->fin_memory['amt'] ) ) {
				$wanted_amt   = (float) $this->fin_memory['amt'];
				$remembered[] = 'down';
			}
		} else {
			$this->fin_memory = array_diff_key( $this->fin_memory, array( 'pct' => 1, 'amt' => 1 ) );
			if ( null !== $wanted_pct ) {
				$this->fin_memory['pct'] = round( $wanted_pct * 100, 1 );
			} else {
				$this->fin_memory['amt'] = (int) round( $wanted_amt );
			}
		}
		if ( null === $terms && isset( $this->fin_memory['term'] ) ) {
			$terms        = array( (int) $this->fin_memory['term'] );
			$remembered[] = 'term';
		} elseif ( null !== $terms ) {
			$this->fin_memory['term'] = (int) $terms[0];
		}
		// "Todos los plazos" vuelve a mostrar la tabla completa.
		if ( preg_match( '/(todos los plazos|otros plazos|all terms|other terms)/', $text ) ) {
			$terms = null;
			unset( $this->fin_memory['term'] );
			$remembered = array_diff( $remembered, array( 'term' ) );
		}

		$columns = array();
		$notes   = array();
		$parts   = array();
		foreach ( $ordered as $item ) {
			list( $row, $rules, $price ) = $item;
			$amount   = (float) $price['amount'];
			$currency = '' !== $price['currency'] ? $price['currency'] : $money['base'];
			$pct      = null !== $wanted_pct ? $wanted_pct : ( $wanted_amt ? $wanted_amt / $amount : (float) $rules['down_default'] );
			if ( $pct < $rules['down_min'] || $pct > $rules['down_max'] ) {
				$asked = $pct;
				$pct   = max( (float) $rules['down_min'], min( (float) $rules['down_max'], $pct ) );
				$notes[] = $this->responder->say( $asked < $rules['down_min'] ? 'finance_down_min' : 'finance_down_max', array( 'pct' => round( $pct * 100 ) . ' %', 'down' => ASI_Money::format( round( $amount * $pct ), $currency ) ) );
			}
			$down      = $amount * $pct;
			$columns[] = array(
				'title'     => $row['card']['title'],
				'price'     => ASI_Money::format( $amount, $currency ),
				'down'      => ASI_Money::format( round( $down ), $currency ),
				'pct'       => (int) round( $pct * 100 ),
				'rules'     => $rules,
				'currency'  => $currency,
				'principal' => $amount - $down,
				'monthly'   => (float) $rules['rate'] / 12,
				'terms'     => null !== $terms ? $terms : (array) $rules['terms'],
				'rate'      => (float) $rules['rate'],
			);
			$parts[] = $this->responder->say( 'finance_part', array( 'title' => $row['card']['title'], 'price' => ASI_Money::format( $amount, $currency ), 'down' => ASI_Money::format( round( $down ), $currency ), 'pct' => round( $pct * 100 ) . ' %' ) );
		}

		$all_terms = array();
		foreach ( $columns as $column ) {
			$all_terms = array_merge( $all_terms, $column['terms'] );
		}
		$all_terms = array_values( array_unique( array_map( 'intval', $all_terms ) ) );
		sort( $all_terms );
		$rows = array();
		foreach ( $all_terms as $months ) {
			$line = array( $this->responder->say( 'finance_term', array( 'n' => $months ) ) );
			foreach ( $columns as $column ) {
				$r       = $column['monthly'];
				$p       = $column['principal'];
				$payment = $r > 0 ? ( $r * $p ) / ( 1 - pow( 1 + $r, -max( 1, $months ) ) ) : $p / max( 1, $months );
				$line[]  = ASI_Money::format( round( $payment ), $column['currency'] );
			}
			$rows[] = $line;
		}

		$rate  = rtrim( rtrim( number_format( $columns[0]['rate'] * 100, 2 ), '0' ), '.' ) . ' %';
		$vars  = array( 'rate' => $rate, 'title' => $columns[0]['title'] );
		$pcts  = array_unique( array_column( $columns, 'pct' ) );
		$same  = 1 === count( $pcts );
		// Precio y enganche van dentro de la tabla, arriba de las cuotas.
		$price_row = array( $this->responder->say( 'finance_row_price' ) );
		$down_row  = array( $this->responder->say( $same ? 'finance_row_down_pct' : 'finance_row_down', array( 'pct' => reset( $pcts ) . ' %' ) ) );
		foreach ( $columns as $column ) {
			$price_row[] = $column['price'];
			$down_row[]  = $same ? $column['down'] : $column['down'] . ' (' . $column['pct'] . ' %)';
		}
		$rows  = array_merge( array( $price_row, $down_row ), $rows );
		$intro = 1 === count( $columns )
			? $this->responder->say( 'finance_intro_one', array( 'title' => $columns[0]['title'] ) )
			: $this->responder->say( 'finance_intro_many' );
		// Que se note que se acordó de lo que pidió.
		if ( $remembered ) {
			$what = array();
			if ( in_array( 'down', $remembered, true ) ) {
				$what[] = $wanted_amt && null === $wanted_pct ? $this->responder->say( 'finance_memory_amount', array( 'down' => ASI_Money::format( round( $wanted_amt ), $columns[0]['currency'] ) ) ) : $this->responder->say( 'finance_memory_pct', array( 'pct' => reset( $pcts ) . ' %' ) );
			}
			if ( in_array( 'term', $remembered, true ) ) {
				$what[] = $this->responder->say( 'finance_term', array( 'n' => (int) $terms[0] ) );
			}
			$notes[] = $this->responder->say( 'finance_memory', array( 'what' => $this->responder->join_list( $what, 'and' ) ) );
		}
		$head  = array( '' );
		foreach ( $columns as $column ) {
			$head[] = 1 === count( $columns ) ? $this->responder->say( 'finance_head_payment' ) : $this->short( $column['title'], 22 );
		}
		// Otros enganches para probar (los que caben en las reglas de todos y no son el actual).
		$chips = array();
		foreach ( array( 30, 40, 50, 20 ) as $option ) {
			$fits = true;
			foreach ( $columns as $column ) {
				$fits = $fits && $option / 100 >= $column['rules']['down_min'] - 0.0001 && $option / 100 <= $column['rules']['down_max'] + 0.0001;
			}
			if ( $fits && ! in_array( $option, $pcts, true ) && count( $chips ) < 3 ) {
				$chips[] = $this->responder->say( 'finance_down_chip', array( 'pct' => $option . ' %' ) );
			}
		}
		return array(
			'text'  => $this->sentences( array_merge( array( $intro ), array_unique( $notes ) ) ),
			'chips' => $chips,
			'table' => array( 'head' => $head, 'rows' => $rows, 'emphasis' => 2 ),
			'rows'  => array_column( $ordered, 0 ),
			'ids'   => array_map( 'intval', array_column( array_column( $ordered, 0 ), 'post_id' ) ),
			'vars'  => $vars,
		);
	}

	/**
	 * Seguimiento sobre lo que está viendo (lo último cotizado, comparado o con cuotas):
	 * "y las cuotas de la otra", "y el del pickup??", "y la de la suv". Repite lo último que pidió
	 * (cuotas; o la cotización, si esa todavía no la tiene) con ese producto.
	 */
	private function selection_follow_up( $message, $normalized, array $state ) {
		if ( count( $state['selected'] ) < 2 || str_word_count( $normalized ) > 9 ) {
			return null;
		}
		$is_follow = preg_match( '/^(y|e|tambien|and)\s/', $normalized ) || preg_match( '/(^|\s)(la otra|el otro|los otros|las otras|the other)(\s|$)/', $normalized );
		if ( ! $is_follow ) {
			return null;
		}
		$picked = array();
		if ( preg_match( '/(^|\s)(la otra|el otro|los otros|las otras|the other)(\s|$)/', $normalized ) ) {
			$picked = array_values( array_diff( $state['selected'], array( (int) $state['focus'] ) ) );
		}
		if ( ! $picked ) {
			$picked = $this->pick_in_selection( $normalized, $state );
		}
		if ( ! $picked ) {
			return null;
		}
		$wants_finance = $this->words_in( $normalized, 'finance_words' );
		$wants_quote   = $this->words_in( $normalized, 'quote_words' );
		$last          = '' !== $state['last'] ? $state['last'] : 'finance';
		if ( $wants_quote || ( ! $wants_finance && 'quote' === $last && array_diff( $picked, $state['quoted'] ) ) ) {
			return $this->start_action( 'quote', $picked, $state );
		}
		return $this->finance_flow( $picked, $message, $normalized, $state );
	}

	/**
	 * Dentro de la selección, el que nombra por tipo ("el del pickup", "la suv") o por nombre.
	 *
	 * @return int[]
	 */
	private function pick_in_selection( $normalized, array $state ) {
		$rows   = $this->repository->rows( $this->context['lang'], $state['selected'] );
		$picked = array();
		foreach ( $rows as $row ) {
			$category = ' ' . ASI_Lexicon::normal_form( (string) ( isset( $row['card']['category'] ) ? $row['card']['category'] : '' ) ) . ' ';
			$body     = isset( $row['card']['attrs']['body'] ) ? (string) $row['card']['attrs']['body'] : '';
			$text     = ' ' . ASI_Lexicon::normal_form( $normalized ) . ' ';
			$by_type  = ( '' !== trim( $category ) && false !== strpos( $text, $category ) )
				|| ( '' !== $body && preg_match( '/\s(' . preg_quote( $body, '/' ) . '|' . ( 'pickup' === $body ? 'pick up|picop|palangana' : ( 'suv' === $body ? 'camioneta' : 'x^' ) ) . ')\s/', $text ) );
			$by_name  = false;
			foreach ( explode( ' ', trim( $row['title_norm'] ) ) as $word ) {
				if ( strlen( $word ) >= 3 && ! ctype_digit( $word ) && false !== strpos( $text, ' ' . $word . ' ' ) ) {
					$by_name = true;
				}
			}
			if ( $by_type || $by_name ) {
				$picked[] = (int) $row['post_id'];
			}
		}
		return count( $picked ) < count( $rows ) ? $picked : array();
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
	 * Un producto ya mostrado por su precio ("la de 169 mil") o su año ("la 2023").
	 */
	private function product_by_detail( $chunk, array $state ) {
		$recent = array_reverse( array_slice( array_values( array_unique( array_merge( $state['shown'], $state['results'] ) ) ), -40 ) );
		if ( ! $recent || ! preg_match( '/[0-9]/', $chunk ) ) {
			return 0;
		}
		$money  = ASI_Money::config( (array) $this->context['money'] );
		// "199 990" (la coma se volvió espacio al normalizar) → "199990".
		$chunk  = preg_replace( '/(\d)\s(?=\d{3}(?:\D|$))/', '$1', $chunk );
		$amount = null;
		if ( preg_match( '/([0-9][0-9.,]*)\s*(mil|k)?/', $chunk, $m ) ) {
			$amount = (float) preg_replace( '/[^0-9.]/', '', str_replace( ',', '', $m[1] ) ) * ( empty( $m[2] ) ? 1 : 1000 );
		}
		$year = preg_match( '/(?:^|\s)(19[5-9][0-9]|20[0-4][0-9])(?:\s|$)/', $chunk, $y ) ? (int) $y[1] : 0;
		foreach ( $this->repository->rows( $this->context['lang'], $recent ) as $row ) {
			$card = (array) $row['card'];
			if ( $year && ( ( isset( $card['attrs']['year'] ) && (int) $card['attrs']['year'] === $year ) || false !== strpos( ' ' . $row['title_norm'] . ' ', ' ' . $year . ' ' ) ) && ( ! $amount || $amount === (float) $year ) ) {
				return (int) $row['post_id'];
			}
			if ( $amount && $amount >= 1000 && ( ! $year || $amount !== (float) $year ) ) {
				$price = ASI_Money::card_price( $card, $money );
				if ( $price['amount'] && abs( $price['amount'] - $amount ) <= max( 1000, $price['amount'] * 0.01 ) ) {
					return (int) $row['post_id'];
				}
			}
		}
		return 0;
	}

	/**
	 * ¿La palabra está en el título? Exacta, con las letras cruzadas ("gmw" por "gwm") o con un
	 * error de dedo en palabras largas ("outlader" por "outlander").
	 */
	private static function word_in_title( $word, $title ) {
		if ( false !== strpos( $title, ' ' . $word . ' ' ) ) {
			return true;
		}
		if ( ctype_digit( $word ) || strlen( $word ) < 3 ) {
			return false;
		}
		$sorted = str_split( $word );
		sort( $sorted );
		foreach ( explode( ' ', trim( $title ) ) as $candidate ) {
			if ( strlen( $candidate ) !== strlen( $word ) && abs( strlen( $candidate ) - strlen( $word ) ) > 1 ) {
				continue;
			}
			$letters = str_split( $candidate );
			sort( $letters );
			if ( $letters === $sorted || ( strlen( $word ) >= 5 && levenshtein( $word, $candidate ) <= 1 ) ) {
				return true;
			}
		}
		return false;
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
