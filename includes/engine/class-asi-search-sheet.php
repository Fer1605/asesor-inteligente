<?php
/**
 * Ficha de la búsqueda: lo que el cliente va pidiendo, por campos, sin depender del giro del sitio.
 *
 * Campos (todos salen de lo que el sitio ya tiene):
 *   terms   [kind => string[]]  términos del sitio por tipo (category, brand, flag, tag, code…).
 *                               Varias marcas son "cualquiera de ellas"; el resto se suma.
 *   attrs   [key => string]     atributos del giro (los define el pack: carrocería, combustible…),
 *                               con las palabras que los dieron, para volver a leerlos igual.
 *   values  [key => string]     lo que significa cada atributo ("camioneta" y "suv" son lo mismo).
 *   words   string[]            palabras sueltas que no son términos ni atributos.
 *   exclude string[]            lo que no quiere (términos o palabras de atributos).
 *   min, max, currency          presupuesto.
 *
 * El "tipo" de la búsqueda es la categoría del sitio o los atributos que el pack marca como tipo
 * (type_attributes()). Cambiar de tipo es un tema nuevo: se quedan el presupuesto y lo que no quiere.
 *
 * La ficha se guarda en el estado como texto (render()) que el analizador vuelve a leer igual.
 *
 * Clase pura.
 *
 * @package AsesorInteligente
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'ASI_CLI_TESTS' ) ) {
	exit;
}

class ASI_Search_Sheet {

	/** Términos del sitio que nombran el tipo de lo que busca. */
	const TYPE_KINDS = array( 'category' );

	/** Términos de los que se pide uno u otro (un producto no es de dos marcas). */
	const ANY_OF_KINDS = array( 'brand' );

	public static function blank() {
		return array( 'terms' => array(), 'attrs' => array(), 'values' => array(), 'words' => array(), 'exclude' => array(), 'min' => null, 'max' => null, 'currency' => '' );
	}

	/** Atributos que los packs marcan como "tipo" (autos: carrocería). */
	public static function type_keys( array $packs ) {
		$keys = array();
		foreach ( $packs as $pack ) {
			if ( is_object( $pack ) && method_exists( $pack, 'type_attributes' ) ) {
				$keys = array_merge( $keys, (array) $pack->type_attributes() );
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * La ficha de lo que dice una consulta analizada (un mensaje, o la búsqueda guardada).
	 */
	public static function from_query( array $query ) {
		$sheet = self::blank();
		foreach ( (array) $query['groups'] as $group ) {
			if ( ! empty( $group['soft'] ) ) {
				continue; // Palabra de un atributo ("camioneta"): la guarda el atributo.
			}
			$kind = 'code' === $group['kind'] ? 'code' : ( isset( $group['term_kind'] ) ? (string) $group['term_kind'] : '' );
			$list = ! empty( $group['brands'] ) ? (array) $group['brands'] : array( (string) $group['text'] );
			if ( '' === $kind ) {
				// Una palabra que no está en ningún producto del sitio no se puede buscar: no se guarda.
				if ( ! isset( $group['known'] ) || $group['known'] ) {
					$sheet['words'] = array_merge( $sheet['words'], $list );
				}
				continue;
			}
			$sheet['terms'][ $kind ] = array_merge( isset( $sheet['terms'][ $kind ] ) ? $sheet['terms'][ $kind ] : array(), $list );
		}
		foreach ( isset( $query['attr_spans'] ) ? (array) $query['attr_spans'] : array() as $key => $span ) {
			if ( isset( $query['attrs'][ $key ] ) && ! isset( $query['attrs'][ $key ]['not_in'] ) ) {
				$sheet['attrs'][ $key ]  = (string) $span;
				$sheet['values'][ $key ] = self::value_of( $query['attrs'][ $key ] );
			}
		}
		foreach ( isset( $query['exclude'] ) ? (array) $query['exclude'] : array() as $group ) {
			// Varias marcas que no quiere vienen juntas en un grupo ("byd o mazda o mitsubishi").
			$sheet['exclude'] = array_merge( $sheet['exclude'], ! empty( $group['brands'] ) ? (array) $group['brands'] : array( (string) $group['text'] ) );
		}
		foreach ( isset( $query['attr_not_spans'] ) ? (array) $query['attr_not_spans'] : array() as $span ) {
			$sheet['exclude'][] = (string) $span;
		}
		$sheet['min']      = isset( $query['price_min'] ) && $query['price_min'] ? (float) $query['price_min'] : null;
		$sheet['max']      = isset( $query['price_max'] ) && $query['price_max'] ? (float) $query['price_max'] : null;
		$sheet['currency'] = isset( $query['price_currency'] ) ? (string) $query['price_currency'] : '';
		return self::tidy( $sheet );
	}

	public static function is_empty( array $sheet ) {
		return ! $sheet['terms'] && ! $sheet['attrs'] && ! $sheet['words'] && ! $sheet['exclude'] && null === $sheet['min'] && null === $sheet['max'];
	}

	/**
	 * Presupuesto redondeado en rangos, para la reportería ("hasta Q 300,000"): 225 mil y 300 mil
	 * caen en el mismo rango. Vale para cualquier moneda y escala (redondea a la cifra mayor).
	 */
	public static function budget_bucket( array $sheet ) {
		$symbol = 'USD' === $sheet['currency'] ? '$' : ( 'GTQ' === $sheet['currency'] ? 'Q ' : '' );
		$value  = null !== $sheet['max'] ? (float) $sheet['max'] : ( null !== $sheet['min'] ? (float) $sheet['min'] : 0.0 );
		if ( $value <= 0 ) {
			return '';
		}
		$step   = pow( 10, max( 0, (int) floor( log10( $value ) ) ) );
		$amount = null !== $sheet['max'] ? ceil( $value / $step ) * $step : floor( $value / $step ) * $step;
		return ( null !== $sheet['max'] ? 'Hasta ' : 'Desde ' ) . $symbol . number_format( $amount, 0, '.', ',' );
	}

	public static function has_price( array $sheet ) {
		return null !== $sheet['min'] || null !== $sheet['max'];
	}

	/** El tipo de lo que busca: categorías y atributos de tipo. */
	public static function type_of( array $sheet, array $type_keys ) {
		$out = array();
		foreach ( self::TYPE_KINDS as $kind ) {
			foreach ( isset( $sheet['terms'][ $kind ] ) ? $sheet['terms'][ $kind ] : array() as $term ) {
				$out[] = $kind . ':' . $term;
			}
		}
		foreach ( $type_keys as $key ) {
			if ( isset( $sheet['attrs'][ $key ] ) ) {
				$out[] = $key . ':' . ( isset( $sheet['values'][ $key ] ) ? $sheet['values'][ $key ] : $sheet['attrs'][ $key ] );
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * Suma lo que dice el mensaje a la ficha guardada.
	 *
	 * - Tema nuevo (otro tipo, o palabras sueltas sin "y/solo/pero…"): queda lo nuevo, más el
	 *   presupuesto y lo que no quiere.
	 * - Si no: cada campo que el mensaje menciona reemplaza al guardado (otra marca reemplaza a la
	 *   anterior, salvo "también"); lo que no menciona se queda. Las palabras sueltas se suman.
	 * - Lo que no quiere se acumula, menos lo que ahora sí pide.
	 *
	 * @param array  $base      Ficha guardada.
	 * @param array  $delta     Ficha del mensaje.
	 * @param array  $options   type_keys, also (dijo "también"), refine (empieza con "y/solo/pero…").
	 * @return array { sheet, mode: new|topic|refine }
	 */
	public static function merge( array $base, array $delta, array $options = array() ) {
		$type_keys = isset( $options['type_keys'] ) ? (array) $options['type_keys'] : array();
		$also      = ! empty( $options['also'] );
		$refine    = ! empty( $options['refine'] );
		if ( self::is_empty( $base ) ) {
			return array( 'sheet' => $delta, 'mode' => 'new' );
		}
		$base_type  = self::type_of( $base, $type_keys );
		$delta_type = self::type_of( $delta, $type_keys );
		$new_type   = $delta_type && $base_type && $delta_type !== $base_type && ! $also;
		$new_words  = $delta['words'] && ! $refine && ! $delta['terms'] && ! $delta['attrs'];
		if ( $new_type || $new_words ) {
			$sheet            = $delta;
			$sheet['exclude'] = self::without( array_merge( $base['exclude'], $delta['exclude'] ), self::wanted( $delta ) );
			if ( ! self::has_price( $delta ) ) {
				$sheet['min']      = $base['min'];
				$sheet['max']      = $base['max'];
				$sheet['currency'] = $base['currency'];
			}
			return array( 'sheet' => self::tidy( $sheet ), 'mode' => 'topic' );
		}

		$sheet = $base;
		foreach ( $delta['terms'] as $kind => $terms ) {
			$sheet['terms'][ $kind ] = $also || ( ! in_array( $kind, self::ANY_OF_KINDS, true ) && ! in_array( $kind, self::TYPE_KINDS, true ) )
				? array_merge( isset( $sheet['terms'][ $kind ] ) ? $sheet['terms'][ $kind ] : array(), $terms )
				: $terms;
		}
		foreach ( $delta['attrs'] as $key => $span ) {
			$joined                  = $also && isset( $sheet['attrs'][ $key ] ) && $sheet['attrs'][ $key ] !== $span;
			$sheet['attrs'][ $key ]  = $joined ? $sheet['attrs'][ $key ] . ' o ' . $span : $span;
			$sheet['values'][ $key ] = $joined ? '' : ( isset( $delta['values'][ $key ] ) ? $delta['values'][ $key ] : '' );
		}
		$sheet['words'] = array_merge( $sheet['words'], $delta['words'] );
		if ( self::has_price( $delta ) ) {
			$sheet['min']      = $delta['min'];
			$sheet['max']      = $delta['max'];
			$sheet['currency'] = $delta['currency'];
		}
		// Lo que ahora no quiere sale también de lo que pedía ("no quiero toyota" tras "toyota").
		$sheet['exclude'] = self::without( array_merge( $sheet['exclude'], $delta['exclude'] ), self::wanted( $delta ) );
		foreach ( $sheet['terms'] as $kind => $terms ) {
			$sheet['terms'][ $kind ] = self::without( $terms, $delta['exclude'] );
		}
		foreach ( $sheet['attrs'] as $key => $span ) {
			if ( in_array( $span, $delta['exclude'], true ) ) {
				unset( $sheet['attrs'][ $key ], $sheet['values'][ $key ] );
			}
		}
		return array( 'sheet' => self::tidy( $sheet ), 'mode' => 'refine' );
	}

	/**
	 * Formas de aflojar la ficha cuando no hay nada exacto, en orden: primero las palabras sueltas,
	 * luego los atributos que no son el tipo, luego las marcas y otros términos. El tipo nunca se
	 * suelta, y el presupuesto aparte (lo decide quien llama: se dice que no alcanza).
	 *
	 * @param array      $keep Lo que no se suelta (lo que el cliente acaba de pedir), o null.
	 * @return array[] { sheet, dropped: string[] (lo que se quitó, como lo dijo), level: words|attrs|terms }
	 */
	public static function relaxations( array $sheet, array $type_keys, $keep = null ) {
		$keep    = is_array( $keep ) ? $keep : self::blank();
		$held    = self::wanted( $keep );
		$is_held = function ( $item ) use ( $held ) {
			return in_array( ASI_Normalizer::normalize( $item ), array_map( array( 'ASI_Normalizer', 'normalize' ), $held ), true );
		};
		$steps   = array();
		$current = $sheet;
		$dropped = array();

		$words = array();
		foreach ( $current['words'] as $i => $word ) {
			if ( ! $is_held( $word ) ) {
				$words[] = $word;
				unset( $current['words'][ $i ] );
			}
		}
		$current['words'] = array_values( $current['words'] );
		if ( $words ) {
			$dropped = array_merge( $dropped, $words );
			$steps[] = array( 'sheet' => $current, 'dropped' => $dropped, 'level' => 'words' );
		}
		$attrs = array();
		foreach ( $current['attrs'] as $key => $span ) {
			if ( ! in_array( $key, $type_keys, true ) && ! isset( $keep['attrs'][ $key ] ) ) {
				$attrs[] = $span;
				unset( $current['attrs'][ $key ], $current['values'][ $key ] );
			}
		}
		if ( $attrs ) {
			$dropped = array_merge( $dropped, $attrs );
			$steps[] = array( 'sheet' => $current, 'dropped' => $dropped, 'level' => 'attrs' );
		}
		// Marcas y otros términos solo se sueltan si queda el tipo (sin tipo no quedaría qué buscar).
		$terms = array();
		if ( self::type_of( $current, $type_keys ) ) {
			foreach ( $current['terms'] as $kind => $list ) {
				if ( ! in_array( $kind, self::TYPE_KINDS, true ) && ! isset( $keep['terms'][ $kind ] ) ) {
					$terms = array_merge( $terms, in_array( $kind, self::ANY_OF_KINDS, true ) ? array( implode( ' o ', $list ) ) : $list );
					unset( $current['terms'][ $kind ] );
				}
			}
		}
		if ( $terms ) {
			$dropped = array_merge( $dropped, $terms );
			$steps[] = array( 'sheet' => $current, 'dropped' => $dropped, 'level' => 'terms' );
		}
		return $steps;
	}

	/** La ficha sin presupuesto. */
	public static function without_price( array $sheet ) {
		$sheet['min']      = null;
		$sheet['max']      = null;
		$sheet['currency'] = '';
		return $sheet;
	}

	/**
	 * La ficha en texto, en el orden en que el analizador la vuelve a leer igual: términos,
	 * atributos, palabras, presupuesto y al final lo que no quiere.
	 *
	 * @param array $phrases Frases del idioma (ai_phrases): search_max, search_min, search_range, search_not.
	 */
	public static function render( array $sheet, array $phrases ) {
		// Orden de lectura natural: tipo (categoría y atributos) y luego marcas y otros términos
		// ("suv gasolina toyota o volkswagen"), que es como se nombra la búsqueda al cliente.
		$parts = array();
		foreach ( self::TYPE_KINDS as $kind ) {
			if ( isset( $sheet['terms'][ $kind ] ) ) {
				$parts[] = implode( ' ', $sheet['terms'][ $kind ] );
			}
		}
		foreach ( $sheet['attrs'] as $span ) {
			$parts[] = $span;
		}
		foreach ( $sheet['terms'] as $kind => $terms ) {
			if ( ! in_array( $kind, self::TYPE_KINDS, true ) ) {
				$parts[] = in_array( $kind, self::ANY_OF_KINDS, true ) ? implode( ' o ', $terms ) : implode( ' ', $terms );
			}
		}
		$parts  = array_merge( $parts, $sheet['words'] );
		$symbol = 'USD' === $sheet['currency'] ? '$' : ( 'GTQ' === $sheet['currency'] ? 'q' : '' );
		$money  = function ( $v ) use ( $symbol ) {
			return $symbol . (int) round( $v );
		};
		if ( null !== $sheet['min'] && null !== $sheet['max'] && isset( $phrases['search_range'] ) ) {
			$parts[] = str_replace( array( '{min}', '{max}' ), array( $money( $sheet['min'] ), $money( $sheet['max'] ) ), $phrases['search_range'] );
		} elseif ( null !== $sheet['max'] && isset( $phrases['search_max'] ) ) {
			$parts[] = str_replace( '{amount}', $money( $sheet['max'] ), $phrases['search_max'] );
		} elseif ( null !== $sheet['min'] && isset( $phrases['search_min'] ) ) {
			$parts[] = str_replace( '{amount}', $money( $sheet['min'] ), $phrases['search_min'] );
		}
		if ( $sheet['exclude'] && isset( $phrases['search_not'] ) ) {
			$parts[] = str_replace( '{words}', implode( ' ', $sheet['exclude'] ), $phrases['search_not'] );
		}
		return trim( preg_replace( '/\s+/', ' ', implode( ' ', array_filter( $parts, 'strlen' ) ) ) );
	}

	/** Lo que significa una restricción de atributo, para comparar ("in" ordenado, mínimo, máximo). */
	private static function value_of( $rule ) {
		$rule = (array) $rule;
		if ( isset( $rule['in'] ) ) {
			$in = array_map( 'strval', (array) $rule['in'] );
			sort( $in );
			$rule['in'] = $in;
		}
		ksort( $rule );
		return json_encode( $rule );
	}

	/** Lo que pide (términos y palabras de atributos), en forma normal. */
	private static function wanted( array $sheet ) {
		$out = $sheet['words'];
		foreach ( $sheet['terms'] as $terms ) {
			$out = array_merge( $out, $terms );
		}
		foreach ( $sheet['attrs'] as $span ) {
			$out = array_merge( $out, array( $span ), explode( ' o ', $span ) );
		}
		return $out;
	}

	private static function without( array $list, array $remove ) {
		$remove = array_map( array( 'ASI_Normalizer', 'normalize' ), $remove );
		return array_values(
			array_filter(
				$list,
				function ( $item ) use ( $remove ) {
					return ! in_array( ASI_Normalizer::normalize( $item ), $remove, true );
				}
			)
		);
	}

	private static function tidy( array $sheet ) {
		foreach ( $sheet['terms'] as $kind => $terms ) {
			$terms = array_values( array_unique( array_filter( array_map( 'strval', $terms ), 'strlen' ) ) );
			if ( $terms ) {
				$sheet['terms'][ $kind ] = $terms;
			} else {
				unset( $sheet['terms'][ $kind ] );
			}
		}
		$sheet['words']   = array_values( array_unique( array_filter( array_map( 'strval', $sheet['words'] ), 'strlen' ) ) );
		$sheet['exclude'] = array_values( array_unique( array_filter( array_map( 'strval', $sheet['exclude'] ), 'strlen' ) ) );
		return $sheet;
	}
}
