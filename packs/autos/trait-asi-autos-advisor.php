<?php
/**
 * Pack autos como asesor: perfil del cliente → carrocería que conviene, con el porqué.
 *
 * Perfil (claves): people, kids, ages (int[]), adults, uses (work, rough, city, highway), cargo_t.
 * Las reglas de recomendación están en profiles.php (editable, borrador por validar con asesores).
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || defined( 'ASI_CLI_TESTS' ) || exit;

trait ASI_Autos_Advisor {

	/** Números escritos con letras. */
	private static $number_words = array(
		'un' => 1, 'una' => 1, 'uno' => 1, 'dos' => 2, 'tres' => 3, 'cuatro' => 4, 'cinco' => 5, 'seis' => 6,
		'siete' => 7, 'ocho' => 8, 'nueve' => 9, 'diez' => 10, 'once' => 11, 'doce' => 12,
		'a' => 1, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7,
		'eight' => 8, 'nine' => 9, 'ten' => 10,
	);

	/** Nombres de cada carrocería en una recomendación. */
	private static $reco_names = array(
		'es' => array(
			'suv' => 'una SUV', 'van' => 'una van', 'microbus' => 'un microbús', 'bus' => 'un bus', 'pickup' => 'una pickup',
			'sedan' => 'un sedán', 'car' => 'un automóvil', 'hatchback' => 'un hatchback', 'truck' => 'un camión',
			'panel' => 'un panel', 'coupe' => 'un coupé', 'agricultural' => 'un vehículo agrícola', 'motorcycle' => 'una moto',
		),
		'en' => array(
			'suv' => 'an SUV', 'van' => 'a van', 'microbus' => 'a minibus', 'bus' => 'a bus', 'pickup' => 'a pickup',
			'sedan' => 'a sedan', 'car' => 'a car', 'hatchback' => 'a hatchback', 'truck' => 'a truck',
			'panel' => 'a cargo van', 'coupe' => 'a coupe', 'agricultural' => 'a farm vehicle', 'motorcycle' => 'a motorcycle',
		),
	);

	public function read_profile( $message, $language, array $profile ) {
		$text    = ' ' . self::fold( $message ) . ' ';
		$num     = '([0-9]{1,2}|' . implode( '|', array_keys( self::$number_words ) ) . ')';
		$before  = $profile;
		$changed = array();

		// Cuántos son.
		$people_patterns = array(
			'/[^a-z](?:somos|seriamos|vamos a ser|viajamos|nos movemos|we are|there are)\s+' . $num . '(?:\s+(?:personas|de familia|en la familia|integrantes|people|of us))?[^a-z0-9]/',
			'/[^a-z](?:familia de|family of)\s+' . $num . '[^a-z0-9]/',
			'/[^a-z]' . $num . '\s+(?:de familia|integrantes|miembros|en la familia|in the family|in my family)[^a-z]/',
		);
		foreach ( $people_patterns as $pattern ) {
			if ( preg_match( $pattern, $text, $m ) ) {
				$profile['people'] = self::to_number( $m[1] );
				break;
			}
		}

		// Adultos.
		if ( preg_match( '/[^a-z](?:solo yo|yo solo|soy soltero|soy soltera|just me|only me)[^a-z]/', $text ) ) {
			$profile['adults'] = 1;
		} elseif ( preg_match( '/[^a-z](?:mi esposa|mi esposo|mi pareja|mi mujer|mi marido|mi novia|mi novio|my wife|my husband|my partner)[^a-z]/', $text ) ) {
			$profile['adults'] = 2;
		}

		// Niños: "con dos hijos", "un niño de 3 años", "mi bebé".
		$kid_words = '(?:hijos|hijas|hijo|hija|ninos|ninas|nino|nina|nenes|nene|bebes|bebe|kids|kid|children|child|sons|daughters|son|daughter|baby|babies)';
		if ( preg_match( '/[^a-z]' . $num . '\s+(?:pequenos?\s+)?' . $kid_words . '[^a-z]/', $text, $m ) ) {
			$profile['kids'] = self::to_number( $m[1] );
		} elseif ( preg_match( '/[^a-z](?:mi|mis|my|our|nuestro|nuestra)\s+' . $kid_words . '[^a-z]/', $text, $m ) ) {
			$profile['kids'] = preg_match( '/(hijos|hijas|ninos|ninas|nenes|bebes|kids|children|sons|daughters|babies)/', $m[0] ) ? max( 2, isset( $profile['kids'] ) ? (int) $profile['kids'] : 0 ) : max( 1, isset( $profile['kids'] ) ? (int) $profile['kids'] : 0 );
		} elseif ( preg_match( '/[^a-z](?:sin hijos|sin ninos|no kids|no children)[^a-z]/', $text ) ) {
			$profile['kids'] = 0;
		}
		// Edades: "de 3 y 7 años", "de 8 meses".
		if ( isset( $profile['kids'] ) && $profile['kids'] > 0 && preg_match_all( '/([0-9]{1,2})\s*(anos|ano|meses|mes|years?|months?)/', $text, $all, PREG_SET_ORDER ) ) {
			$ages = array();
			foreach ( $all as $age ) {
				$ages[] = preg_match( '/^(meses|mes|months?)$/', $age[2] ) ? 0 : (int) $age[1];
			}
			if ( preg_match_all( '/([0-9]{1,2})\s*(?:y|,|and)\s*(?=[0-9]{1,2}\s*(?:anos|years?))/', $text, $lead ) ) {
				foreach ( $lead[1] as $n ) {
					$ages[] = (int) $n;
				}
			}
			$profile['ages'] = array_values( array_unique( array_filter( $ages, static function ( $a ) {
				return $a < 18;
			} ) ) );
		}
		if ( preg_match( '/[^a-z](bebe|bebes|baby|babies|recien nacido|carriola|coche de bebe|stroller)[^a-z]/', $text ) ) {
			$profile['ages']   = array_values( array_unique( array_merge( isset( $profile['ages'] ) ? (array) $profile['ages'] : array(), array( 0 ) ) ) );
			$profile['kids']   = max( 1, isset( $profile['kids'] ) ? (int) $profile['kids'] : 0 );
		}

		// Si dijo los niños y no cuántos son: adultos (2 si menciona pareja o "familia") + niños.
		if ( ! isset( $profile['people'] ) && ! empty( $profile['kids'] ) ) {
			$adults            = isset( $profile['adults'] ) ? (int) $profile['adults'] : 2;
			$profile['people'] = $adults + (int) $profile['kids'];
		} elseif ( ! isset( $profile['people'] ) && isset( $profile['adults'] ) ) {
			$profile['people'] = (int) $profile['adults'];
		}

		// Para qué lo usa.
		$uses = isset( $profile['uses'] ) ? (array) $profile['uses'] : array();
		$rest = $text;
		if ( preg_match( '/[^a-z](para ir al trabajo|ir a trabajar|ir a la oficina|al trabajo y|uso diario|diario|en la ciudad|para la ciudad|ciudad|trafico|commute|city)[^a-z]/', $rest ) ) {
			$uses[] = 'city';
			$rest   = preg_replace( '/(para ir al trabajo|ir a trabajar|ir a la oficina)/', ' ', $rest );
		}
		if ( preg_match( '/[^a-z](trabajo|trabajar|negocio|carga|cargar|mercaderia|herramientas|reparto|repartir|distribuir|construccion|obra|work|business|haul|hauling|tools|deliveries)[^a-z]/', $rest ) ) {
			$uses[] = 'work';
		}
		if ( preg_match( '/[^a-z](finca|campo|terraceria|montana|camino de tierra|caminos de tierra|lodo|off ?road|aldea|cerro|rio|farm|dirt|mountain)[^a-z]/', $rest ) ) {
			$uses[] = 'rough';
		}
		if ( preg_match( '/[^a-z](carretera|viajes|viajar|paseos|interior|road trips?|highway|long trips)[^a-z]/', $rest ) ) {
			$uses[] = 'highway';
		}
		if ( $uses ) {
			$profile['uses'] = array_values( array_unique( $uses ) );
		}

		// Carga en toneladas.
		if ( preg_match( '/([0-9]+(?:[.,][0-9]+)?)\s*(?:toneladas|tonelada|ton|tons)[^a-z]/', $text, $m ) && preg_match( '/(carg|llevar|mover|transportar|haul|carry|move)/', $text ) ) {
			$profile['cargo_t'] = (float) str_replace( ',', '.', $m[1] );
		}

		foreach ( $profile as $key => $value ) {
			if ( ! array_key_exists( $key, $before ) || $before[ $key ] !== $value ) {
				$changed[] = $key;
			}
		}
		return array( 'profile' => $profile, 'changed' => $changed );
	}

	public function asks_advice( $message, $language ) {
		$text = ' ' . self::fold( $message ) . ' ';
		return (bool) preg_match( '/[^a-z](recomiendas|recomendas|recomienda|recomiende|recomendarias|recomendaria|recomendacion|recomiendame|recomiendeme|me conviene|conviene mas|aconsejas|aconseja|aconsejame|aconsejame vos|sugieres|sugeris|sugiere|sugerencia|cual me queda|que me queda|que opciones tengo|que me ofreces|que tienes para mi|recommend|suggest|what should i (get|buy)|which one is best|what do you have for)[^a-z]/', $text );
	}

	public function can_advise( array $profile ) {
		return ! empty( $profile['people'] ) || ! empty( $profile['uses'] ) || ! empty( $profile['cargo_t'] );
	}

	public function recommend( array $profile, $language ) {
		$lang    = 'en' === $language ? 'en' : 'es';
		$rules   = require __DIR__ . '/profiles.php';
		$people  = isset( $profile['people'] ) ? (int) $profile['people'] : 0;
		$kids    = isset( $profile['kids'] ) ? (int) $profile['kids'] : 0;
		$ages    = isset( $profile['ages'] ) ? (array) $profile['ages'] : array();
		$uses    = isset( $profile['uses'] ) ? (array) $profile['uses'] : array();
		$cargo   = isset( $profile['cargo_t'] ) ? (float) $profile['cargo_t'] : 0.0;
		$small   = (bool) array_filter( $ages, static function ( $a ) {
			return $a < 5;
		} );
		$weights = array();
		$avoid   = array();
		$prefer  = array();
		$reasons = array();
		$avoid_r = '';
		$vars    = array( '{people}' => (string) $people, '{cargo}' => rtrim( rtrim( number_format( $cargo, 1, '.', '' ), '0' ), '.' ) );

		foreach ( $rules as $rule ) {
			$when = $rule['when'];
			if ( isset( $when['people_min'] ) && $people < $when['people_min'] ) {
				continue;
			}
			if ( isset( $when['people_max'] ) && ( ! $people || $people > $when['people_max'] ) ) {
				continue;
			}
			if ( isset( $when['kids'] ) && ( $when['kids'] !== ( $kids > 0 ) ) ) {
				continue;
			}
			if ( ! empty( $when['small_kids'] ) && ! $small ) {
				continue;
			}
			if ( isset( $when['use'] ) && ! in_array( $when['use'], $uses, true ) ) {
				continue;
			}
			if ( isset( $when['cargo_min'] ) && $cargo < $when['cargo_min'] ) {
				continue;
			}
			foreach ( isset( $rule['weights'] ) ? $rule['weights'] : array() as $body => $weight ) {
				$weights[ $body ] = ( isset( $weights[ $body ] ) ? $weights[ $body ] : 0 ) + $weight;
			}
			$avoid  = array_merge( $avoid, isset( $rule['avoid'] ) ? $rule['avoid'] : array() );
			$prefer = array_merge( $prefer, isset( $rule['prefer'] ) ? $rule['prefer'] : array() );
			if ( isset( $rule['reason'][ $lang ] ) ) {
				// Primero el porqué del uso y de la carga (lo que más cambia la elección), luego el
				// de los niños pequeños y al final el del tamaño del grupo.
				$priority = isset( $when['use'] ) || isset( $when['cargo_min'] ) ? 0 : ( ! empty( $when['small_kids'] ) || ! empty( $when['kids'] ) ? 1 : 2 );
				// Con un uso claro, "para 3 adultos un sedán o una SUV" sobra.
				if ( 2 === $priority && $uses && empty( $rule['avoid_reason'] ) ) {
					$priority = 9;
				}
				$reasons[] = array( $priority, strtr( $rule['reason'][ $lang ], $vars ) );
			}
			if ( '' === $avoid_r && isset( $rule['avoid_reason'][ $lang ] ) ) {
				$avoid_r = strtr( $rule['avoid_reason'][ $lang ], $vars );
			}
		}
		foreach ( array_unique( $avoid ) as $body ) {
			unset( $weights[ $body ] );
		}
		usort(
			$reasons,
			static function ( $a, $b ) {
				return $a[0] - $b[0];
			}
		);
		$reasons = array_column( array_filter( $reasons, static function ( $r ) {
			return $r[0] < 9;
		} ), 1 );
		if ( ! $weights ) {
			$weights = array( 'suv' => 0.5, 'sedan' => 0.5, 'car' => 0.5, 'pickup' => 0.5 );
		}
		arsort( $weights );

		$constraints = array( 'body' => array( 'in' => array_keys( $weights ) ) );
		if ( $people ) {
			$constraints['seats'] = array( 'min' => $people );
		}
		if ( $cargo ) {
			$constraints['cargo_t'] = array( 'min' => $cargo );
		}

		return array(
			'weights'     => $weights,
			'group_by'    => 'body',
			'constraints' => $constraints,
			'prefer'      => $prefer,
			'summary'     => self::profile_summary( $people, $kids, $uses, $lang ),
			'names'       => self::$reco_names[ $lang ],
			'reasons'     => array_values( array_unique( $reasons ) ),
			'avoid'       => $avoid_r,
		);
	}

	/** "una familia de 4 con un niño", "dos personas", "tu trabajo en la finca". */
	private static function profile_summary( $people, $kids, array $uses, $lang ) {
		$es = 'es' === $lang;
		if ( $people && $kids ) {
			$kid = $es ? ( 1 === $kids ? 'un niño' : $kids . ' niños' ) : ( 1 === $kids ? 'one kid' : $kids . ' kids' );
			return $es ? 'una familia de ' . $people . ' con ' . $kid : 'a family of ' . $people . ' with ' . $kid;
		}
		if ( $people >= 3 ) {
			return $es ? $people . ' personas' : $people . ' people';
		}
		if ( 2 === $people ) {
			return $es ? 'dos personas' : 'two people';
		}
		if ( 1 === $people ) {
			return $es ? 'una persona' : 'one person';
		}
		$names = $es
			? array( 'work' => 'trabajo y carga', 'rough' => 'caminos difíciles', 'city' => 'la ciudad', 'highway' => 'carretera' )
			: array( 'work' => 'work and hauling', 'rough' => 'rough roads', 'city' => 'the city', 'highway' => 'highway driving' );
		$list = array();
		foreach ( $uses as $use ) {
			if ( isset( $names[ $use ] ) ) {
				$list[] = $names[ $use ];
			}
		}
		return implode( $es ? ' y ' : ' and ', $list );
	}

	private static function to_number( $raw ) {
		return ctype_digit( (string) $raw ) ? (int) $raw : ( isset( self::$number_words[ $raw ] ) ? self::$number_words[ $raw ] : 0 );
	}
}
