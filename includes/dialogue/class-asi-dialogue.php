<?php
/**
 * Conversación: decide qué hacer con cada mensaje y arma la respuesta.
 *
 * Clase pura (habla con el índice por ASI_Repository). Flujo de cada mensaje:
 *  1. Seguimientos de la conversación: "más opciones", "el segundo", refinar ("solo John Deere").
 *  2. Intención: saludo, gracias, ayuda, o información del sitio (contacto, ubicaciones…).
 *  3. Búsqueda en el contexto (idioma + país), con fidelidad antes que relleno.
 *  4. Respuesta con variantes que no se repiten, tarjetas, enlaces y sugerencias.
 *
 * La memoria de la conversación viaja en $state (la guarda el navegador), así el servidor no
 * escribe nada por cada mensaje.
 *
 * @package AsesorInteligente
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'ASI_CLI_TESTS' ) ) {
	exit;
}

class ASI_Dialogue {

	use ASI_Dialogue_Products;
	use ASI_Dialogue_Advice;
	use ASI_Dialogue_Similar;
	use ASI_Dialogue_Actions;

	const MAX_MESSAGE = 300;

	// Columnas de la tabla de cuotas en el chat.
	const FINANCE_COLUMNS = 4;
	const MAX_SHOWN   = 300;

	/** @var ASI_Repository */
	private $repository;

	/** @var array lang, language, site_name, country_name, multi_country, hour, home_url, siblings, languages, synonyms */
	private $context;

	/** @var callable|null */
	private $random;

	/** @var ASI_Lexicon */
	private $lexicon;

	/** @var array */
	private $vocab;

	/** @var ASI_Query_Analyzer */
	private $analyzer;

	/** @var ASI_Search */
	private $search;

	/** @var ASI_Responder */
	private $responder;

	/** @var array Caché de páginas informativas por intención. */
	private $info_pages = array();

	/** @var array<string,bool> ¿La página informativa de cada intención es una página (y no otro contenido)? */
	private $info_is_page = array();

	/** @var array Enganche y plazo que pidió el cliente: pct (%), amt (monto), term (meses). Se recuerdan. */
	private $fin_memory = array();

	/** @var bool Este mensaje traía números de enganche/plazo que las reglas no entendieron. */
	private $fin_unread = false;

	/** @var array<int,array> Datos de la ficha ya leídos en esta petición. */
	private $details_cache = array();

	public function __construct( ASI_Repository $repository, array $context, $random = null ) {
		$this->repository = $repository;
		$this->context    = array_merge(
			array(
				'lang'          => '',
				'language'      => 'es',
				'site_name'     => '',
				'country_name'  => '',
				'multi_country' => false,
				'hour'          => 12,
				'home_url'      => '',
				'siblings'      => array(),
				'languages'     => array( 'es', 'en' ),
				'synonyms'      => array(),
				'location_types' => array(),
				'learned'       => array(), // clave de búsqueda => IDs enseñados desde el panel.
				'tone'          => 'tu',
				'emojis'        => false,
				'money'         => array(), // base, map, rates (ASI_Money).
				'packs'         => array(), // ASI_Pack activos (atributos del giro).
				'actions'       => null,    // ASI_Action_Runner: cotizar, comparar en PDF, cuotas.
				'card_flags'    => null,    // Sellos visibles: etiqueta => cómo se muestra (null = todos).
			),
			$context
		);
		$this->random = $random;
	}

	/**
	 * Responde un mensaje.
	 *
	 * @param string $message Mensaje del usuario.
	 * @param array  $state   Estado de la conversación enviado por el navegador.
	 * @return array kind, text, followup, cards, links, suggestions, more, state
	 */
	public function handle( $message, array $state = array() ) {
		$message = trim( function_exists( 'mb_substr' ) ? mb_substr( (string) $message, 0, self::MAX_MESSAGE ) : substr( (string) $message, 0, self::MAX_MESSAGE ) );
		$state   = $this->clean_state( $state );
		$this->fin_memory = $state['fin'];
		$this->fin_unread = false;

		$this->lexicon   = ASI_Lexicon::get( $this->context['language'], $this->context['synonyms'] );
		$this->vocab     = $this->repository->vocabulary( $this->context['lang'] );
		$this->analyzer  = new ASI_Query_Analyzer( $this->lexicon, $this->vocab, (array) $this->context['money'], (array) $this->context['packs'] );
		$this->search    = new ASI_Search( $this->repository, $this->vocab, (array) $this->context['money'] );
		$this->responder = new ASI_Responder( $this->lexicon, $this->context, $state['recent'], $this->random );

		$normalized = ASI_Normalizer::normalize( $message );
		// Errores de dedo en las palabras de acción ("cuoa", "cotisacion"), antes de buscar.
		list( $message, $normalized ) = $this->fix_action_typos( $message, $normalized );
		$response   = $this->route( $message, $normalized, $state );
		if ( $this->fin_unread && isset( $response['kind'] ) && in_array( $response['kind'], array( 'finance', 'file' ), true ) ) {
			$response['fin_unread'] = true; // Lo usa el endpoint (IA); no se envía al navegador.
		}
		// Justo después de una tabla de cuotas, "¿y si le doy 200 al principio?" no es una búsqueda:
		// si las reglas solo lo entendieron a medias y trae un número, que la IA lea el enganche.
		if ( in_array( $state['last'], array( 'finance', 'quote' ), true ) && ! empty( $response['learn'] ) && preg_match( '/[0-9]{2,}|(^|\s)(mil|cien|ciento|doscientos|trescientos)(\s|$)/', $normalized ) ) {
			$response['fin_unread'] = true;
		}
		// Clave de la pregunta para el aprendizaje (la usa el endpoint; no se envía al navegador).
		$response['qkey'] = self::content_key( $this->analyzer, $this->lexicon, $message );
		$response   = $this->maybe_language_hint( $response, $normalized );

		// Texto del botón "más opciones" en el idioma de la conversación (lo envía tal cual al pulsarlo).
		$response['more_label'] = $this->lexicon->word( 'more_chip' );

		$response['state'] = array_merge(
			$state,
			array( 'awaiting' => '' ),
			isset( $response['state'] ) ? $response['state'] : array(),
			array(
				'recent' => $this->responder->recent(),
				// Lo que pidió de enganche y plazo sigue valiendo en las próximas cuotas.
				'fin'    => $this->fin_memory,
				'turns'  => $state['turns'] + 1,
				'lang'   => $this->context['lang'],
			)
		);
		return $response;
	}

	/**
	 * Corrige las palabras de acción mal escritas ("cuoa" → cuota, "comparatiba" → comparativa,
	 * "enganch" → enganche) con 1 letra de tolerancia (2 en palabras largas). No toca palabras del
	 * catálogo ni palabras comunes: "cuoa" no es un vehículo, pero "costa" sí podría ser algo.
	 *
	 * @return array { mensaje, normalizado }
	 */
	private function fix_action_typos( $message, $normalized ) {
		$vocab = array_map( array( 'ASI_Normalizer', 'normalize' ), (array) $this->lexicon->get_list( 'action_vocab' ) );
		if ( ! $vocab || '' === $normalized ) {
			return array( $message, $normalized );
		}
		$fixes  = array();
		$tokens = explode( ' ', $normalized );
		foreach ( $tokens as $i => $token ) {
			$length = strlen( $token );
			if ( $length < 4 || ctype_digit( $token ) || in_array( $token, $vocab, true ) || isset( $this->vocab['tokens'][ $token ] ) || $this->lexicon->is_stopword( $token ) || $this->lexicon->synonyms( $token ) ) {
				continue;
			}
			$max  = $length <= 6 ? 1 : 2;
			$best = '';
			$dist = PHP_INT_MAX;
			foreach ( $vocab as $word ) {
				if ( $word[0] !== $token[0] || abs( strlen( $word ) - $length ) > $max ) {
					continue;
				}
				$d = levenshtein( $token, $word );
				if ( $d <= $max && $d < $dist ) {
					$best = $word;
					$dist = $d;
				}
			}
			if ( '' !== $best ) {
				$tokens[ $i ]    = $best;
				$fixes[ $token ] = $best;
			}
		}
		if ( ! $fixes ) {
			return array( $message, $normalized );
		}
		// El mensaje original también, palabra por palabra (conserva el resto como lo escribió).
		$words = preg_split( '/(\s+)/u', $message, -1, PREG_SPLIT_DELIM_CAPTURE );
		foreach ( $words as $i => $word ) {
			$key = ASI_Normalizer::normalize( $word );
			if ( isset( $fixes[ $key ] ) ) {
				$words[ $i ] = $fixes[ $key ];
			}
		}
		return array( implode( '', $words ), implode( ' ', $tokens ) );
	}

	/**
	 * Decide el camino del mensaje.
	 */
	private function route( $message, $normalized, array $state ) {
		if ( '' === $normalized ) {
			return $this->smalltalk( 'greeting' );
		}

		// Despedidas y cierres ("solo eso, gracias", "eso es todo", "listo, bye").
		$closing = $this->closing_flow( $normalized, $state );
		if ( $closing ) {
			return $closing;
		}

		// 1. Seguimientos.
		if ( '' !== $state['q'] && $this->is_more_request( $normalized ) ) {
			return $this->more( $state );
		}

		// Respuestas enseñadas desde el panel (Aprendizaje) para esta forma de preguntar.
		$learned = $this->learned_flow( $message );
		if ( $learned ) {
			return $learned;
		}

		// "Buscar otra cosa" (el botón de la pregunta "no estoy seguro de haberte entendido").
		if ( '' !== ASI_Lexicon::find_phrase( $normalized, (array) $this->lexicon->get_list( 'search_else_words' ) ) && str_word_count( $normalized ) <= 5 ) {
			$help          = $this->smalltalk( 'help' );
			$help['state'] = array( 'q' => '', 'shown' => array(), 'results' => array(), 'focus' => 0, 'offer' => array() );
			return $help;
		}

		// Cerrar la venta: datos del cliente, "sí", cuotas, cotización, comparación en PDF.
		$action = $this->action_flow( $message, $normalized, $state );
		if ( $action ) {
			return $action;
		}

		// "Algo parecido al Frontier" (antes que "el más barato": "parecido pero más barato").
		$similar = $this->similar_flow( $message, $normalized, $state );
		if ( $similar ) {
			return $similar;
		}

		$reference = $this->detect_reference( $normalized, $state );
		if ( null !== $reference ) {
			// "¿Cuánto cuesta el segundo?" es un dato; "el segundo" solo, el detalle.
			$fact = $this->fact_flow( $normalized, $state );
			return $fact ? $fact : $this->detail( $reference, $state );
		}

		// Comparar dos productos, o el más barato / el más caro.
		$special = $this->compare_flow( $normalized, $state );
		$special = $special ? $special : $this->price_rank_flow( $message, $normalized, $state );
		if ( $special ) {
			return $special;
		}

		// Asesor: el cliente cuenta su situación ("somos 4 con un niño") o pide consejo.
		$advice = $this->advice_flow( $message, $state );
		if ( $advice ) {
			return $advice;
		}

		// 2. Intención. "¿Qué es {nombre del sitio}?" va directo a quiénes somos.
		if ( $this->asks_about_site( $normalized ) ) {
			return $this->info( 'about' );
		}
		$classifier = new ASI_Intent_Classifier( $this->lexicon );
		$intent     = $classifier->classify( $normalized );
		$stripped   = '' !== $intent['anchor'] ? $this->strip_anchor( $message, $intent['anchor'] ) : $message;
		$query      = $this->analyzer->analyze( $stripped );
		$searchable = $this->is_searchable( $query );

		if ( ASI_Intent_Classifier::is_smalltalk( $intent['intent'] ) ) {
			if ( $searchable && 'goodbye' !== $intent['intent'] ) {
				$fact = $this->fact_flow( ASI_Normalizer::normalize( $stripped ), $state );
				if ( $fact ) {
					return $fact;
				}
				// "Hola, busco montacargas": se saluda y se busca.
				return $this->search_flow( $stripped, $query, $state, array( 'greet' => 'greeting' === $intent['intent'] ) );
			}
			return $this->smalltalk( $intent['intent'] );
		}

		// Solo un presupuesto ("algo de 60 a 70 mil dólares"): es una búsqueda, diga lo que diga el clasificador.
		if ( empty( $query['groups'] ) && ASI_Search::has_hard_filter( $query ) && ! in_array( $intent['intent'], array( 'contact', 'location', 'hours', 'jobs', 'about' ), true ) ) {
			return $this->search_flow( $stripped, $query, $state );
		}

		if ( 'search' !== $intent['intent'] ) {
			// "Cotizar montacargas eléctricos": se busca y se ofrece la página de cotización.
			if ( $searchable && $this->has_critical( $query ) && in_array( $intent['intent'], array( 'quote', 'financing', 'human' ), true ) ) {
				return $this->search_flow( $stripped, $query, $state, array( 'cta' => $intent['intent'] ) );
			}
			return $this->info( $intent['intent'], $query, $normalized );
		}

		// 3. Un dato de un producto ("¿cuánto pesa?", "¿trae cabina el 6110J?").
		$fact = $this->fact_flow( $normalized, $state );
		if ( $fact ) {
			return $fact;
		}

		// 4. Búsqueda (con posible refinamiento de la anterior).
		$query = $this->analyzer->analyze( $message );
		if ( $this->is_refinement( $normalized, $query, $state ) ) {
			return $this->refine( $message, $query, $state );
		}
		return $this->search_flow( $message, $query, $state );
	}

	/**
	 * Quita del mensaje original las palabras del ancla de la intención, conservando cómo escribió
	 * el resto (acentos y mayúsculas): "¿Tienen tienda en Petén?" → "en Petén".
	 *
	 * @param string $message Mensaje original.
	 * @param string $anchor  Ancla normalizada ("tienen tienda").
	 * @return string
	 */
	private function strip_anchor( $message, $anchor ) {
		$words  = preg_split( '/\s+/u', trim( $message ) );
		$target = explode( ' ', $anchor );
		$size   = count( $target );
		for ( $i = 0; $i + $size <= count( $words ); $i++ ) {
			$slice = array_map( array( 'ASI_Normalizer', 'normalize' ), array_slice( $words, $i, $size ) );
			if ( $slice === $target ) {
				array_splice( $words, $i, $size );
				return trim( implode( ' ', $words ) );
			}
		}
		// Si el ancla no coincide palabra a palabra (signos pegados), se usa el texto normalizado.
		return trim( str_replace( ' ' . $anchor . ' ', ' ', ' ' . ASI_Normalizer::normalize( $message ) . ' ' ) );
	}

	/**
	 * Búsqueda y respuesta con resultados.
	 *
	 * @param string $text    Texto buscado.
	 * @param array  $query   Consulta analizada.
	 * @param array  $state   Estado.
	 * @param array  $options greet (bool), cta (intención), opening (clave), refine (texto).
	 * @return array
	 */
	private function search_flow( $text, array $query, array $state, array $options = array() ) {
		if ( ! $this->is_searchable( $query ) ) {
			// "Dame la cosa esa pues" con opciones a la vista: pregunta qué quiere hacer con ellas.
			return $state['results'] && ! $options ? $this->unclear( $state ) : $this->smalltalk( 'help' );
		}

		$boosts  = $this->repository->boosts( $this->context['lang'], self::content_key( $this->analyzer, $this->lexicon, $text ) );
		$catalog = $this->search->search( $this->context['lang'], $query, array( 'roles' => array( 'catalog' ), 'boosts' => $boosts ) );
		$pages   = $this->search->search( $this->context['lang'], $query, array( 'roles' => array( 'info', 'article' ), 'limit' => 3 ) );

		$intro = array();
		if ( ! empty( $options['greet'] ) ) {
			$intro[] = $this->responder->say( 'greeting_prefix' );
		}
		if ( $query['corrections'] ) {
			$intro[] = $this->responder->say( 'corrected', array( 'fixed' => implode( ' ', array_values( $query['corrections'] ) ) ) );
		}

		// Hay opciones a la vista y no entendió bien lo que pide: pregunta en vez de adivinar.
		if ( $this->is_unclear( $query, $catalog, $state, $options ) ) {
			return $this->unclear( $state );
		}

		if ( ! $catalog['items'] && ! $pages['items'] ) {
			return $this->none( $text, $intro );
		}

		// Sin productos pero con páginas que hablan de eso: se responde con la página
		// (solo las que coinciden casi tanto como la mejor).
		if ( ! $catalog['items'] ) {
			$best  = $pages['items'][0]['final'];
			$close = array_filter(
				$pages['items'],
				function ( $row ) use ( $best ) {
					return $row['final'] >= $best * 0.6;
				}
			);
			// Lo que coincide son sucursales ("tienda zona 10"): se muestran como tarjetas de lugar.
			$places = array_filter( $close, array( $this, 'is_location_row' ) );
			if ( $places && count( $places ) === count( $close ) ) {
				return $this->places_response( 'info.location_specific', array_values( $places ), $this->info_page( 'location' ) );
			}
			// La frase de la página que responde, si la hay; si no, solo el enlace.
			$first   = reset( $close );
			$details = $this->details_of( (int) $first['post_id'] );
			$quote   = ASI_Extractor::best( $details['text'], explode( ' ', $query['content'] ), array(), 2 );
			$intro[] = $this->responder->say( $quote ? 'info_quote.page' : 'info.page' );
			return array(
				'kind'        => 'info',
				'text'        => $this->sentences( $intro ),
				'quote'       => $quote,
				'followup'    => $quote ? $this->responder->say( 'info_quote_more' ) : '',
				'cards'       => array(),
				'links'       => $this->links( $close ),
				'suggestions' => array(),
				'more'        => false,
				'state'       => array( 'q' => '', 'shown' => array(), 'results' => array() ),
			);
		}

		$total = $catalog['total'];
		if ( ! empty( $catalog['browse'] ) && empty( $options['opening'] ) ) {
			$options['opening'] = ! empty( $catalog['budget'] ) ? ( ! empty( $query['attr_labels'] ) ? 'results_budget_attrs' : 'results_budget' ) : 'results_attrs';
		}
		$vars  = array(
			'budget' => ASI_Search::has_price_filter( $query ) ? $this->budget_text( $query ) : '',
			'wanted' => $this->responder->join_list( isset( $query['attr_labels'] ) ? (array) $query['attr_labels'] : array(), 'and' ),
			'n'      => $this->responder->count_options( $total ),
			'label'  => $query['label'],
			'query'  => $this->short( $text ),
			'refine' => isset( $options['refine'] ) ? $options['refine'] : '',
		);

		if ( ! empty( $options['opening'] ) ) {
			$intro[] = $this->responder->say( $options['opening'], $vars );
		} elseif ( 'exact' !== $catalog['quality'] ) {
			$intro[] = $this->responder->say( 'results_partial', $vars );
		} elseif ( $catalog['named'] ) {
			// Pidió algo por su nombre: se nombra lo que encontró.
			$vars['title'] = $catalog['items'][0]['card']['title'];
			$intro[]       = $this->responder->say( 'results_named', $vars );
		} elseif ( 1 === $total ) {
			$intro[] = $this->responder->say( 'results_single', $vars );
		} else {
			$intro[] = $this->responder->say( 'results', $vars );
		}
		if ( ! empty( $query['exclude'] ) ) {
			$intro[] = $this->responder->say( 'excluded', array( 'excluded' => $query['exclude_label'] ) );
		}
		// Un solo producto (o el que nombró): se describe con los datos de su ficha.
		$single = 1 === $total || $catalog['named'];
		if ( $single && 'exact' === $catalog['quality'] ) {
			$intro = array_merge( $intro, $this->describe( $catalog['items'][0], 2, false ) );
		}

		$links    = array();
		$followup = array();

		// Precio: si lo pidió y las tarjetas no lo traen, se ofrece la cotización.
		$cta_intent = isset( $options['cta'] ) ? $options['cta'] : ( $query['asks_price'] && ! $this->any_price( $catalog['items'] ) ? 'quote' : '' );
		if ( $cta_intent ) {
			$page = $this->info_page( $cta_intent );
			$page = $page ? $page : $this->info_page( 'contact' );
			if ( $page ) {
				$followup[] = $this->responder->say( 'price' === $cta_intent || $query['asks_price'] ? 'price_note' : 'info.' . $cta_intent );
				$links[]    = $page;
			}
		}

		$suggestions = array();
		list( $clarify, $clarify_key ) = $this->clarify_facets( $catalog, $query );
		if ( $clarify ) {
			$followup[]  = $this->responder->say( $clarify_key, array( 'n' => $vars['n'], 'label' => $query['label'], 'facets' => $this->responder->join_list( 'clarify' === $clarify_key ? $this->lower( $clarify ) : $clarify, 'or' ) ) );
			$suggestions = $clarify;
		}

		// Pocos resultados: se ofrecen similares como tarjetas (nombre parecido o misma categoría).
		$similar = $catalog['similar'];
		if ( ! $similar && $total <= 2 && 'exact' === $catalog['quality'] ) {
			$similar = $this->same_category( $catalog['items'] );
		}
		if ( ! $clarify && ! $cta_intent ) {
			// Con similares el cierre siempre se dice: invita a elegir o a dar otro detalle.
			$followup[]  = $this->responder->say( $similar ? 'closing_similar' : ( 1 === $total ? 'closing_single' : 'closing' ) );
			$suggestions = $this->related_facets( $catalog, $query );
		}
		$extra = array();
		if ( $similar ) {
			$extra = array(
				'text'  => $this->responder->say( 'similar' ),
				'cards' => $this->cards( $similar, $query ),
			);
		}

		// Páginas que también responden (p. ej. "garantía" trae productos y la página de garantía).
		$strong_pages = array_filter(
			$pages['items'],
			function ( $row ) {
				return $row['score'] >= 12 && $row['coverage'] >= 1;
			}
		);
		if ( $strong_pages && ! $cta_intent ) {
			$followup[] = $this->responder->say( 'also_info' );
			$links      = array_merge( $links, $this->links( array_slice( $strong_pages, 0, 2 ) ) );
		}

		$shown   = array_map( 'intval', array_column( $catalog['items'], 'post_id' ) );
		$similar_ids = array_map( 'intval', array_column( $similar, 'post_id' ) );

		return array(
			'kind'        => 'results',
			'text'        => $this->sentences( $intro ),
			'cards'       => $this->cards( $catalog['items'], $query ),
			'extra'       => $extra,
			'followup'    => $this->sentences( $followup ),
			'links'       => $links,
			'suggestions' => $suggestions,
			'more'        => $total > count( $shown ),
			'learn'       => 'exact' === $catalog['quality'] ? '' : 'weak',
			'state'       => array(
				'q'       => $this->short( $text, 200 ),
				'focus'   => $single ? $shown[0] : 0,
				'shown'   => $shown,
				// "El segundo" cuenta en el orden en que se ven: primero lo pedido, luego los similares.
				'results' => array_slice( array_merge( $shown, $similar_ids ), 0, 8 ),
			),
		);
	}

	/**
	 * Otros productos de la misma categoría que los resultados (para ofrecer similares).
	 *
	 * @param array[] $items Resultados mostrados.
	 * @return array[]
	 */
	private function same_category( array $items ) {
		$category = isset( $items[0]['card']['category'] ) ? $items[0]['card']['category'] : '';
		if ( '' === $category ) {
			return array();
		}
		$normal = ASI_Lexicon::normal_form( $category );
		$query  = array(
			'groups'     => array( array( 'text' => $normal, 'alts' => array( $normal ), 'kind' => 'phrase', 'critical' => true, 'label' => $category, 'term_kind' => 'category' ) ),
			'content'    => $normal,
			'price_max'  => null,
			'price_min'  => null,
			'price_currency' => '',
			'attrs'      => array(),
			'attr_labels' => array(),
			'asks_price' => false,
		);
		$result = $this->search->search( $this->context['lang'], $query, array( 'roles' => array( 'catalog' ), 'exclude' => array_column( $items, 'post_id' ), 'limit' => 4 ) );
		return 'exact' === $result['quality'] ? $result['items'] : array();
	}

	/**
	 * "Solo John Deere", "y eléctricos": se suma a la búsqueda anterior.
	 */
	private function refine( $message, array $query, array $state ) {
		$combined_text  = $state['q'] . ' ' . $message;
		$combined_query = $this->analyzer->analyze( $combined_text );
		$combined       = $this->search->search( $this->context['lang'], $combined_query, array( 'roles' => array( 'catalog' ) ) );

		if ( $combined['items'] && 'exact' === $combined['quality'] ) {
			return $this->search_flow( $combined_text, $combined_query, $state, array( 'opening' => 'results_refined', 'refine' => $query['label'] ) );
		}
		// Sumado no da nada: se busca solo lo nuevo y se dice con honestidad.
		return $this->search_flow( $message, $query, $state, array( 'opening' => 'refine_empty', 'refine' => $query['label'] ) );
	}

	/**
	 * Más resultados de la última búsqueda.
	 */
	private function more( array $state ) {
		$query  = $this->analyzer->analyze( $state['q'] );
		$result = $this->search->search( $this->context['lang'], $query, array( 'roles' => array( 'catalog' ), 'exclude' => $state['shown'] ) );

		if ( ! $result['items'] ) {
			return array(
				'kind'        => 'no_more',
				'text'        => $this->responder->say( 'no_more' ),
				'followup'    => '',
				'cards'       => array(),
				'links'       => array(),
				'suggestions' => $this->related_facets( $result, $query ),
				'more'        => false,
				'state'       => array(),
			);
		}

		$ids   = array_map( 'intval', array_column( $result['items'], 'post_id' ) );
		$shown = array_slice( array_merge( $state['shown'], $ids ), -self::MAX_SHOWN );

		return array(
			'kind'        => 'more',
			'text'        => $this->responder->say( 'more', array( 'n' => $this->responder->count_options( count( $ids ) ) ) ),
			'followup'    => $this->responder->say( 'closing' ),
			'cards'       => $this->cards( $result['items'], $query ),
			'links'       => array(),
			'suggestions' => array(),
			'more'        => $result['total'] > count( $shown ),
			'state'       => array( 'shown' => $shown, 'results' => $ids ),
		);
	}

	/**
	 * Detalle de un resultado mostrado ("el segundo", "ese").
	 */
	private function detail( $post_id, array $state ) {
		if ( ! $post_id ) {
			return array(
				'kind'        => 'clarify',
				'text'        => $this->responder->say( 'reference_missing' ),
				'followup'    => '',
				'cards'       => array(),
				'links'       => array(),
				'suggestions' => array(),
				'more'        => false,
				'state'       => array(),
			);
		}

		$rows = $this->repository->rows( $this->context['lang'], array( $post_id ) );
		if ( ! $rows ) {
			return $this->smalltalk( 'help' );
		}
		$card  = $rows[0]['card'];
		$parts = array( $this->responder->say( 'detail', array( 'title' => $card['title'] ) ) );
		if ( ! empty( $card['summary'] ) ) {
			$summary = trim( $card['summary'] );
			$parts[] = preg_match( '/[.!?…]$/u', $summary ) ? $summary : $summary . '.';
		}
		$parts = array_merge( $parts, $this->describe( $rows[0], 4, true ) );

		$links = array();
		foreach ( isset( $card['docs'] ) ? $card['docs'] : array() as $doc ) {
			$links[] = array( 'title' => $doc['label'], 'url' => $doc['url'], 'summary' => '' );
		}
		$followup = '';
		if ( ! empty( $card['price'] ) ) {
			// Tienda: se compra desde la ficha, no se cotiza.
			$followup = $this->responder->say( 'detail_buy' );
		} else {
			$page = $this->info_page( 'quote' );
			$page = $page ? $page : $this->info_page( 'contact' );
			if ( $page ) {
				$followup = $this->responder->say( 'detail_cta' );
				$links[]  = $page;
			}
		}

		return array(
			'kind'        => 'detail',
			'text'        => $this->sentences( $parts ),
			'followup'    => $followup,
			'cards'       => $this->cards( $rows ),
			'links'       => $links,
			'suggestions' => '' !== $state['q'] ? array( $this->lexicon->word( 'more_chip' ) ) : array(),
			'more'        => false,
			'state'       => array( 'focus' => (int) $post_id ),
		);
	}

	/**
	 * Clave estable de una pregunta: lo que se busca en forma normal, ordenado. Usa el mismo análisis
	 * que la búsqueda, así respeta las palabras que el sitio usa en sus nombres ("Producto 2").
	 * "Busco los montacargas eléctricos" y "montacargas electrico" dan la misma clave.
	 *
	 * @param ASI_Query_Analyzer $analyzer Analizador del contexto.
	 * @param ASI_Lexicon        $lexicon  Idioma.
	 * @param string            $text     Pregunta.
	 * @return string
	 */
	public static function content_key( ASI_Query_Analyzer $analyzer, ASI_Lexicon $lexicon, $text ) {
		$query = $analyzer->analyze( $text );
		$words = array_values( array_unique( array_filter( explode( ' ', $query['content'] ) ) ) );
		if ( ! $words ) {
			return $lexicon->query_key( $text );
		}
		sort( $words );
		return substr( implode( ' ', $words ), 0, 190 );
	}

	/**
	 * Respuesta enseñada desde el panel para esta forma de preguntar. null si no hay.
	 *
	 * @param string $message Mensaje.
	 * @return array|null
	 */
	private function learned_flow( $message ) {
		$key = self::content_key( $this->analyzer, $this->lexicon, $message );
		if ( '' === $key || empty( $this->context['learned'][ $key ] ) ) {
			return null;
		}
		$rows = $this->repository->rows( $this->context['lang'], array_slice( (array) $this->context['learned'][ $key ], 0, 8 ) );
		if ( ! $rows ) {
			return null;
		}
		$catalog = array();
		$others  = array();
		foreach ( $rows as $row ) {
			if ( 'catalog' === $row['role'] ) {
				$catalog[] = $row;
			} else {
				$others[] = $row;
			}
		}
		$quote = array();
		if ( ! $catalog && $others ) {
			$details = $this->details_of( (int) $others[0]['post_id'] );
			$quote   = ASI_Extractor::best( $details['text'], explode( ' ', $key ), array(), 2 );
		}
		$ids = array_map( 'intval', array_column( $rows, 'post_id' ) );
		return array(
			'kind'        => 'results',
			'text'        => $this->responder->say( 'learned' ),
			'quote'       => $quote,
			'followup'    => $catalog ? $this->responder->say( 1 === count( $catalog ) ? 'closing_single' : 'closing' ) : '',
			'cards'       => $this->cards( $catalog ),
			'links'       => $this->links( $others ),
			'suggestions' => array(),
			'more'        => false,
			'state'       => array( 'q' => '', 'shown' => $ids, 'results' => $ids, 'focus' => 1 === count( $catalog ) ? (int) $catalog[0]['post_id'] : 0 ),
		);
	}

	/**
	 * Respuesta a una pregunta informativa (contacto, ubicaciones, horarios…).
	 */
	private function info( $intent, array $query = array(), $normalized = '' ) {
		$page = $this->info_page( $intent );
		if ( ! $page && in_array( $intent, array( 'location', 'hours', 'quote', 'human' ), true ) ) {
			$page = $this->info_page( 'contact' );
		}

		// Ubicaciones: la página general; una sucursal concreta si la nombra; todas si pide el listado.
		if ( in_array( $intent, array( 'location', 'hours' ), true ) && $this->context['location_types'] ) {
			$all      = $this->repository->by_post_types( $this->context['lang'], $this->context['location_types'], 50 );
			$place    = $this->place_words( $query );
			if ( $place ) {
				$matches = array_values(
					array_filter(
						$all,
						function ( $row ) use ( $place ) {
							return $this->row_mentions_all( $row, $place );
						}
					)
				);
				if ( $matches ) {
					return $this->places_response( 'info.location_specific', $matches, $page );
				}
				if ( $all ) {
					return $this->places_response( 'info.location_none', $all, $page, array( 'query' => $this->place_label( $query, $place ) ) );
				}
			}
			if ( $all && ( ! $page || '' !== ASI_Lexicon::find_phrase( $normalized, $this->lexicon->get_list( 'list_words' ) ) ) ) {
				return $this->places_response( 'info.location_list', $all, $page );
			}
		}

		$links = $page ? array( $page ) : array();

		if ( $links ) {
			// La frase de la página que responde ("Atendemos de lunes a sábado…"), además del enlace.
			$quote = $this->page_quote( $page, $intent, $query );
			return array(
				'kind'        => 'info',
				'text'        => $this->responder->say( ( $quote ? 'info_quote.' : 'info.' ) . $intent ),
				'quote'       => $quote,
				'followup'    => $quote ? $this->responder->say( 'info_quote_more' ) : '',
				'cards'       => array(),
				'links'       => $links,
				'suggestions' => array(),
				'more'        => false,
				'state'       => array(),
			);
		}

		$contact = $this->info_page( 'contact' );
		return array(
			'kind'        => 'info',
			'learn'       => 'none',
			'text'        => $this->responder->say( 'info_missing' ),
			'followup'    => '',
			'cards'       => array(),
			'links'       => array( $contact ? $contact : array( 'title' => $this->context['site_name'], 'url' => $this->context['home_url'], 'summary' => '' ) ),
			'suggestions' => array(),
			'more'        => false,
			'state'       => array(),
		);
	}

	/**
	 * Frases de una página informativa que responden la intención (horario, teléfono, misión…).
	 *
	 * @param array  $page   Enlace de la página (con id).
	 * @param string $intent Intención.
	 * @param array  $query  Consulta analizada (sus palabras suman).
	 * @return string[]
	 */
	private function page_quote( array $page, $intent, array $query ) {
		if ( empty( $page['id'] ) ) {
			return array();
		}
		$evidence = $this->lexicon->get_list( 'evidence' );
		$words    = isset( $evidence[ $intent ] ) ? array_map( array( 'ASI_Lexicon', 'normal_form' ), (array) $evidence[ $intent ] ) : array();
		if ( ! $words ) {
			return array();
		}
		if ( ! empty( $query['content'] ) ) {
			$words = array_merge( $words, explode( ' ', $query['content'] ) );
		}
		$details = $this->details_of( (int) $page['id'] );
		return ASI_Extractor::best( $details['text'], array(), $words, 2 );
	}

	/**
	 * Sin resultados: se dice con honestidad y se ofrecen caminos.
	 */
	/**
	 * ¿Conviene preguntar? Solo con opciones a la vista (está a media conversación), sin filtros
	 * claros (presupuesto, atributos) y cuando la búsqueda no encontró nada o salió "parecida" por
	 * una palabra que el catálogo no conoce ("cuoa", "dame eso pues"). Una búsqueda bien escrita que
	 * solo coincide en parte ("rav4 roja") sigue mostrando lo más parecido.
	 */
	private function is_unclear( array $query, array $catalog, array $state, array $options ) {
		if ( ! $state['results'] || $options || ASI_Search::has_hard_filter( $query ) || ! empty( $query['attrs'] ) ) {
			return false;
		}
		if ( ! $catalog['items'] ) {
			return true;
		}
		if ( 'exact' === $catalog['quality'] || ! empty( $catalog['named'] ) ) {
			return false;
		}
		foreach ( explode( ' ', (string) $query['content'] ) as $word ) {
			if ( strlen( $word ) >= 3 && ! ctype_digit( $word ) && ! isset( $this->vocab['tokens'][ $word ] ) && ! $this->lexicon->is_stopword( $word ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * "No estoy seguro de haberte entendido": con botones para lo que suele seguir. Las opciones a la
	 * vista se conservan. Queda en Aprendizaje para enseñarlo desde el panel.
	 */
	private function unclear( array $state ) {
		$chips  = array( $this->responder->say( 'unclear_chip_finance' ) );
		$runner = $this->runner();
		$have   = $runner ? $runner->available() : array();
		if ( isset( $have['quote'] ) ) {
			$chips[] = $this->responder->say( 'unclear_chip_quote' );
		}
		if ( isset( $have['compare'] ) && count( $state['results'] ) >= 2 ) {
			$chips[] = $this->responder->say( 'unclear_chip_compare' );
		}
		$chips[] = $this->responder->say( 'unclear_chip_other' );
		return array(
			'kind'        => 'unclear',
			'learn'       => 'none',
			'text'        => $this->responder->say( 'unclear_visible' ),
			'followup'    => '',
			'cards'       => array(),
			'links'       => array(),
			'suggestions' => $chips,
			'more'        => false,
			'state'       => array(),
		);
	}

	private function none( $text, array $intro ) {
		$intro[]  = $this->responder->say( 'none', array( 'query' => $this->short( $text ) ) );
		$followup = array( $this->responder->say( 'none_followup' ) );
		$links    = array();
		$contact  = $this->info_page( 'contact' );
		if ( $contact ) {
			$links[] = $contact;
		}

		return array(
			'kind'        => 'none',
			'learn'       => 'none',
			'text'        => $this->sentences( $intro ),
			'followup'    => $this->sentences( $followup ),
			'cards'       => array(),
			'links'       => $links,
			'suggestions' => $this->top_categories( 4 ),
			'more'        => false,
			'state'       => array( 'q' => '', 'shown' => array(), 'results' => array() ),
		);
	}

	/**
	 * Saludo, gracias, despedida o ayuda.
	 */
	private function smalltalk( $intent ) {
		$categories = $this->top_categories( 3 );
		$text       = $this->responder->say( $intent, array( 'facets' => $this->responder->join_list( $this->quote_all( $this->lower( $categories ) ) ) ) );

		return array(
			'kind'        => 'smalltalk',
			'text'        => $text,
			'followup'    => '',
			'cards'       => array(),
			'links'       => array(),
			// Con un asesor, el saludo ofrece contar su situación; si no, las categorías del sitio.
			'suggestions' => in_array( $intent, array( 'greeting', 'help' ), true ) ? ( $this->advisor() ? (array) $this->lexicon->get_list( 'profile_chips' ) : $categories ) : array(),
			'more'        => false,
			'state'       => array(),
		);
	}

	/**
	 * Si escribe en otro idioma que el sitio tiene para el mismo país, se le ofrece.
	 */
	private function maybe_language_hint( array $response, $normalized ) {
		$detected = ASI_Lexicon::detect_language( $normalized, $this->context['languages'] );
		if ( '' === $detected || $detected === $this->context['language'] || empty( $this->context['siblings'][ $detected ] ) ) {
			return $response;
		}
		$names                = $this->lexicon->get_list( 'language_names' );
		$name                 = isset( $names[ $detected ] ) ? $names[ $detected ] : $detected;
		$response['followup'] = trim( $response['followup'] . ' ' . $this->responder->say( 'language_hint', array( 'other_language' => $name ) ) );
		$response['links'][]  = array( 'title' => ucfirst( $name ), 'url' => $this->context['siblings'][ $detected ], 'summary' => '' );
		return $response;
	}

	/**
	 * Página informativa del contexto para una intención (la de título más parecido a sus palabras clave).
	 *
	 * @param string $intent Intención.
	 * @return array|null title, url, summary
	 */
	private function info_page( $intent ) {
		if ( array_key_exists( $intent, $this->info_pages ) ) {
			return $this->info_pages[ $intent ];
		}
		$intents  = $this->lexicon->intents();
		$keywords = isset( $intents[ $intent ]['keywords'] ) ? $intents[ $intent ]['keywords'] : array();
		$page     = null;

		// Las palabras clave van en orden de prioridad: "horarios" antes que "contacto".
		// Se prefiere una página; otro contenido informativo (una sucursal) queda de respaldo.
		$fallback = null;
		foreach ( $keywords as $keyword ) {
			$alt    = ASI_Lexicon::normal_form( $keyword );
			$query  = array(
				'groups'     => array( array( 'text' => $alt, 'alts' => array( $alt ), 'kind' => 'word', 'critical' => false, 'label' => '', 'term_kind' => '' ) ),
				'content'    => '',
				'price_max'  => null,
				'price_min'  => null,
				'price_currency' => '',
				'attrs'      => array(),
				'attr_labels' => array(),
				'asks_price' => false,
			);
			$result = $this->search->search( $this->context['lang'], $query, array( 'roles' => array( 'info' ), 'limit' => 30 ) );
			foreach ( $result['items'] as $row ) {
				// Tiene que coincidir en el título o en los términos, no solo en el texto.
				if ( $row['score'] < 6 ) {
					continue;
				}
				$links = $this->links( array( $row ) );
				if ( 'page' === $row['post_type'] ) {
					$page = $links[0];
					break 2;
				}
				$fallback = $fallback ? $fallback : $links[0];
			}
		}
		$this->info_is_page[ $intent ] = (bool) $page;
		$page                          = $page ? $page : $fallback;

		$this->info_pages[ $intent ] = $page;
		return $page;
	}

	/**
	 * Respuesta con ubicaciones como tarjetas de lugar (cuadrícula) y la página general como enlace.
	 *
	 * @param string     $key  Tipo de respuesta.
	 * @param array[]    $rows Ubicaciones.
	 * @param array|null $page Página de tiendas/ubicaciones.
	 * @param array      $vars Marcadores.
	 * @return array
	 */
	private function places_response( $key, array $rows, $page, array $vars = array() ) {
		$places = array();
		foreach ( array_slice( $rows, 0, 12 ) as $row ) {
			$places[] = array(
				'title'   => $row['card']['title'],
				'url'     => $this->place_url( $row ),
				'summary' => isset( $row['card']['summary'] ) ? $row['card']['summary'] : '',
			);
		}
		return array(
			'kind'        => 'info',
			'text'        => $this->responder->say( $key, $vars ),
			'followup'    => $page ? $this->responder->say( 'info.location_page' ) : '',
			'cards'       => array(),
			'places'      => $places,
			'links'       => $page ? array( $page ) : array(),
			'suggestions' => array(),
			'more'        => false,
			'state'       => array(),
		);
	}

	/**
	 * Enlace de una ubicación. Las ubicaciones casi nunca tienen una página propia con diseño, así que
	 * se enlaza a la página donde están todas (la del mapa) con esa ubicación marcada: ?asi_place={id}.
	 * Si el sitio no tiene esa página, se usa el enlace propio de la ubicación.
	 *
	 * @param array $row Fila de la ubicación.
	 * @return string
	 */
	private function place_url( array $row ) {
		$map = $this->info_page( 'location' );
		if ( ! $map || empty( $this->info_is_page['location'] ) || empty( $row['post_id'] ) ) {
			return $row['card']['url'];
		}
		$url  = $map['url'];
		$hash = '';
		if ( false !== strpos( $url, '#' ) ) {
			list( $url, $hash ) = explode( '#', $url, 2 );
			$hash               = '#' . $hash;
		}
		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . 'asi_place=' . (int) $row['post_id'] . $hash;
	}

	/**
	 * ¿Pregunta por el propio sitio? ("¿qué es La Garza?", "cuéntame de Grupo Delta").
	 * Tiene que nombrar el sitio (una palabra propia de su nombre, no "grupo" ni "tienda"), traer una
	 * palabra de pregunta y nada más: "shampoo La Garza" es una búsqueda, no una pregunta por la empresa.
	 *
	 * @param string $normalized Mensaje normalizado.
	 * @return bool
	 */
	private function asks_about_site( $normalized ) {
		$site = ASI_Normalizer::normalize( (string) $this->context['site_name'] );
		if ( '' === $site || '' === $normalized ) {
			return false;
		}
		$about   = array_flip( array_map( array( 'ASI_Normalizer', 'normalize' ), (array) $this->lexicon->get_list( 'about_site_words' ) ) );
		$generic = array_flip( array_map( array( 'ASI_Normalizer', 'normalize' ), (array) $this->lexicon->get_list( 'company_words' ) ) );
		$own     = array();
		$name    = array();
		foreach ( explode( ' ', $site ) as $token ) {
			$name[ $token ] = true;
			if ( strlen( $token ) >= 3 && ! isset( $generic[ $token ] ) && ! $this->lexicon->is_stopword( $token ) ) {
				$own[ $token ] = true;
			}
		}

		$words = explode( ' ', $normalized );
		if ( ! $own || ! array_intersect_key( $own, array_flip( $words ) ) ) {
			return false;
		}
		$question = false;
		foreach ( $words as $word ) {
			if ( isset( $name[ $word ] ) || isset( $generic[ $word ] ) ) {
				continue;
			}
			if ( isset( $about[ $word ] ) ) {
				$question = true;
				continue;
			}
			if ( ! $this->lexicon->is_stopword( $word ) ) {
				return false;
			}
		}
		return $question;
	}

	/**
	 * Palabras que nombran un lugar concreto ("mixco", "zona 10"), sin las genéricas ("tienda", "sucursal").
	 *
	 * @param array $query Consulta analizada (sin el ancla de la intención).
	 * @return string[]
	 */
	private function place_words( array $query ) {
		if ( empty( $query['groups'] ) ) {
			return array();
		}
		$generic = array();
		foreach ( (array) $this->lexicon->get_list( 'place_words' ) as $word ) {
			$generic[ ASI_Lexicon::normal_form( $word ) ] = true;
		}
		$words = array();
		foreach ( $query['groups'] as $group ) {
			foreach ( explode( ' ', $group['text'] ) as $word ) {
				if ( '' !== $word && ! isset( $generic[ $word ] ) ) {
					$words[] = $word;
				}
			}
		}
		return array_values( array_unique( $words ) );
	}

	/**
	 * El lugar tal como lo escribió el usuario ("Petén"), para nombrarlo en la respuesta.
	 */
	private function place_label( array $query, array $place ) {
		$labels = array();
		foreach ( $query['groups'] as $group ) {
			if ( array_intersect( explode( ' ', $group['text'] ), $place ) ) {
				$labels[] = $group['label'];
			}
		}
		$label = $labels ? implode( ' ', $labels ) : implode( ' ', $place );
		return function_exists( 'mb_convert_case' ) ? mb_convert_case( $label, MB_CASE_TITLE, 'UTF-8' ) : ucwords( $label );
	}

	/**
	 * ¿La ubicación menciona todas las palabras (en su nombre, términos o dirección)?
	 */
	private function row_mentions_all( array $row, array $words ) {
		$text = ' ' . $row['title_norm'] . ' ' . str_replace( '|', ' ', $row['terms_norm'] ) . ' ' . ASI_Lexicon::normal_form( isset( $row['card']['summary'] ) ? $row['card']['summary'] : '' ) . ' ';
		foreach ( $words as $word ) {
			if ( false === strpos( $text, ' ' . $word . ' ' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * ¿La fila es de un tipo de contenido de ubicaciones?
	 */
	private function is_location_row( array $row ) {
		return in_array( $row['post_type'], $this->context['location_types'], true );
	}

	/**
	 * ¿Pide más resultados? ("más", "ver más", "otras opciones").
	 */
	/**
	 * ¿El cliente cierra la conversación? Solo si todo el mensaje son palabras de cierre
	 * ("solo eso gracias", "listo, eso es todo, bye"), nunca si además pide algo ("gracias, ¿y
	 * en rojo?"). Se despide por su nombre y, si pidió una cotización, le recuerda que un asesor
	 * lo contactará.
	 */
	private function closing_flow( $normalized, array $state ) {
		$closing = array_map( array( 'ASI_Normalizer', 'normalize' ), (array) $this->lexicon->get_list( 'closing_words' ) );
		$strong  = (array) $this->lexicon->get_list( 'closing_strong' );
		$words   = array_values( array_filter( explode( ' ', $normalized ), 'strlen' ) );
		if ( ! $words || count( $words ) > 10 || '' === ASI_Lexicon::find_phrase( $normalized, $strong ) ) {
			return null;
		}
		foreach ( $words as $word ) {
			if ( ! in_array( $word, $closing, true ) && ! $this->lexicon->is_stopword( $word ) ) {
				return null;
			}
		}
		$name   = ! empty( $state['customer']['name'] ) ? strtok( $state['customer']['name'], ' ' ) : '';
		$quoted = ! empty( $state['quoted'] );
		$text   = array( $this->responder->say( '' !== $name ? 'closing_bye_name' : 'closing_bye', array( 'name' => $name ) ) );
		if ( $quoted ) {
			$text[] = $this->responder->say( 'closing_advisor', array( 'site' => $this->context['site_name'] ) );
		}
		$text[] = $this->responder->say( 'closing_door' );
		return array(
			'kind'        => 'smalltalk',
			'text'        => $this->sentences( $text ),
			'followup'    => '',
			'cards'       => array(),
			'links'       => array(),
			'suggestions' => array(),
			'more'        => false,
			'state'       => array( 'pending' => array(), 'offer' => array(), 'q' => '' ),
		);
	}

	private function is_more_request( $normalized ) {
		// Sin las palabras de pedido: "puedes mostrarme más opciones porfavor" → "mas opciones".
		$request    = array_map( array( 'ASI_Normalizer', 'normalize' ), (array) $this->lexicon->get_list( 'request_words' ) );
		$kept       = array_values( array_filter( explode( ' ', $normalized ), static function ( $w ) use ( $request ) {
			return '' !== $w && ! in_array( $w, $request, true );
		} ) );
		$normalized = $kept ? implode( ' ', $kept ) : $normalized;
		$more = $this->lexicon->get_list( 'more' );
		if ( in_array( $normalized, array_map( array( 'ASI_Normalizer', 'normalize' ), $more ), true ) ) {
			return true;
		}
		return count( explode( ' ', $normalized ) ) <= 4 && '' !== ASI_Lexicon::find_phrase( $normalized, $more )
			&& ! $this->is_searchable( $this->analyzer->analyze( $normalized ) );
	}

	/**
	 * ¿Se refiere a un resultado mostrado? Devuelve su ID, 0 si es ambiguo, o null si no es una referencia.
	 */
	private function detect_reference( $normalized, array $state ) {
		if ( ! $state['results'] ) {
			return null;
		}
		$tokens   = explode( ' ', $normalized );
		$ordinals = $this->lexicon->get_list( 'ordinals' );
		$markers  = $this->lexicon->get_list( 'reference_markers' );

		if ( count( $tokens ) <= 4 ) {
			foreach ( $tokens as $position => $token ) {
				if ( ! isset( $ordinals[ $token ] ) ) {
					continue;
				}
				// Un número suelto solo cuenta si va solo o precedido de "el", "la", "opción"…
				$previous = $position > 0 ? $tokens[ $position - 1 ] : '';
				if ( ctype_digit( $token ) && count( $tokens ) > 1 && ! in_array( $previous, $markers, true ) ) {
					continue;
				}
				$index = (int) $ordinals[ $token ];
				$index = -1 === $index ? count( $state['results'] ) - 1 : $index - 1;
				return isset( $state['results'][ $index ] ) ? (int) $state['results'][ $index ] : 0;
			}
		}

		if ( in_array( $normalized, array_map( array( 'ASI_Normalizer', 'normalize' ), $this->lexicon->get_list( 'reference_alone' ) ), true ) ) {
			return 1 === count( $state['results'] ) ? (int) $state['results'][0] : 0;
		}
		return null;
	}

	/**
	 * ¿El mensaje afina la búsqueda anterior en vez de empezar una nueva?
	 * Sí cuando no nombra una categoría nueva y empieza con "solo", "y", "pero"… o solo trae marcas/distintivos.
	 */
	private function is_refinement( $normalized, array $query, array $state ) {
		if ( '' === $state['q'] || ! $query['groups'] || count( $query['groups'] ) > 3 ) {
			return false;
		}
		foreach ( $query['groups'] as $group ) {
			if ( 'category' === $group['term_kind'] || 'code' === $group['kind'] ) {
				return false;
			}
		}
		$first_words = implode( ' ', array_slice( explode( ' ', $normalized ), 0, 2 ) );
		foreach ( $this->lexicon->get_list( 'refine_markers' ) as $marker ) {
			if ( 0 === strpos( $first_words . ' ', ASI_Normalizer::normalize( $marker ) . ' ' ) ) {
				return true;
			}
		}
		foreach ( $query['groups'] as $group ) {
			if ( ! in_array( $group['term_kind'], array( 'brand', 'flag', 'attribute', 'tag' ), true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * ¿Queda algo que buscar después de quitar saludos y palabras de petición?
	 */
	private function is_searchable( array $query ) {
		return ! empty( $query['groups'] ) || ASI_Search::has_hard_filter( $query );
	}

	/**
	 * El presupuesto como lo dijo el cliente: "entre $ 60,000 y $ 70,000", "hasta Q 300,000".
	 */
	private function budget_text( array $query ) {
		$money = ASI_Money::config( (array) $this->context['money'] );
		$iso   = ! empty( $query['price_currency'] ) ? $query['price_currency'] : $money['base'];
		$fmt   = function ( $amount ) use ( $iso ) {
			return ASI_Money::format( $amount, $iso );
		};
		if ( null !== $query['price_min'] && null !== $query['price_max'] ) {
			return $this->responder->say( 'budget_between', array( 'min' => $fmt( $query['price_min'] ), 'max' => $fmt( $query['price_max'] ) ) );
		}
		if ( null !== $query['price_max'] ) {
			return $this->responder->say( 'budget_max', array( 'max' => $fmt( $query['price_max'] ) ) );
		}
		return $this->responder->say( 'budget_min', array( 'min' => $fmt( $query['price_min'] ) ) );
	}

	private function has_critical( array $query ) {
		foreach ( $query['groups'] as $group ) {
			if ( $group['critical'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Muchos resultados repartidos en varias líneas (o marcas): se pregunta cuál, como haría un vendedor.
	 *
	 * @return array [opciones, clave de respuesta] o [[], '']
	 */
	private function clarify_facets( array $result, array $query ) {
		if ( 'exact' !== $result['quality'] || $result['total'] < 8 ) {
			return array( array(), '' );
		}
		$facets = array();
		foreach ( $result['facets'] as $facet => $count ) {
			if ( $count >= 2 && $count < $result['total'] && ! $this->facet_in_query( $facet, $query ) && ! $this->is_too_general( $facet ) ) {
				$facets[] = $facet;
			}
		}
		if ( count( $facets ) >= 2 ) {
			return array( array_slice( $facets, 0, 3 ), 'clarify' );
		}
		$brands = array();
		foreach ( isset( $result['brands'] ) ? $result['brands'] : array() as $brand => $count ) {
			if ( $count >= 2 && $count < $result['total'] && ! $this->facet_in_query( $brand, $query ) ) {
				$brands[] = $brand;
			}
		}
		return count( $brands ) >= 2 ? array( array_slice( $brands, 0, 3 ), 'clarify_brand' ) : array( array(), '' );
	}

	/**
	 * Otras líneas presentes en los resultados, como sugerencia.
	 */
	private function related_facets( array $result, array $query ) {
		$facets = array();
		foreach ( array_keys( $result['facets'] ) as $facet ) {
			if ( ! $this->facet_in_query( $facet, $query ) && ! $this->is_too_general( $facet ) ) {
				$facets[] = $facet;
			}
		}
		return array_slice( $facets, 0, 3 );
	}

	/**
	 * Una categoría que tiene casi todo el sitio ("Venta") no sirve para elegir.
	 */
	private function is_too_general( $facet ) {
		$normal = ASI_Lexicon::normal_form( $facet );
		$docs   = ! empty( $this->vocab['catalog'] ) ? (int) $this->vocab['catalog'] : (int) $this->vocab['docs'];
		return isset( $this->vocab['phrases'][ $normal ] ) && $docs >= 5 && $this->vocab['phrases'][ $normal ]['df'] / $docs > 0.5;
	}

	private function facet_in_query( $facet, array $query ) {
		$normal = ASI_Lexicon::normal_form( $facet );
		foreach ( $query['groups'] as $group ) {
			if ( in_array( $normal, $group['alts'], true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Categorías con más contenido del contexto.
	 *
	 * @param int $limit Cuántas.
	 * @return string[]
	 */
	private function top_categories( $limit ) {
		$categories = array();
		foreach ( $this->vocab['phrases'] as $info ) {
			if ( 'category' === $info['kind'] && ! $this->is_too_general( $info['label'] ) ) {
				$categories[ $info['label'] ] = (int) $info['df'];
			}
		}
		arsort( $categories );
		return array_slice( array_keys( $categories ), 0, $limit );
	}

	private function any_price( array $rows ) {
		foreach ( $rows as $row ) {
			if ( ! empty( $row['card']['price'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Tarjetas para el navegador.
	 */
	private function cards( array $rows, $query = null ) {
		$cards = array();
		foreach ( $rows as $row ) {
			$card       = is_array( $row['card'] ) ? $row['card'] : array();
			$card['id'] = (int) $row['post_id'];
			// Se muestra la categoría que coincide con lo que buscó, si el producto tiene varias.
			if ( $query && ! empty( $card['categories'] ) ) {
				foreach ( $card['categories'] as $category ) {
					if ( $this->facet_in_query( $category, $query ) ) {
						$card['category'] = $category;
						break;
					}
				}
			}
			unset( $card['categories'] );
			if ( is_array( $this->context['card_flags'] ) && isset( $card['flags'] ) ) {
				$shown = array();
				foreach ( (array) $card['flags'] as $flag ) {
					if ( isset( $this->context['card_flags'][ $flag ] ) ) {
						$shown[] = $this->context['card_flags'][ $flag ];
					}
				}
				$card['flags'] = array_values( array_unique( $shown ) );
			}
			$cards[] = $this->money_card( $card );
		}
		return $cards;
	}

	/**
	 * Precio de la tarjeta con su símbolo y, si está en otra moneda, el equivalente en la base
	 * ("$ 61,900" · "≈ Q 476,630").
	 *
	 * @param array $card Tarjeta.
	 * @return array
	 */
	private function money_card( array $card ) {
		$money = ASI_Money::config( (array) $this->context['money'] );
		$price = ASI_Money::card_price( $card, $money );
		unset( $card['currency_raw'] );
		if ( null === $price['amount'] ) {
			$card['price'] = '';
			return $card;
		}
		// Si el sitio guarda el número solo, se le pone el símbolo; si ya trae formato, se respeta.
		if ( preg_match( '/^[0-9.,\s]+$/', trim( (string) $card['price'] ) ) && '' !== $price['currency'] ) {
			$card['price'] = ASI_Money::format( $price['amount'], $price['currency'] );
		}
		$card['currency'] = $price['currency'];
		if ( '' !== $money['base'] && $price['currency'] !== $money['base'] && null !== $price['base'] ) {
			$card['price_alt'] = '≈ ' . ASI_Money::format( round( $price['base'] ), $money['base'] );
		}
		return $card;
	}

	/**
	 * Precio de una fila en la moneda base (0 si no tiene o no se puede convertir). Para ordenar.
	 *
	 * @param array $row Fila.
	 * @return float
	 */
	private function base_price( array $row ) {
		$price = ASI_Money::card_price( isset( $row['card'] ) ? (array) $row['card'] : array(), (array) $this->context['money'] );
		return null === $price['base'] ? 0.0 : (float) $price['base'];
	}

	private function links( array $rows ) {
		$links = array();
		foreach ( $rows as $row ) {
			$links[] = array(
				'id'      => (int) $row['post_id'],
				'title'   => $row['card']['title'],
				'url'     => $row['card']['url'],
				'summary' => isset( $row['card']['summary'] ) ? $row['card']['summary'] : '',
			);
		}
		return $links;
	}

	private function sentences( array $parts ) {
		return trim( implode( ' ', array_filter( array_map( 'trim', $parts ) ) ) );
	}

	private function short( $text, $max = 60 ) {
		$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $max ) {
			return rtrim( mb_substr( $text, 0, $max - 1 ) ) . '…';
		}
		return $text;
	}

	private function lower( array $items ) {
		return array_map(
			function ( $item ) {
				return function_exists( 'mb_strtolower' ) ? mb_strtolower( $item, 'UTF-8' ) : strtolower( $item );
			},
			$items
		);
	}

	private function quote_all( array $items ) {
		return array_map(
			function ( $item ) {
				return '“' . $item . '”';
			},
			$items
		);
	}

	/**
	 * Estado enviado por el navegador, validado: nunca se confía en su forma.
	 */
	/**
	 * Perfil del cliente que viene del navegador: plano, chico y solo con valores simples (lo define
	 * el pack; el core no confía en su forma).
	 *
	 * @param mixed $profile Perfil.
	 * @return array
	 */
	private static function clean_profile( $profile ) {
		$clean = array();
		if ( ! is_array( $profile ) ) {
			return $clean;
		}
		foreach ( array_slice( $profile, 0, 20, true ) as $key => $value ) {
			if ( ! is_string( $key ) || ! preg_match( '/^[a-z_]{1,20}$/', $key ) ) {
				continue;
			}
			if ( is_int( $value ) || is_float( $value ) ) {
				$clean[ $key ] = $value;
			} elseif ( is_string( $value ) ) {
				$clean[ $key ] = substr( $value, 0, 30 );
			} elseif ( is_bool( $value ) ) {
				$clean[ $key ] = $value;
			} elseif ( is_array( $value ) ) {
				$clean[ $key ] = array_slice( array_values( array_filter( $value, 'is_scalar' ) ), 0, 10 );
			}
		}
		return $clean;
	}

	private static function clean_action( $action, $ints ) {
		if ( ! is_array( $action ) || empty( $action['action'] ) || ! is_string( $action['action'] ) || ! preg_match( '/^[a-z_]{2,20}$/', $action['action'] ) ) {
			return array();
		}
		return array(
			'action' => $action['action'],
			'ids'    => $ints( isset( $action['ids'] ) ? $action['ids'] : array(), 8 ),
			'with'   => isset( $action['with'] ) && 'finance' === $action['with'] ? 'finance' : '',
			'fin'    => isset( $action['fin'] ) && is_string( $action['fin'] ) ? substr( preg_replace( '/[^a-z0-9 %.,$]/', '', strtolower( $action['fin'] ) ), 0, 60 ) : '',
		);
	}

	/**
	 * Enganche y plazo recordados. Son solo para calcular la cuota aproximada: el servidor usa el precio
	 * del sitio y las reglas de enganche mínimo y máximo, así que un valor raro no cambia ningún monto real.
	 */
	private static function clean_fin( $fin ) {
		$fin = is_array( $fin ) ? $fin : array();
		$out = array();
		if ( isset( $fin['pct'] ) && is_numeric( $fin['pct'] ) && $fin['pct'] > 0 && $fin['pct'] < 100 ) {
			$out['pct'] = round( (float) $fin['pct'], 1 );
		} elseif ( isset( $fin['amt'] ) && is_numeric( $fin['amt'] ) && $fin['amt'] > 0 && $fin['amt'] < 1e9 ) {
			$out['amt'] = (int) $fin['amt'];
			if ( isset( $fin['cur'] ) && is_string( $fin['cur'] ) && preg_match( '/^[A-Z]{3}$/', $fin['cur'] ) ) {
				$out['cur'] = $fin['cur'];
			}
		}
		if ( isset( $fin['term'] ) && is_numeric( $fin['term'] ) && $fin['term'] >= 6 && $fin['term'] <= 120 ) {
			$out['term'] = (int) $fin['term'];
		}
		return $out;
	}

	private static function clean_customer_state( $customer ) {
		$customer = is_array( $customer ) ? $customer : array();
		$get      = static function ( $key, $max ) use ( $customer ) {
			return isset( $customer[ $key ] ) && is_string( $customer[ $key ] ) ? trim( substr( strip_tags( $customer[ $key ] ), 0, $max ) ) : '';
		};
		$email = $get( 'email', 120 );
		return array(
			'name'  => $get( 'name', 60 ),
			'email' => preg_match( '/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i', $email ) ? $email : '',
			'phone' => preg_replace( '/[^0-9+ \-]/', '', $get( 'phone', 20 ) ),
		);
	}

	private function clean_state( array $state ) {
		$ints = function ( $list, $max ) {
			return array_slice( array_values( array_filter( array_map( 'intval', is_array( $list ) ? $list : array() ) ) ), -$max );
		};
		$clean = array(
			'q'       => isset( $state['q'] ) && is_string( $state['q'] ) ? $this->short( $state['q'], 200 ) : '',
			'shown'   => $ints( isset( $state['shown'] ) ? $state['shown'] : array(), self::MAX_SHOWN ),
			'results' => $ints( isset( $state['results'] ) ? $state['results'] : array(), 8 ),
			'focus'   => isset( $state['focus'] ) ? max( 0, (int) $state['focus'] ) : 0,
			'recent'  => array_slice( array_values( array_filter( isset( $state['recent'] ) && is_array( $state['recent'] ) ? $state['recent'] : array(), 'is_string' ) ), -ASI_Responder::MEMORY ),
			'turns'   => isset( $state['turns'] ) ? max( 0, (int) $state['turns'] ) : 0,
			'lang'    => isset( $state['lang'] ) && is_string( $state['lang'] ) ? $state['lang'] : '',
			'profile' => self::clean_profile( isset( $state['profile'] ) ? $state['profile'] : array() ),
			'asked'   => array_slice( array_values( array_filter( isset( $state['asked'] ) && is_array( $state['asked'] ) ? $state['asked'] : array(), 'is_string' ) ), -10 ),
			'pending'  => self::clean_action( isset( $state['pending'] ) ? $state['pending'] : array(), $ints ),
			'offer'    => self::clean_action( isset( $state['offer'] ) ? $state['offer'] : array(), $ints ),
			'customer' => self::clean_customer_state( isset( $state['customer'] ) ? $state['customer'] : array() ),
			'selected' => $ints( isset( $state['selected'] ) ? $state['selected'] : array(), 8 ),
			'quoted'   => $ints( isset( $state['quoted'] ) ? $state['quoted'] : array(), 20 ),
			'last'     => isset( $state['last'] ) && in_array( $state['last'], array( 'quote', 'compare', 'finance' ), true ) ? $state['last'] : '',
			'awaiting' => isset( $state['awaiting'] ) && 'compare' === $state['awaiting'] ? 'compare' : '',
			'previewed' => self::clean_action( isset( $state['previewed'] ) ? $state['previewed'] : array(), $ints ),
			'fin'       => self::clean_fin( isset( $state['fin'] ) ? $state['fin'] : array() ),
			'fin_ids'   => $ints( isset( $state['fin_ids'] ) ? $state['fin_ids'] : array(), 8 ),
		);
		// Otra página de idioma o país: la conversación empieza de cero.
		if ( '' !== $clean['lang'] && $clean['lang'] !== $this->context['lang'] ) {
			$clean = array( 'q' => '', 'shown' => array(), 'results' => array(), 'focus' => 0, 'recent' => array(), 'turns' => 0, 'lang' => '', 'profile' => array(), 'asked' => array(), 'pending' => array(), 'offer' => array(), 'customer' => $clean['customer'], 'selected' => array(), 'quoted' => array(), 'last' => '', 'awaiting' => '', 'previewed' => array(), 'fin' => array(), 'fin_ids' => array() );
		}
		return $clean;
	}
}
