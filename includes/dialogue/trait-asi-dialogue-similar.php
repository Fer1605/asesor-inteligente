<?php
/**
 * "Busco algo parecido al Frontier": describe la referencia, recomienda las más parecidas de otras
 * marcas con el porqué y sus precios, y ofrece compararlas.
 *
 * La referencia sale del catálogo (datos y precio reales) o, si el sitio no la tiene, de la tabla
 * de modelos del pack. El criterio de parecido lo pone un pack que implementa ASI_Similarity.
 *
 * @package AsesorInteligente
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'ASI_CLI_TESTS' ) ) {
	exit;
}

trait ASI_Dialogue_Similar {

	/** Parecido mínimo para recomendar algo como "parecido". */
	private static $min_similarity = 0.45;

	private function similarity_pack() {
		foreach ( (array) $this->context['packs'] as $pack ) {
			if ( $pack instanceof ASI_Similarity ) {
				return $pack;
			}
		}
		return null;
	}

	private function similar_flow( $message, $normalized, array $state ) {
		$pack = $this->similarity_pack();
		if ( ! $pack ) {
			return null;
		}
		$markers = (array) $this->lexicon->get_list( 'similar_markers' );
		$marker  = ASI_Lexicon::find_phrase( $normalized, $markers );
		if ( '' === $marker ) {
			return null;
		}
		$strong = false;
		foreach ( (array) $this->lexicon->get_list( 'similar_strong' ) as $stem ) {
			$strong = $strong || false !== strpos( $marker, $stem );
		}

		// Lo que viene después de "parecido al": el nombre del modelo, hasta la siguiente idea.
		$after  = trim( substr( ' ' . $normalized . ' ', strpos( ' ' . $normalized . ' ', ' ' . $marker . ' ' ) + strlen( $marker ) + 1 ) );
		$tokens = array();
		$stops  = (array) $this->lexicon->get_list( 'similar_stops' );
		foreach ( explode( ' ', $after ) as $token ) {
			if ( '' === $token ) {
				continue;
			}
			if ( in_array( $token, $stops, true ) || count( $tokens ) >= 4 ) {
				break;
			}
			if ( ! $tokens && $this->lexicon->is_stopword( $token ) ) {
				continue; // "al", "la", "un".
			}
			$tokens[] = $token;
		}
		$name_text = implode( ' ', $tokens );
		if ( '' === $name_text ) {
			return null;
		}

		$model    = $pack->reference_model( $name_text, $this->context['language'] );
		$ref_rows = $this->reference_rows( $name_text );
		if ( ! $ref_rows && ! $model ) {
			return $strong ? $this->similar_unknown( $name_text, $state ) : null;
		}

		$money     = ASI_Money::config( (array) $this->context['money'] );
		$reference = $this->reference_attrs( $ref_rows, $model ? $model['attrs'] : array(), $money );
		$name      = $model ? $model['name'] : self::title_case( self::model_key( $ref_rows[0] ) );
		$ref_brand = ASI_Lexicon::normal_form( $model ? $model['brand'] : (string) $ref_rows[0]['card']['brand'] );

		// "Parecido pero más barato".
		$query = $this->analyzer->analyze( $message );
		if ( ASI_Lexicon::find_phrase( $normalized, (array) $this->lexicon->get_list( 'cheapest_markers' ) ) && isset( $reference['price_base'] ) ) {
			$query['price_max']      = round( $reference['price_base'] * 0.95 );
			$query['price_min']      = null;
			$query['price_currency'] = $money['base'];
		}

		$family = in_array( $reference['body'], array( 'sedan', 'hatchback', 'car', 'coupe' ), true ) ? array( 'sedan', 'hatchback', 'car', 'coupe' ) : array( $reference['body'] );
		$search = $query;
		$search['groups'] = array();
		$search['attrs']  = array( 'body' => array( 'in' => $family ) );
		$pool   = $this->search->search( $this->context['lang'], $search, array( 'roles' => array( 'catalog' ), 'limit' => 400 ) )['items'];

		$ref_ids    = array_map( 'intval', array_column( $ref_rows, 'post_id' ) );
		$ref_models = array_map( array( __CLASS__, 'model_key' ), $ref_rows );
		$scored     = array();
		foreach ( $pool as $row ) {
			if ( in_array( (int) $row['post_id'], $ref_ids, true ) || in_array( self::model_key( $row ), $ref_models, true ) ) {
				continue;
			}
			$attrs = isset( $row['card']['attrs'] ) ? (array) $row['card']['attrs'] : array();
			$price = ASI_Money::card_price( (array) $row['card'], $money );
			if ( null !== $price['base'] ) {
				$attrs['price_base'] = $price['base'];
			}
			$row['similar'] = $pack->similarity( $reference, $attrs );
			$row['sattrs']  = $attrs;
			if ( $row['similar'] >= self::$min_similarity ) {
				$scored[] = $row;
			}
		}
		usort(
			$scored,
			static function ( $a, $b ) {
				return $a['similar'] === $b['similar'] ? 0 : ( $a['similar'] < $b['similar'] ? 1 : -1 );
			}
		);

		// La mejor de cada marca, primero las otras marcas ("te recomiendo estas dos marcas").
		$picked = array();
		$brands = array();
		foreach ( array( false, true ) as $same_brand_ok ) {
			foreach ( $scored as $row ) {
				$brand = ASI_Lexicon::normal_form( isset( $row['card']['brand'] ) ? (string) $row['card']['brand'] : '' );
				$same  = '' !== $brand && $brand === $ref_brand;
				if ( count( $picked ) >= 3 || isset( $brands[ $brand ] ) || ( $same && ! $same_brand_ok ) ) {
					continue;
				}
				$brands[ $brand ] = true;
				$picked[]         = $row;
			}
			if ( count( $picked ) >= 2 ) {
				break;
			}
		}

		$vars = array(
			'ref'      => $name,
			'describe' => $pack->describe( $reference, $this->context['language'] ),
			'traits'   => $model ? $model['traits'] : '',
		);
		$text = array( $this->responder->say( '' !== $vars['traits'] ? 'similar_ref_traits' : 'similar_ref', $vars ) );

		if ( ! $picked ) {
			$text[] = $this->responder->say( 'similar_none', $vars );
			$cards  = $ref_rows ? array( $this->cheapest( $ref_rows ) ) : array();
			if ( $cards ) {
				$text[] = $this->responder->say( 'similar_have_ref', array( 'ref' => $name, 'price' => $this->row_price( $cards[0] ) ) );
			}
			return $this->similar_response( $text, $cards, '', $state, $ref_ids );
		}

		$options = array();
		$prices  = array();
		foreach ( $picked as $row ) {
			$options[] = $row['card']['title'];
			$prices[]  = $this->responder->say( 'similar_price', array( 'title' => $row['card']['title'], 'price' => $this->row_price( $row ) ) );
		}
		$why    = $pack->why_similar( $reference, $picked[0]['sattrs'], $this->context['language'] );
		$text[] = $this->responder->say( '' !== $why ? 'similar_reco_why' : 'similar_reco', array( 'options' => $this->responder->join_list( $options, 'or' ), 'why' => $why ) );
		$text[] = $this->responder->say( 'similar_prices', array( 'prices' => $this->responder->join_list( $prices, 'and' ) ) );

		$cards = $picked;
		if ( $ref_rows ) {
			$ref    = $this->cheapest( $ref_rows );
			$text[] = $this->responder->say( 'similar_have_ref', array( 'ref' => $name, 'price' => $this->row_price( $ref ) ) );
			$cards[] = $ref;
		}
		return $this->similar_response( $text, $cards, $this->responder->say( 'similar_closing' ), $state, array_map( 'intval', array_column( $picked, 'post_id' ) ) );
	}

	/**
	 * Productos del catálogo que son ese modelo: su título contiene todas las palabras que nombró.
	 */
	private function reference_rows( $name_text ) {
		$name = str_replace( array( ' ', '-' ), '', ASI_Lexicon::normal_form( $name_text ) );
		if ( strlen( $name ) < 2 ) {
			return array();
		}
		$rows = array();
		foreach ( $this->repository->catalog( $this->context['lang'], ASI_Search::BROWSE_LIMIT ) as $row ) {
			if ( empty( $row['card']['attrs'] ) ) {
				continue;
			}
			// Con o sin espacios y guiones ("CX-5", "CX5", "cx 5"), y siempre palabras completas:
			// el nombre tiene que ser 1 a 3 palabras seguidas del título ("rio" no está en "patriot").
			$words = array_values( array_filter( explode( ' ', (string) $row['title_norm'] ), 'strlen' ) );
			$count = count( $words );
			for ( $i = 0; $i < $count; $i++ ) {
				$joined = '';
				for ( $j = $i; $j < min( $count, $i + 3 ); $j++ ) {
					$joined .= str_replace( '-', '', $words[ $j ] );
					if ( $joined === $name ) {
						$rows[] = $row;
						continue 3;
					}
				}
			}
		}
		return $rows;
	}

	/**
	 * Atributos de la referencia: lo más común entre sus versiones del catálogo (y su precio
	 * típico); lo que falte, de la tabla del pack.
	 */
	private function reference_attrs( array $rows, array $fallback, array $money ) {
		$attrs  = $fallback;
		$values = array();
		foreach ( $rows as $row ) {
			foreach ( (array) $row['card']['attrs'] as $key => $value ) {
				if ( is_scalar( $value ) && ! in_array( $key, array( 'body_label', 'seats_est' ), true ) ) {
					$values[ $key ][] = $value;
				}
			}
			$price = ASI_Money::card_price( (array) $row['card'], $money );
			if ( null !== $price['base'] ) {
				$values['price_base'][] = $price['base'];
			}
		}
		foreach ( $values as $key => $list ) {
			if ( is_numeric( $list[0] ) && ! in_array( $key, array( 'body', 'traction', 'fuel', 'transmission', 'condition' ), true ) ) {
				sort( $list );
				$attrs[ $key ] = $list[ (int) floor( ( count( $list ) - 1 ) / 2 ) ];
			} else {
				$counts = array_count_values( array_map( 'strval', $list ) );
				arsort( $counts );
				$attrs[ $key ] = key( $counts );
			}
		}
		if ( empty( $attrs['body'] ) ) {
			$attrs['body'] = 'suv';
		}
		return $attrs;
	}

	private function cheapest( array $rows ) {
		$money = ASI_Money::config( (array) $this->context['money'] );
		usort(
			$rows,
			static function ( $a, $b ) use ( $money ) {
				$pa = ASI_Money::card_price( (array) $a['card'], $money )['base'];
				$pb = ASI_Money::card_price( (array) $b['card'], $money )['base'];
				return ( null === $pa ? PHP_FLOAT_MAX : $pa ) <=> ( null === $pb ? PHP_FLOAT_MAX : $pb );
			}
		);
		return $rows[0];
	}

	/** Precio de una fila como se muestra ("Q 349,900", "$ 47,900"). */
	private function row_price( array $row ) {
		$card = $this->money_card( (array) $row['card'] );
		return '' !== (string) $card['price'] ? $card['price'] : $this->responder->say( 'similar_no_price' );
	}

	private function similar_unknown( $name_text, array $state ) {
		return array(
			'kind'        => 'smalltalk',
			'text'        => $this->responder->say( 'similar_unknown', array( 'ref' => $name_text ) ),
			'cards'       => array(),
			'followup'    => '',
			'links'       => array(),
			'suggestions' => (array) $this->lexicon->get_list( 'similar_body_chips' ),
			'more'        => false,
			'state'       => array(),
		);
	}

	private function similar_response( array $text, array $rows, $followup, array $state, array $results ) {
		$ids = array_map( 'intval', array_column( $rows, 'post_id' ) );
		return array(
			'kind'        => 'results',
			'text'        => $this->sentences( $text ),
			'cards'       => $this->cards( $rows ),
			'followup'    => $followup,
			'links'       => array(),
			'suggestions' => $results && count( $results ) >= 2 ? (array) $this->lexicon->get_list( 'similar_chips' ) : array(),
			'more'        => false,
			'state'       => array(
				'q'       => '',
				'shown'   => array_slice( array_merge( $state['shown'], $ids ), -self::MAX_SHOWN ),
				'results' => $results ? $results : $ids,
				'focus'   => 0,
			),
		);
	}

	private static function title_case( $text ) {
		return function_exists( 'mb_convert_case' ) ? mb_convert_case( $text, MB_CASE_TITLE, 'UTF-8' ) : ucwords( $text );
	}
}
