<?php
/**
 * Conversación sobre productos concretos: datos de la ficha, comparaciones y precios.
 *
 *  - "¿Cuánto pesa el tractor 5075E?", "¿y qué potencia tiene?", "¿trae cabina?": contesta con el
 *    dato de la ficha (campos ACF, atributos de WooCommerce, especificaciones) o con la frase de la
 *    descripción que lo dice. Si no está, lo dice con honestidad y ofrece la ficha o un asesor.
 *  - "Diferencia entre el FB20 y el FGL25T", "compara el primero con el segundo": tabla lado a lado.
 *  - "El más barato", "el montacargas más económico": ordena por el precio publicado.
 *
 * Todo sale de los datos del sitio: nada se inventa. Es un trait de ASI_Dialogue (usa su estado).
 *
 * @package AsesorInteligente
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'ASI_CLI_TESTS' ) ) {
	exit;
}

trait ASI_Dialogue_Products {

	/** Datos propios de la tarjeta (no vienen de un campo con nombre). */
	private static $card_facts = array( 'price', 'brand', 'category', 'code' );

	/**
	 * Pregunta por un dato de un producto. null si el mensaje no es eso.
	 *
	 * @param string $normalized Mensaje normalizado.
	 * @param array  $state      Estado.
	 * @return array|null
	 */
	private function fact_flow( $normalized, array $state ) {
		$fact_map  = $this->fact_word_map();
		$questions = array_flip( $this->lexicon->normal_list( 'question_words' ) );
		$yes_no    = ASI_Lexicon::find_phrase( $normalized, (array) $this->lexicon->get_list( 'yes_no_verbs' ) );
		$yes_no_words = '' !== $yes_no ? array_flip( explode( ' ', $yes_no ) ) : array();

		// Palabras de los nombres de datos del sitio ("potencia", "tracción").
		$label_words = array();
		foreach ( isset( $this->vocab['facts'] ) ? array_keys( $this->vocab['facts'] ) : array() as $label ) {
			foreach ( explode( ' ', $label ) as $word ) {
				if ( strlen( $word ) >= 4 && ! $this->lexicon->is_stopword( $word ) ) {
					$label_words[ $word ] = true;
				}
			}
		}

		$asked = array();
		$rest  = array();
		foreach ( explode( ' ', $normalized ) as $token ) {
			$single = ASI_Normalizer::singular( $token );
			if ( isset( $fact_map[ $token ] ) || isset( $fact_map[ $single ] ) || isset( $label_words[ $single ] ) ) {
				$asked[] = isset( $fact_map[ $token ] ) ? $token : $single;
				continue;
			}
			if ( '' === $token || $this->lexicon->is_stopword( $token ) || $this->lexicon->is_filler( $token ) || isset( $questions[ $token ] ) || isset( $yes_no_words[ $token ] ) ) {
				continue;
			}
			$rest[] = $token;
		}
		if ( ! $asked && ( '' === $yes_no || ! $rest ) ) {
			return null;
		}

		// ¿De qué producto? El que nombra, o el que se está conversando.
		$focus  = $this->focus_row( $state );
		$target = null;
		$extra  = array();
		if ( $rest ) {
			$target = $this->resolve_product( implode( ' ', $rest ), $state );
			if ( $target ) {
				// "¿Trae cabina el 6110J?": lo que no es el nombre del producto es lo que pregunta.
				$extra = '' !== $yes_no ? $this->words_outside( $rest, $target ) : array();
			} elseif ( $focus && ! $this->words_outside( $rest, $focus ) ) {
				$target = $focus; // "¿Cuánto pesa el tractor?" hablando del tractor 5075E.
			} elseif ( $focus && '' !== $yes_no && ! $this->names_products( implode( ' ', $rest ) ) ) {
				// "¿Trae cabina?" sobre el producto del que se habla. Pero "¿tiene tractores?" nombra
				// productos del sitio: es una búsqueda, no una pregunta sobre este.
				$target = $focus;
				$extra  = $rest;
			}
		} else {
			$target = $focus;
		}
		$asked = array_values( array_unique( array_merge( $asked, $extra ) ) );
		if ( ! $target || ! $asked ) {
			return null;
		}
		return $this->answer_fact( $target, $asked, $fact_map );
	}

	/**
	 * Responde el dato pedido de un producto.
	 */
	private function answer_fact( array $row, array $asked, array $fact_map ) {
		$card    = $row['card'];
		$id      = (int) $row['post_id'];
		$facts   = $this->facts_of( $row );
		$matched = $this->match_facts( $asked, $facts, $fact_map );
		$title   = $card['title'];

		$parts    = array();
		$links    = array();
		$quote    = array();
		$learn    = '';
		$generic  = array();
		foreach ( $matched as $fact ) {
			list( $key, $label, $value ) = $fact;
			if ( in_array( $key, self::$card_facts, true ) ) {
				$parts[] = $this->responder->say( 'fact_' . $key, array( 'title' => $title, 'value' => $value ) );
			} else {
				$generic[] = $this->format_fact( $label, $value );
			}
		}
		if ( $generic ) {
			$parts[] = $this->responder->say( 'fact', array( 'title' => $title, 'facts' => $this->responder->join_list( $generic ) ) );
		}

		$asks_price = false;
		foreach ( $asked as $word ) {
			$asks_price = $asks_price || in_array( 'price', isset( $fact_map[ $word ] ) ? $fact_map[ $word ] : array(), true );
		}
		if ( $asks_price && '' === (string) $card['price'] ) {
			$parts[] = $this->responder->say( 'fact_price_missing', array( 'title' => $title ) );
			$page    = $this->info_page( 'quote' );
			$page    = $page ? $page : $this->info_page( 'contact' );
			if ( $page ) {
				$links[] = $page;
			}
		}

		if ( ! $parts ) {
			// No es un dato con nombre: se busca en la descripción la frase que lo dice.
			$words = array();
			foreach ( $asked as $word ) {
				$words = array_merge( $words, isset( $fact_map[ $word ] ) ? $fact_map[ $word ] : array( ASI_Normalizer::singular( $word ) ) );
			}
			$details = $this->details_of( $id );
			$quote   = ASI_Extractor::best( $details['text'], array_values( array_diff( $words, self::$card_facts ) ), array(), 1 );
			if ( $quote ) {
				$parts[] = $this->responder->say( 'fact_text', array( 'title' => $title ) );
			} else {
				$parts[] = $this->responder->say( 'fact_missing', array( 'title' => $title ) );
				$contact = $this->info_page( 'contact' );
				if ( $contact ) {
					$links[] = $contact;
				}
				$learn = 'weak';
			}
		}

		return array(
			'kind'        => 'fact',
			'text'        => $this->sentences( $parts ),
			'quote'       => $quote,
			'followup'    => $links ? '' : $this->responder->say( 'fact_followup' ),
			'cards'       => $this->cards( array( $row ) ),
			'links'       => $links,
			'suggestions' => array(),
			'more'        => false,
			'learn'       => $learn,
			'state'       => array( 'focus' => $id ),
		);
	}

	/**
	 * "Diferencia entre X y Y", "compara el primero con el segundo". null si no pide comparar.
	 */
	private function compare_flow( $normalized, array $state ) {
		$marker = ASI_Lexicon::find_phrase( $normalized, (array) $this->lexicon->get_list( 'compare_markers' ) );
		$both   = ASI_Lexicon::find_phrase( $normalized, (array) $this->lexicon->get_list( 'compare_both' ) );
		// Cualquier forma de "comparar" (comparativa, comparativo, compárame, compare…).
		if ( '' === $marker && preg_match( '/(^|\s)(compar[a-z]*)(\s|$)/', $normalized, $stem ) ) {
			$marker = $stem[2];
		}
		// "Estos", "ambos", "los dos": lo que está viendo.
		$these = (bool) preg_match( '/(^|\s)(estos|estas|esos|esas|ambos|ambas|los dos|las dos|both|these|those)(\s|$)/', $normalized );
		if ( '' === $marker && '' === $both ) {
			return null;
		}
		// "La comparativa de todas / de cada una" con 3 o más a la vista: el PDF con varias.
		// "De estos" justo después de cotizar o calcular cuotas de dos habla de esos dos.
		$every = (bool) preg_match( '/(^|\s)(todas|todos|cada|all|each)(\s|$)/', $normalized );
		if ( '' !== $marker && count( $state['results'] ) >= 3 && ( $every || ( $this->means_all_visible( $normalized ) && count( $state['selected'] ) < 2 ) ) ) {
			$many = $this->compare_many( $state['results'] );
			if ( $many ) {
				return $many;
			}
		}
		$splitters = $this->lexicon->normal_list( 'compare_splitters' );

		// Lo que queda sin la frase de comparar ("vs" también separa, así que se deja).
		$text = ' ' . $normalized . ' ';
		if ( '' !== $marker && ! in_array( $marker, $splitters, true ) ) {
			$text = preg_replace( '/ ' . preg_quote( $marker, '/' ) . ' /', ' ', $text, 1 );
		}
		$tokens = array_values( array_filter( explode( ' ', trim( $text ) ), 'strlen' ) );

		$pair = null;
		foreach ( $tokens as $position => $token ) {
			if ( 0 === $position || ! in_array( $token, $splitters, true ) ) {
				continue;
			}
			$left  = $this->resolve_product( implode( ' ', array_slice( $tokens, 0, $position ) ), $state );
			$right = $left ? $this->resolve_product( implode( ' ', array_slice( $tokens, $position + 1 ) ), $state ) : null;
			if ( $left && $right && (int) $left['post_id'] !== (int) $right['post_id'] ) {
				$pair = array( $left, $right );
				break;
			}
		}
		// "Compáralo con el producto 3": el que se está viendo contra el que nombra.
		$focus = $this->focus_row( $state );
		if ( ! $pair && $focus && $tokens ) {
			$other_tokens = in_array( $tokens[0], $splitters, true ) ? array_slice( $tokens, 1 ) : $tokens;
			$other        = $other_tokens ? $this->resolve_product( implode( ' ', $other_tokens ), $state ) : null;
			if ( $other && (int) $other['post_id'] !== (int) $focus['post_id'] ) {
				$pair = array( $focus, $other );
			}
		}
		// Sin nombres: la selección (lo último cotizado, con cuotas o comparado); si no, los primeros.
		if ( ! $pair && isset( $state['selected'] ) && count( $state['selected'] ) >= 2 ) {
			$rows = $this->repository->rows( $this->context['lang'], array_slice( $state['selected'], 0, 2 ) );
			$pair = 2 === count( $rows ) ? $rows : null;
		}
		if ( ! $pair && isset( $state['quoted'] ) && count( $state['quoted'] ) >= 2 ) {
			$rows = $this->repository->rows( $this->context['lang'], array_slice( $state['quoted'], -2 ) );
			$pair = 2 === count( $rows ) ? $rows : null;
		}
		if ( ! $pair && count( $state['results'] ) >= 2 && ( '' !== $both || $these || count( $tokens ) <= 1 ) ) {
			$rows = $this->repository->rows( $this->context['lang'], array_slice( $state['results'], 0, 2 ) );
			$pair = 2 === count( $rows ) ? $rows : null;
		}
		if ( ! $pair ) {
			if ( '' === $marker ) {
				return null; // "cuál es mejor" sin nada que comparar: sigue la conversación normal.
			}
			return array(
				'kind'        => 'clarify',
				'text'        => $this->responder->say( 'compare_missing' ),
				'followup'    => '',
				'cards'       => array(),
				'links'       => array(),
				'suggestions' => array(),
				'more'        => false,
				'state'       => array( 'awaiting' => 'compare' ),
			);
		}
		return $this->answer_compare( $pair[0], $pair[1] );
	}

	/**
	 * Comparativa de varias opciones (hasta las que acepta el comparador del sitio): el PDF completo
	 * en el chat, sin pedir datos; enviarla por correo se ofrece aparte. null si el sitio no tiene
	 * comparador en PDF (entonces se comparan las dos primeras, como siempre).
	 */
	private function compare_many( array $ids ) {
		$runner = $this->runner();
		$have   = $runner ? $runner->available() : array();
		if ( ! isset( $have['compare'] ) ) {
			return null;
		}
		$max  = isset( $have['compare']['max_items'] ) ? (int) $have['compare']['max_items'] : 4;
		$rows = $this->repository->rows( $this->context['lang'], array_slice( array_map( 'intval', $ids ), 0, $max ) );
		$ids  = array_map( 'intval', array_column( $rows, 'post_id' ) );
		if ( count( $ids ) < 2 ) {
			return null;
		}
		$preview = $runner->preview( 'compare', $ids );
		if ( ! is_array( $preview ) || empty( $preview['url'] ) ) {
			return null;
		}
		$titles = array_map(
			function ( $row ) {
				return $row['card']['title'];
			},
			$rows
		);
		return array(
			'kind'        => 'compare',
			'text'        => $this->sentences( array( $this->responder->say( 'compare_many', array( 'list' => $this->responder->join_list( $titles, 'and' ) ) ), $this->responder->say( 'compare_full_pdf_many' ) ) ),
			'file'        => array(
				'url'      => (string) $preview['url'],
				'name'     => (string) $preview['filename'],
				'label'    => $this->responder->say( 'file_label_compare' ),
				'open'     => $this->responder->say( 'file_open' ),
				'download' => $this->responder->say( 'file_download' ),
			),
			'followup'    => $this->responder->say( 'compare_offer_email' ),
			'cards'       => array(),
			'links'       => array(),
			'suggestions' => array(),
			'more'        => false,
			'state'       => array(
				'focus'     => 0,
				'selected'  => $ids,
				'last'      => 'compare',
				'previewed' => array( 'action' => 'compare', 'ids' => $ids ),
				'offer'     => array( 'action' => 'compare', 'ids' => $ids ),
			),
		);
	}

	/**
	 * Tabla lado a lado de dos productos y lo que más los diferencia.
	 */
	private function answer_compare( array $a, array $b ) {
		$facts_a = $this->facts_by_key( $this->facts_of( $a ), false );
		$facts_b = $this->facts_by_key( $this->facts_of( $b ), false );
		// El precio como en las tarjetas: con su moneda ("Q 249,900", "$ 47,900").
		foreach ( array( array( &$facts_a, $a ), array( &$facts_b, $b ) ) as $pair ) {
			if ( isset( $pair[0]['price'] ) ) {
				$shown = $this->money_card( (array) $pair[1]['card'] );
				if ( '' !== (string) $shown['price'] ) {
					$pair[0]['price'][1] = $shown['price'];
				}
			}
		}

		$keys = array();
		foreach ( array( 'price', 'brand', 'category' ) as $key ) {
			if ( isset( $facts_a[ $key ] ) || isset( $facts_b[ $key ] ) ) {
				$keys[] = $key;
			}
		}
		// Primero lo que cambia entre los dos; lo que es igual va después.
		$common = array_diff( array_keys( array_intersect_key( $facts_a, $facts_b ) ), self::$card_facts );
		usort(
			$common,
			function ( $x, $y ) use ( $facts_a, $facts_b ) {
				$same_x = ASI_Normalizer::normalize( $facts_a[ $x ][1] ) === ASI_Normalizer::normalize( $facts_b[ $x ][1] );
				$same_y = ASI_Normalizer::normalize( $facts_a[ $y ][1] ) === ASI_Normalizer::normalize( $facts_b[ $y ][1] );
				return (int) $same_x - (int) $same_y;
			}
		);
		$only   = array_diff( array_keys( $facts_a + $facts_b ), self::$card_facts, $common );
		$keys   = array_slice( array_merge( $keys, $common, $only ), 0, 10 );

		$rows = array();
		$diff = null;
		foreach ( $keys as $key ) {
			$fact_a = isset( $facts_a[ $key ] ) ? $facts_a[ $key ] : null;
			$fact_b = isset( $facts_b[ $key ] ) ? $facts_b[ $key ] : null;
			$label  = $fact_a ? $fact_a[0] : $fact_b[0];
			$va     = $fact_a ? $fact_a[1] : '—';
			$vb     = $fact_b ? $fact_b[1] : '—';
			$rows[] = array( $label, $va, $vb );
			if ( ! $diff && $fact_a && $fact_b && ! in_array( $key, self::$card_facts, true ) && ASI_Normalizer::normalize( $va ) !== ASI_Normalizer::normalize( $vb ) ) {
				$diff = array( $label, $va, $vb );
			}
		}

		$parts = array( $this->responder->say( 'compare', array( 'a' => $a['card']['title'], 'b' => $b['card']['title'] ) ) );
		if ( $diff ) {
			$parts[] = $this->responder->say( 'compare_diff', array( 'label' => $this->lower_label( $diff[0] ), 'va' => $diff[1], 'vb' => $diff[2] ) );
		}
		$price_a = $this->base_price( $a );
		$price_b = $this->base_price( $b );
		if ( $price_a > 0 && $price_b > 0 && $price_a !== $price_b ) {
			$parts[] = $this->responder->say( 'compare_cheaper', array( 'title' => $price_a < $price_b ? $a['card']['title'] : $b['card']['title'] ) );
		}
		if ( 1 === count( $parts ) ) {
			$parts[] = $this->responder->say( 'compare_same' );
		}

		// Si el sitio tiene comparador en PDF: en el chat solo un resumen y la comparativa completa
		// en PDF para verla aquí mismo (sin pedir datos); enviarla por correo se ofrece aparte.
		$file = null;
		$runner = $this->runner();
		if ( $runner && isset( $runner->available()['compare'] ) ) {
			$preview = $runner->preview( 'compare', array( (int) $a['post_id'], (int) $b['post_id'] ) );
			if ( is_array( $preview ) && ! empty( $preview['url'] ) ) {
				$file    = array(
					'url'      => (string) $preview['url'],
					'name'     => (string) $preview['filename'],
					'label'    => $this->responder->say( 'file_label_compare' ),
					'open'     => $this->responder->say( 'file_open' ),
					'download' => $this->responder->say( 'file_download' ),
				);
				$rows    = array_slice( $rows, 0, 6 );
				$parts[] = $this->responder->say( 'compare_full_pdf' );
			}
		}

		return array(
			'kind'        => 'compare',
			'text'        => $this->sentences( $parts ),
			'compare'     => array(
				'columns' => array( $a['card']['title'], $b['card']['title'] ),
				'urls'    => array( $a['card']['url'], $b['card']['url'] ),
				'rows'    => $rows,
			),
			'file'        => $file,
			'followup'    => $file ? $this->responder->say( 'compare_offer_email' ) : ( $runner && isset( $runner->available()['compare'] ) ? $this->responder->say( 'compare_offer_pdf' ) : $this->responder->say( 'fact_followup' ) ),
			'cards'       => $file ? array() : $this->cards( array( $a, $b ) ),
			'links'       => array(),
			'suggestions' => array(),
			'more'        => false,
			'state'       => array(
				'results'  => array( (int) $a['post_id'], (int) $b['post_id'] ),
				'focus'    => 0,
				'selected' => array( (int) $a['post_id'], (int) $b['post_id'] ),
				'last'     => 'compare',
				// Qué PDF ya está a la vista: si después lo pide por correo, no se vuelve a mostrar.
				'previewed' => $file ? array( 'action' => 'compare', 'ids' => array( (int) $a['post_id'], (int) $b['post_id'] ) ) : array(),
				// Después de comparar se ofrece la comparación en PDF, si el sitio la tiene.
				'offer'   => $this->runner() && isset( $this->runner()->available()['compare'] ) ? array( 'action' => 'compare', 'ids' => array( (int) $a['post_id'], (int) $b['post_id'] ) ) : array(),
			),
		);
	}

	/**
	 * "El más barato", "los montacargas más económicos", "el más caro". null si no lo pide.
	 */
	private function price_rank_flow( $message, $normalized, array $state ) {
		$cheap  = ASI_Lexicon::find_phrase( $normalized, (array) $this->lexicon->get_list( 'cheapest_markers' ) );
		$pricey = ASI_Lexicon::find_phrase( $normalized, (array) $this->lexicon->get_list( 'priciest_markers' ) );
		$marker = strlen( $cheap ) >= strlen( $pricey ) ? $cheap : $pricey;
		if ( '' === $marker ) {
			return null;
		}
		$rest  = trim( str_replace( ' ' . $marker . ' ', ' ', ' ' . $normalized . ' ' ) );
		$query = $this->analyzer->analyze( $rest );
		$text  = $rest;
		if ( ! $query['groups'] ) {
			if ( '' === $state['q'] ) {
				return null;
			}
			$text  = $state['q'];
			$query = $this->analyzer->analyze( $text );
		}
		$result = $this->search->search( $this->context['lang'], $query, array( 'roles' => array( 'catalog' ), 'limit' => 200 ) );
		// "¿Cuánto sale la más barata?": las palabras sueltas no son un producto; es de lo que venía viendo.
		if ( ( ! $result['items'] || 'exact' !== $result['quality'] ) && $text === $rest && '' !== $state['q'] ) {
			$text   = $state['q'];
			$query  = $this->analyzer->analyze( $text );
			$result = $this->search->search( $this->context['lang'], $query, array( 'roles' => array( 'catalog' ), 'limit' => 200 ) );
		}
		if ( ! $result['items'] ) {
			return null;
		}

		$priced = array();
		foreach ( $result['items'] as $row ) {
			$price = $this->base_price( $row );
			if ( $price > 0 ) {
				$priced[] = array( $price, $row );
			}
		}
		$en_label = '' !== $query['label'] ? ' ' . $this->lexicon->word( 'among' ) . ' ' . $query['label'] : '';

		if ( ! $priced ) {
			$page  = $this->info_page( 'quote' );
			$page  = $page ? $page : $this->info_page( 'contact' );
			return array(
				'kind'        => 'info',
				'text'        => $this->responder->say( 'prices_missing', array( 'en_label' => $en_label ) ),
				'followup'    => '',
				'cards'       => array(),
				'links'       => $page ? array( $page ) : array(),
				'suggestions' => array(),
				'more'        => false,
				'state'       => array(),
			);
		}

		$ascending = $marker === $cheap;
		usort(
			$priced,
			function ( $x, $y ) use ( $ascending ) {
				if ( $x[0] === $y[0] ) {
					return 0;
				}
				return ( $x[0] < $y[0] ) === $ascending ? -1 : 1;
			}
		);
		$rows = array_column( $priced, 1 );
		$top  = $rows[0];
		$next = array_slice( $rows, 1, 3 );
		$ids  = array_map( 'intval', array_column( array_slice( $rows, 0, 4 ), 'post_id' ) );

		$top_cards = $this->cards( array( $top ), $query );
		// El precio como se ve en la tarjeta ("$ 25,990"), no el número crudo de la ficha.
		$price = isset( $top_cards[0]['price'] ) && '' !== (string) $top_cards[0]['price'] ? $top_cards[0]['price'] : $top['card']['price'];
		return array(
			'kind'        => 'results',
			'text'        => $this->responder->say( $ascending ? 'cheapest' : 'priciest', array( 'title' => $top['card']['title'], 'price' => $price, 'en_label' => $en_label ) ),
			'cards'       => $top_cards,
			'extra'       => $next ? array( 'text' => $this->responder->say( 'sorted_more' ), 'cards' => $this->cards( $next, $query ) ) : array(),
			'followup'    => $this->responder->say( 'fact_followup' ),
			'links'       => array(),
			'suggestions' => array(),
			'more'        => false,
			'state'       => array( 'q' => $this->short( $text, 200 ), 'shown' => $ids, 'results' => $ids, 'focus' => (int) $top['post_id'] ),
		);
	}

	/**
	 * Frases que describen un producto con los datos de su ficha (marca, línea, datos clave, precio).
	 *
	 * @param array $row        Fila.
	 * @param int   $max_facts  Datos clave a mencionar.
	 * @param bool  $with_price Mencionar el precio.
	 * @return string[]
	 */
	private function describe( array $row, $max_facts = 3, $with_price = true ) {
		$card  = $row['card'];
		$parts = array();
		$brand = isset( $card['brand'] ) ? (string) $card['brand'] : '';
		$cat   = isset( $card['category'] ) ? (string) $card['category'] : '';
		// La marca solo se dice si el nombre no la trae ya.
		if ( '' !== $brand && false !== strpos( ' ' . ASI_Normalizer::normalize( $card['title'] ) . ' ', ' ' . ASI_Normalizer::normalize( $brand ) . ' ' ) ) {
			$brand = '';
		}
		if ( '' !== $brand && '' !== $cat ) {
			$parts[] = $this->responder->say( 'describe_brand_category', array( 'brand' => $brand, 'category' => $this->lower_label( $cat ) ) );
		} elseif ( '' !== $brand ) {
			$parts[] = $this->responder->say( 'describe_brand', array( 'brand' => $brand ) );
		} elseif ( '' !== $cat ) {
			$parts[] = $this->responder->say( 'describe_category', array( 'category' => $this->lower_label( $cat ) ) );
		}

		$key_facts = array();
		foreach ( $this->facts_of( $row ) as $fact ) {
			$length = function_exists( 'mb_strlen' ) ? mb_strlen( $fact[1] ) : strlen( $fact[1] );
			if ( ! in_array( $fact[2], self::$card_facts, true ) && $length <= 40 ) {
				$key_facts[] = $this->format_fact( $fact[0], $fact[1] );
			}
			if ( count( $key_facts ) >= $max_facts ) {
				break;
			}
		}
		if ( $key_facts ) {
			$parts[] = $this->responder->say( 'describe_facts', array( 'facts' => $this->responder->join_list( $key_facts ) ) );
		}
		if ( $with_price && ! empty( $card['price'] ) ) {
			$parts[] = $this->responder->say( 'describe_price', array( 'price' => $card['price'] ) );
		}
		return $parts;
	}

	/**
	 * Un producto nombrado en un pedazo de texto ("el FB20", "el segundo"), si es inequívoco.
	 *
	 * @param string $text  Texto.
	 * @param array  $state Estado.
	 * @return array|null Fila.
	 */
	private function resolve_product( $text, array $state ) {
		$normalized = ASI_Normalizer::normalize( $text );
		if ( '' === $normalized ) {
			return null;
		}
		$reference = $this->detect_reference( $normalized, $state );
		if ( $reference ) {
			$rows = $this->repository->rows( $this->context['lang'], array( $reference ) );
			return $rows ? $rows[0] : null;
		}
		$query = $this->analyzer->analyze( $text );
		if ( ! $query['groups'] ) {
			return null;
		}
		$result = $this->search->search( $this->context['lang'], $query, array( 'roles' => array( 'catalog' ), 'limit' => 3 ) );
		if ( ! $result['items'] || 'exact' !== $result['quality'] ) {
			return null;
		}
		if ( $result['named'] || 1 === $result['total'] ) {
			return $result['items'][0];
		}
		$second = isset( $result['items'][1] ) ? $result['items'][1]['final'] : 0;
		return $result['items'][0]['final'] >= $second * 1.5 ? $result['items'][0] : null;
	}

	/**
	 * ¿El texto nombra varios productos del sitio (en su nombre o sus términos, no solo en el texto)?
	 */
	private function names_products( $text ) {
		$query = $this->analyzer->analyze( $text );
		if ( ! $query['groups'] ) {
			return false;
		}
		$result = $this->search->search( $this->context['lang'], $query, array( 'roles' => array( 'catalog' ), 'limit' => 5 ) );
		$strong = 0;
		foreach ( $result['items'] as $row ) {
			$strong += $row['score'] >= 6 ? 1 : 0;
		}
		return $strong >= 2;
	}

	/**
	 * Producto del que se está hablando: el último que se vio solo o en detalle.
	 */
	private function focus_row( array $state ) {
		$id = $state['focus'] ? $state['focus'] : ( 1 === count( $state['results'] ) ? $state['results'][0] : 0 );
		if ( ! $id ) {
			return null;
		}
		$rows = $this->repository->rows( $this->context['lang'], array( $id ) );
		return $rows ? $rows[0] : null;
	}

	/**
	 * Palabras que no están en el nombre, términos ni códigos del producto.
	 */
	private function words_outside( array $words, array $row ) {
		$haystack = ' ' . $row['title_norm'] . ' ' . str_replace( '|', ' ', $row['terms_norm'] ) . ' ' . $row['codes'] . ' ';
		$outside  = array();
		foreach ( $words as $word ) {
			$normal = ASI_Normalizer::singular( $word );
			if ( false === strpos( $haystack, ' ' . $normal . ' ' ) && false === strpos( $haystack, ' ' . $word . ' ' ) && false === strpos( $haystack, ' ' . ASI_Normalizer::compact_code( $word ) . ' ' ) ) {
				$outside[] = $normal;
			}
		}
		return $outside;
	}

	/**
	 * Datos de la ficha: los guardados en el índice más los de la tarjeta (precio, marca, línea, código).
	 *
	 * @param array $row Fila.
	 * @return array[] [etiqueta, valor, clave] (clave: price/brand/category/code o el nombre en forma normal)
	 */
	private function facts_of( array $row ) {
		$details = $this->details_of( (int) $row['post_id'] );
		$facts   = array();
		$seen    = array();
		foreach ( self::$card_facts as $key ) {
			if ( ! empty( $row['card'][ $key ] ) ) {
				$facts[]      = array( $this->lexicon->word( $key ), (string) $row['card'][ $key ], $key );
				$seen[ $key ] = true;
			}
		}
		foreach ( $details['facts'] as $fact ) {
			if ( ! is_array( $fact ) || count( $fact ) < 2 ) {
				continue;
			}
			$key = ASI_Lexicon::normal_form( $fact[0] );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$facts[]      = array( (string) $fact[0], (string) $fact[1], $key );
		}
		// Especificaciones de la tarjeta que no estén ya (índices viejos o filtros de otros plugins).
		$values = array();
		foreach ( $facts as $fact ) {
			$values[ ASI_Lexicon::normal_form( $fact[1] ) ] = true;
		}
		foreach ( isset( $row['card']['specs'] ) ? (array) $row['card']['specs'] : array() as $spec ) {
			$key = ASI_Lexicon::normal_form( $spec['label'] );
			if ( ! isset( $seen[ $key ] ) && ! isset( $values[ ASI_Lexicon::normal_form( $spec['value'] ) ] ) ) {
				$seen[ $key ] = true;
				$facts[]      = array( $spec['label'], $spec['value'], $key );
			}
		}
		return $facts;
	}

	/**
	 * Datos indexados por su clave: price/brand/category/code o el nombre en forma normal.
	 */
	private function facts_by_key( array $facts, $with_code = true ) {
		$out = array();
		foreach ( $facts as $fact ) {
			if ( ( $with_code || 'code' !== $fact[2] ) && ! isset( $out[ $fact[2] ] ) ) {
				$out[ $fact[2] ] = $fact;
			}
		}
		return $out;
	}

	/**
	 * Datos de la ficha que responden las palabras de la pregunta.
	 *
	 * @return array[] [clave, etiqueta, valor]
	 */
	private function match_facts( array $asked, array $facts, array $fact_map ) {
		$by_key  = $this->facts_by_key( $facts );
		$matched = array();
		foreach ( $asked as $word ) {
			$targets = isset( $fact_map[ $word ] ) ? $fact_map[ $word ] : array( $word );
			foreach ( $by_key as $key => $fact ) {
				if ( isset( $matched[ $key ] ) ) {
					continue;
				}
				if ( in_array( $key, self::$card_facts, true ) ? in_array( $key, $targets, true ) : $this->label_matches( $key, $targets ) ) {
					$matched[ $key ] = array( $key, $fact[0], $fact[1] );
				}
			}
		}
		return array_slice( array_values( $matched ), 0, 3 );
	}

	/**
	 * ¿El nombre del dato ("peso neto", "potencia hp") corresponde a lo que pregunta?
	 */
	private function label_matches( $label, array $targets ) {
		foreach ( explode( ' ', $label ) as $word ) {
			foreach ( $targets as $target ) {
				if ( $word === $target || ( strlen( $word ) >= 5 && strlen( $target ) >= 5 && substr( $word, 0, 5 ) === substr( $target, 0, 5 ) ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Palabra de la pregunta => nombres posibles del dato (todo en forma normal).
	 */
	private function fact_word_map() {
		$map = array();
		foreach ( (array) $this->lexicon->get_list( 'fact_words' ) as $word => $targets ) {
			$normalized = array();
			foreach ( (array) $targets as $target ) {
				$normalized[] = in_array( $target, self::$card_facts, true ) ? $target : ASI_Lexicon::normal_form( $target );
			}
			$map[ ASI_Normalizer::normalize( $word ) ] = $normalized;
		}
		return $map;
	}

	/**
	 * "potencia de 75 HP", "color rojo".
	 */
	private function format_fact( $label, $value ) {
		$label = $this->lower_label( $label );
		return preg_match( '/^[0-9]/', $value ) ? $label . ' ' . $this->lexicon->word( 'fact_of' ) . ' ' . $value : $label . ' ' . $value;
	}

	/**
	 * Etiqueta en minúscula, salvo siglas ("HP", "SKU").
	 */
	private function lower_label( $label ) {
		$label = trim( (string) $label );
		if ( preg_match( '/^[A-Z0-9]{2,3}$/', $label ) ) {
			return $label;
		}
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $label, 'UTF-8' ) : strtolower( $label );
	}

	/**
	 * Datos y texto de un post (una consulta por petición y post).
	 */
	private function details_of( $id ) {
		if ( ! isset( $this->details_cache[ $id ] ) ) {
			$details                    = $this->repository->details( $this->context['lang'], array( $id ) );
			$this->details_cache[ $id ] = isset( $details[ $id ] ) ? $details[ $id ] : array( 'facts' => array(), 'text' => '' );
		}
		return $this->details_cache[ $id ];
	}

	/**
	 * Precio de un texto ("Q 1,250.00", "$95", "₡12 500") como número; 0 si no hay.
	 *
	 * @param string $text Texto.
	 * @return float
	 */
	public static function parse_price( $text ) {
		if ( ! preg_match( '/[0-9][0-9.,\s]*/', (string) $text, $match ) ) {
			return 0.0;
		}
		$number = preg_replace( '/\s+/', '', rtrim( $match[0], " .," ) );
		if ( preg_match( '/[.,]([0-9]{1,2})$/', $number, $decimals ) ) {
			$integer = substr( $number, 0, -strlen( $decimals[0] ) );
			return (float) ( preg_replace( '/[^0-9]/', '', $integer ) . '.' . $decimals[1] );
		}
		return (float) preg_replace( '/[^0-9]/', '', $number );
	}
}
