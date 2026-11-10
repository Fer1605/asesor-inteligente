<?php
/**
 * Conversación de asesor: el cliente cuenta su situación ("somos 4, con un niño de 3 años, para la
 * finca") y el chat recomienda qué le conviene, con el porqué, pide el presupuesto si falta y avisa
 * si lo que conviene se pasa del presupuesto.
 *
 * El conocimiento del giro lo pone un pack que implementa ASI_Advisor; aquí solo se conversa.
 *
 * @package AsesorInteligente
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'ASI_CLI_TESTS' ) ) {
	exit;
}

trait ASI_Dialogue_Advice {

	/** Cuántas tarjetas muestra una recomendación (un asesor no muestra 40). */
	private static $advice_cards = 4;

	/**
	 * Primer pack activo que sabe aconsejar.
	 *
	 * @return ASI_Advisor|null
	 */
	private function advisor() {
		foreach ( (array) $this->context['packs'] as $pack ) {
			if ( $pack instanceof ASI_Advisor ) {
				return $pack;
			}
		}
		return null;
	}

	/**
	 * ¿Este mensaje es para el asesor? Si sí, responde; si no, null y sigue la conversación normal.
	 */
	private function advice_flow( $message, array $state ) {
		$advisor = $this->advisor();
		if ( ! $advisor ) {
			return null;
		}
		$language = $this->context['language'];
		$before   = $state['profile'];
		$read     = $advisor->read_profile( $message, $language, $before );
		$profile  = $read['profile'];
		$changed  = $read['changed'];
		$query    = $this->analyzer->analyze( $message );

		// El presupuesto también es parte del perfil.
		if ( ASI_Search::has_price_filter( $query ) ) {
			$budget = array(
				'budget_min'      => $query['price_min'],
				'budget_max'      => $query['price_max'],
				'budget_currency' => (string) $query['price_currency'],
			);
			foreach ( $budget as $key => $value ) {
				if ( ! array_key_exists( $key, $profile ) || $profile[ $key ] !== $value ) {
					$changed[] = 'budget';
				}
				$profile[ $key ] = $value;
			}
		}
		$changed = array_values( array_unique( $changed ) );

		// Habla de su situación aunque no sea nuevo ("no no, somos 4, 2 adultos y 2 niños…"):
		// cuenta lo que dice el mensaje por sí solo, no solo lo que cambió.
		$own        = $advisor->read_profile( $message, $language, array() );
		$about_me   = (bool) array_diff( $changed, array( 'budget' ) ) || (bool) array_intersect( $own['changed'], array( 'people', 'kids', 'adults', 'uses', 'cargo_t' ) );
		$asks       = $advisor->asks_advice( $message, $language );
		// "Quiero ver más opciones", "ninguna me gustó": otra tanda con el mismo perfil.
		if ( ! $about_me && $advisor->can_advise( $before ) && '' !== ASI_Lexicon::find_phrase( ASI_Normalizer::normalize( $message ), (array) $this->lexicon->get_list( 'more_options_words' ) ) ) {
			return $this->recommend( $advisor, $profile, $this->analyzer->analyze( '' ), $state, true );
		}
		$only_soft  = ! array_filter(
			$query['groups'],
			static function ( $group ) {
				return empty( $group['soft'] );
			}
		);
		// "Como 250 mil" después de haber contado su situación: es la respuesta al presupuesto.
		$budget_reply = in_array( 'budget', $changed, true ) && $advisor->can_advise( $before ) && $only_soft;

		if ( ! $about_me && ! $asks && ! $budget_reply ) {
			return null;
		}
		if ( ! $advisor->can_advise( $profile ) ) {
			return $this->advice_ask( 'ask_profile', $profile, $state, array() );
		}
		return $this->recommend( $advisor, $profile, $query, $state );
	}

	/**
	 * Recomienda con lo que se sabe del cliente.
	 */
	private function recommend( ASI_Advisor $advisor, array $profile, array $query, array $state, $more = false ) {
		$language = $this->context['language'];
		$rec      = $advisor->recommend( $profile, $language );
		$group_by = $rec['group_by'];
		$weights  = $rec['weights'];

		// Si además nombró un tipo ("somos 5, busco una pickup"), se respeta lo que pidió.
		if ( isset( $query['attrs'][ $group_by ]['in'] ) ) {
			$asked   = (array) $query['attrs'][ $group_by ]['in'];
			$kept    = array_intersect_key( $weights, array_flip( $asked ) );
			$weights = $kept ? $kept : array_fill_keys( $asked, 1.0 );
		}

		$constraints = ASI_Attributes::merge( $rec['constraints'], (array) $query['attrs'] );
		$constraints[ $group_by ] = array( 'in' => array_keys( $weights ) );

		$search             = $query;
		$search['groups']   = array();
		$search['attrs']    = $constraints;
		$search['exclude']  = isset( $query['exclude'] ) ? $query['exclude'] : array();
		$has_budget         = isset( $profile['budget_max'] ) && null !== $profile['budget_max'] || isset( $profile['budget_min'] ) && null !== $profile['budget_min'];
		$search['price_max']      = $has_budget ? $profile['budget_max'] : null;
		$search['price_min']      = $has_budget ? $profile['budget_min'] : null;
		$search['price_currency'] = $has_budget ? (string) $profile['budget_currency'] : '';

		$exclude = $more ? $state['shown'] : array();
		$found   = $this->search->search( $this->context['lang'], $search, array( 'roles' => array( 'catalog' ), 'limit' => 80, 'exclude' => $exclude ) )['items'];
		$over  = false;
		if ( ! $found && $has_budget ) {
			// Nada de lo que conviene entra en el presupuesto: se muestra lo más accesible y se dice.
			$wide              = $search;
			$wide['price_max'] = null;
			$wide['price_min'] = null;
			$found             = $this->search->search( $this->context['lang'], $wide, array( 'roles' => array( 'catalog' ), 'limit' => 80, 'exclude' => $exclude ) )['items'];
			$over              = (bool) $found;
		}

		$state_out = array( 'profile' => $profile, 'asked' => $state['asked'] );
		if ( ! $found ) {
			return array(
				'kind'        => 'results',
				'text'        => $this->responder->say( 'advice_none', array( 'summary' => $rec['summary'] ) ),
				'cards'       => array(),
				'followup'    => $this->responder->say( 'advice_none_followup' ),
				'links'       => array_filter( array( $this->info_page( 'contact' ) ) ),
				'suggestions' => array(),
				'more'        => false,
				'state'       => array_merge( $state_out, array( 'q' => '', 'results' => array() ) ),
			);
		}

		$picked = $this->pick_advice( $found, $weights, $group_by, $rec['prefer'], $has_budget && ! $over ? $search : null, $over );

		// Qué recomienda, en el orden en que salen las tarjetas.
		$options = array();
		foreach ( $picked as $row ) {
			$value = isset( $row['card']['attrs'][ $group_by ] ) ? $row['card']['attrs'][ $group_by ] : '';
			if ( isset( $rec['names'][ $value ] ) && ! in_array( $rec['names'][ $value ], $options, true ) ) {
				$options[] = $rec['names'][ $value ];
			}
		}
		$options = array_slice( $options, 0, 2 );
		$vars    = array( 'summary' => $rec['summary'], 'options' => $this->responder->join_list( $options, 'or' ) );

		$text   = array( $this->responder->say( $more ? 'advice_more' : ( '' !== $rec['summary'] ? 'advice_open' : 'advice_open_generic' ), $vars ) );
		$text   = array_merge( $text, array_slice( $rec['reasons'], 0, 2 ) );
		$follow = array();
		if ( '' !== $rec['avoid'] ) {
			$text[] = $rec['avoid'];
		}

		$money = ASI_Money::config( (array) $this->context['money'] );
		if ( $over ) {
			$cheapest = ASI_Money::card_price( (array) $picked[0]['card'], $money );
			$follow[] = $this->responder->say(
				'advice_over_budget',
				array(
					'budget' => $this->budget_text( $search ),
					'min'    => ASI_Money::format( round( (float) $cheapest['base'] ), $money['base'] ),
				)
			);
		} elseif ( $has_budget ) {
			$text[] = $this->responder->say( 'advice_in_budget', array( 'budget' => $this->budget_text( $search ) ) );
		}

		$asked       = $state['asked'];
		$suggestions = array();
		if ( ! $has_budget && ! in_array( 'budget', $asked, true ) ) {
			$follow[]    = $this->responder->say( 'ask_budget' );
			$asked[]     = 'budget';
			$suggestions = $this->budget_chips( $found );
		} elseif ( ! $over ) {
			$follow[] = $this->responder->say( 'advice_closing' );
		}

		$ids = array_map( 'intval', array_column( $picked, 'post_id' ) );
		return array(
			'kind'        => 'results',
			'text'        => $this->sentences( $text ),
			'cards'       => $this->cards( $picked ),
			'followup'    => $this->sentences( $follow ),
			'links'       => array(),
			'suggestions' => $suggestions,
			'more'        => false,
			'state'       => array(
				'q'       => '',
				'shown'   => array_slice( array_merge( $state['shown'], $ids ), -self::MAX_SHOWN ),
				'results' => $ids,
				'focus'   => 0,
				'profile' => $profile,
				'asked'   => array_values( array_unique( $asked ) ),
				'offer'   => array( 'action' => 'quote', 'ids' => array_slice( $ids, 0, 4 ) ),
				'pending' => array(),
			),
		);
	}

	/**
	 * Elige pocas tarjetas y variadas: las mejores de cada tipo recomendado, en orden de peso.
	 *
	 * @param array[]    $rows     Candidatos (ya cumplen las restricciones).
	 * @param array      $weights  Valor de agrupación => peso.
	 * @param string     $group_by Atributo de agrupación.
	 * @param array      $prefer   Restricciones blandas.
	 * @param array|null $budget   Consulta con presupuesto (para premiar lo cercano al tope).
	 * @param bool       $over     Se pasó del presupuesto: primero lo más accesible.
	 * @return array[]
	 */
	private function pick_advice( array $rows, array $weights, $group_by, array $prefer, $budget, $over ) {
		$money = ASI_Money::config( (array) $this->context['money'] );
		$max   = null;
		if ( $budget && null !== $budget['price_max'] ) {
			$max = ASI_Money::to_base( $budget['price_max'], (string) $budget['price_currency'], $money );
		}
		// Sin presupuesto, un asesor empieza por precios intermedios (la mediana de lo que conviene),
		// no por lo más caro.
		$median = null;
		if ( ! $budget && ! $over ) {
			$prices = array();
			foreach ( $rows as $row ) {
				$price = ASI_Money::card_price( (array) $row['card'], $money );
				if ( $price['base'] ) {
					$prices[] = (float) $price['base'];
				}
			}
			if ( $prices ) {
				sort( $prices );
				$median = $prices[ (int) floor( ( count( $prices ) - 1 ) / 2 ) ];
			}
		}
		foreach ( $rows as &$row ) {
			$attrs = isset( $row['card']['attrs'] ) ? (array) $row['card']['attrs'] : array();
			$value = isset( $attrs[ $group_by ] ) ? $attrs[ $group_by ] : '';
			$score = isset( $weights[ $value ] ) ? (float) $weights[ $value ] : 0.0;
			foreach ( $prefer as $key => $rule ) {
				if ( true === ASI_Attributes::accepts( $attrs, array( $key => $rule ) ) ) {
					$score += 0.15;
				}
			}
			$price = ASI_Money::card_price( (array) $row['card'], $money );
			if ( $max && $price['base'] ) {
				$score += 0.3 * min( 1, $price['base'] / $max ); // Aprovecha el presupuesto.
			}
			if ( $median && $price['base'] ) {
				$score -= 0.25 * abs( log( $price['base'] / $median ) ); // Cerca del precio típico.
			}
			if ( isset( $attrs['year'] ) ) {
				$score += 0.01 * max( 0, min( 10, (int) $attrs['year'] - 2016 ) ); // Desempate: más reciente.
			}
			$row['advice']     = $score;
			$row['advice_key'] = $value;
			$row['price_base'] = null === $price['base'] ? PHP_FLOAT_MAX : (float) $price['base'];
		}
		unset( $row );

		usort(
			$rows,
			static function ( $a, $b ) use ( $over ) {
				if ( $over && $a['price_base'] !== $b['price_base'] ) {
					return $a['price_base'] < $b['price_base'] ? -1 : 1;
				}
				return $a['advice'] === $b['advice'] ? 0 : ( $a['advice'] < $b['advice'] ? 1 : -1 );
			}
		);
		if ( $over ) {
			return array_slice( $rows, 0, 3 );
		}

		// Un solo vehículo por modelo (las versiones del mismo se ven en su ficha).
		$models = array();
		$rows   = array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( &$models ) {
					$model = self::model_key( $row );
					if ( isset( $models[ $model ] ) ) {
						return false;
					}
					$models[ $model ] = true;
					return true;
				}
			)
		);

		// Hasta 2 por tipo, siguiendo el orden de los pesos; luego se completa con lo mejor que quede.
		$picked = array();
		$taken  = array();
		foreach ( array_keys( $weights ) as $value ) {
			$count = 0;
			foreach ( $rows as $row ) {
				if ( $row['advice_key'] === $value && $count < 2 && count( $picked ) < self::$advice_cards ) {
					$picked[]                         = $row;
					$taken[ (int) $row['post_id'] ] = true;
					$count++;
				}
			}
		}
		foreach ( $rows as $row ) {
			if ( count( $picked ) >= self::$advice_cards ) {
				break;
			}
			if ( ! isset( $taken[ (int) $row['post_id'] ] ) ) {
				$picked[] = $row;
			}
		}
		usort(
			$picked,
			static function ( $a, $b ) {
				return $a['advice'] === $b['advice'] ? 0 : ( $a['advice'] < $b['advice'] ? 1 : -1 );
			}
		);
		return $picked;
	}

	/**
	 * Modelo de un producto para no repetirlo: marca + primera palabra del modelo
	 * ("hyundai accent", "bmw x3"), sin años ni versiones.
	 */
	private static function model_key( array $row ) {
		$title = preg_replace( '/\b(19|20)[0-9]{2}\b/', ' ', (string) $row['title_norm'] );
		$words = array_values( array_filter( explode( ' ', $title ) ) );
		return implode( ' ', array_slice( $words, 0, 2 ) );
	}

	/**
	 * Botones de presupuesto a partir de los precios de lo que conviene ("Hasta Q 200,000").
	 */
	private function budget_chips( array $rows ) {
		$money  = ASI_Money::config( (array) $this->context['money'] );
		$prices = array();
		foreach ( $rows as $row ) {
			$price = ASI_Money::card_price( (array) $row['card'], $money );
			if ( $price['base'] ) {
				$prices[] = (float) $price['base'];
			}
		}
		if ( count( $prices ) < 3 || '' === $money['base'] ) {
			return array();
		}
		sort( $prices );
		$chips = array();
		foreach ( array( 0.25, 0.6, 0.95 ) as $q ) {
			$value = $prices[ (int) floor( $q * ( count( $prices ) - 1 ) ) ];
			$step  = $value >= 100000 ? 50000 : ( $value >= 10000 ? 5000 : 500 );
			$value = ceil( $value / $step ) * $step;
			$chips[] = $this->responder->say( 'budget_chip', array( 'max' => ASI_Money::format( $value, $money['base'] ) ) );
		}
		return array_values( array_unique( $chips ) );
	}

	/**
	 * Falta algo para poder aconsejar: se pregunta, una sola cosa.
	 */
	private function advice_ask( $key, array $profile, array $state, array $suggestions ) {
		$asked   = $state['asked'];
		$asked[] = $key;
		return array(
			'kind'        => 'smalltalk',
			'text'        => $this->responder->say( $key ),
			'cards'       => array(),
			'followup'    => '',
			'links'       => array(),
			'suggestions' => $suggestions ? $suggestions : $this->lexicon->get_list( 'profile_chips' ),
			'more'        => false,
			'state'       => array( 'profile' => $profile, 'asked' => array_values( array_unique( $asked ) ) ),
		);
	}
}
