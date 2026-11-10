<?php
/**
 * Pack "autos": criterio de asesor para agencias de vehículos.
 *
 * Lee la ficha de cualquier sitio de autos por convenciones comunes (nombres de campos y términos
 * en español e inglés), nunca por un sitio concreto:
 *  - body:         sedan, hatchback, car (turismo sin detalle), suv, pickup, van, microbus, bus,
 *                  truck, panel, agricultural, coupe, motorcycle.
 *  - seats, doors: números. Si faltan las plazas se estiman por carrocería (seats_est = true).
 *  - traction:     4x4, awd, 4x2.
 *  - fuel:         diesel, gasoline, hybrid, electric, lpg.
 *  - transmission: auto, manual.
 *  - year, km, cc, cargo_t (toneladas de carga), condition (new, used).
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || defined( 'ASI_CLI_TESTS' ) || exit;

final class ASI_Pack_Autos implements ASI_Pack {

	const VEHICLE_TYPES = array( 'vehiculo', 'vehiculos', 'vehicle', 'vehicles', 'auto', 'autos', 'car', 'cars', 'carro', 'carros', 'listing', 'listings' );

	/**
	 * Carrocerías: clave => palabras que la nombran (sin acentos, minúsculas). El orden importa:
	 * se prueba de lo más específico a lo más general.
	 */
	const BODIES = array(
		'microbus'     => array( 'microbus', 'micro bus', 'minibus', 'coaster' ),
		'bus'          => array( 'bus', 'autobus', 'buses' ),
		'pickup'       => array( 'pick up', 'pickup', 'pick-up', 'picop', 'palangana', 'doble cabina', 'cabina sencilla' ),
		'panel'        => array( 'panel', 'furgon', 'cargo van' ),
		'van'          => array( 'minivan', 'mini van', 'van', 'camioneta familiar' ),
		'agricultural' => array( 'tractor', 'maquinaria agricola' ),
		'truck'        => array( 'camion', 'camiones', 'truck', 'cabezal', 'volteo', 'chasis cabina', 'ton' ),
		'suv'          => array( 'suv', 'camioneta', 'todoterreno', 'crossover' ),
		'hatchback'    => array( 'hatchback', 'hatch back', 'hb' ),
		'coupe'        => array( 'coupe', 'cupe' ),
		'motorcycle'   => array( 'moto', 'motocicleta', 'motorcycle' ),
		'sedan'        => array( 'sedan' ),
		'car'          => array( 'automovil', 'automoviles', 'turismo' ),
	);

	/**
	 * Pickups conocidas del mercado, para leer fichas que no dicen la carrocería (p. ej. las que
	 * el sitio clasifica como "camioneta agrícola", que es una categoría fiscal, no un tipo).
	 */
	const PICKUP_MODELS = array( 'hilux', 'frontier', 'navara', 'np300', 'ranger', 'l200', 'triton', 'bt-50', 'bt 50', 'd-max', 'dmax', 'tacoma', 'tundra', 'amarok', 'gladiator', 'f-150', 'f150', 'silverado', 'colorado', 'ram 1500', 'ram 2500', 'maverick', 'saveiro', 'strada', 'toro', 'alaskan', 'poer', 'k01', 'kaicene', 't60', 'tunland', 'shark', 'cannon' );

	/** Etiqueta legible de cada carrocería. */
	const BODY_LABELS = array(
		'es' => array(
			'sedan' => 'sedán', 'hatchback' => 'hatchback', 'car' => 'automóvil', 'suv' => 'SUV', 'pickup' => 'pickup',
			'van' => 'van', 'microbus' => 'microbús', 'bus' => 'bus', 'truck' => 'camión', 'panel' => 'panel',
			'agricultural' => 'agrícola', 'coupe' => 'coupé', 'motorcycle' => 'moto',
		),
		'en' => array(
			'sedan' => 'sedan', 'hatchback' => 'hatchback', 'car' => 'car', 'suv' => 'SUV', 'pickup' => 'pickup',
			'van' => 'van', 'microbus' => 'minibus', 'bus' => 'bus', 'truck' => 'truck', 'panel' => 'cargo van',
			'agricultural' => 'farm vehicle', 'coupe' => 'coupe', 'motorcycle' => 'motorcycle',
		),
	);

	/** Plazas típicas por carrocería, para los vehículos que no las traen. */
	const SEATS_BY_BODY = array(
		'sedan' => 5, 'hatchback' => 5, 'car' => 5, 'coupe' => 4, 'suv' => 5, 'van' => 7, 'microbus' => 15,
		'bus' => 30, 'truck' => 3, 'panel' => 2, 'agricultural' => 3, 'motorcycle' => 2,
	);

	/** Lo que el cliente nombra → carrocerías que acepta. */
	const QUERY_BODIES = array(
		'sedan'        => array( 'sedan', 'car' ),
		'hatchback'    => array( 'hatchback', 'car' ),
		'car'          => array( 'sedan', 'hatchback', 'car', 'coupe' ),
		'suv'          => array( 'suv' ),
		'pickup'       => array( 'pickup' ),
		'van'          => array( 'van', 'microbus' ),
		'microbus'     => array( 'microbus' ),
		'bus'          => array( 'bus', 'microbus' ),
		'truck'        => array( 'truck' ),
		'panel'        => array( 'panel' ),
		'agricultural' => array( 'agricultural' ),
		'coupe'        => array( 'coupe' ),
		'motorcycle'   => array( 'motorcycle' ),
	);

	public function id() {
		return 'autos';
	}

	public function label() {
		return 'Vehículos';
	}

	public function detect() {
		if ( ! function_exists( 'post_type_exists' ) ) {
			return false;
		}
		foreach ( self::VEHICLE_TYPES as $type ) {
			if ( post_type_exists( $type ) ) {
				return true;
			}
		}
		return false;
	}

	public function field_role( $name, $label, array $samples, $role ) {
		$key = self::fold( $name . ' ' . $label );
		// "Modelo" / "año" con valores de año (2019, 2026) es el año, no un código.
		if ( preg_match( '/(^|[^a-z])(modelo|model|ano|anio|year)([^a-z]|$)/', $key ) && self::samples_are_years( $samples ) ) {
			return 'attribute';
		}
		return $role;
	}

	public function attributes( array $doc, $post_type ) {
		if ( ! in_array( $post_type, self::VEHICLE_TYPES, true ) ) {
			return array();
		}
		$attrs = array();
		$facts = array();
		foreach ( isset( $doc['facts'] ) ? (array) $doc['facts'] : array() as $fact ) {
			if ( is_array( $fact ) && isset( $fact[0], $fact[1] ) ) {
				$facts[] = array( self::fold( $fact[0] ), (string) $fact[1] );
			}
		}
		$facet_values = array();
		foreach ( isset( $doc['facets'] ) ? (array) $doc['facets'] : array() as $facet ) {
			if ( is_array( $facet ) && isset( $facet[1] ) && 'fact' !== $facet[0] ) {
				$facet_values[] = (string) $facet[1];
			}
		}
		$title = isset( $doc['card']['title'] ) ? (string) $doc['card']['title'] : '';
		$terms = ' ' . self::fold( implode( ' | ', $facet_values ) . ' | ' . implode( ' | ', isset( $doc['terms'] ) ? (array) $doc['terms'] : array() ) ) . ' ';
		$all   = ' ' . self::fold( $title ) . ' |' . $terms;

		// Carrocería: primero los términos del sitio (tipo de vehículo), después el título.
		foreach ( $facet_values as $value ) {
			$body = self::body_of( $value, true );
			if ( '' !== $body ) {
				$attrs['body']       = $body;
				$attrs['body_label'] = $value;
				break;
			}
		}
		// "Camioneta agrícola" / "agrícola" es una categoría fiscal: la carrocería sale del modelo.
		if ( ! isset( $attrs['body'] ) ) {
			foreach ( $facet_values as $value ) {
				if ( in_array( trim( self::fold( $value ) ), array( 'camioneta agricola', 'agricola', 'vehiculo agricola' ), true ) ) {
					$attrs['body']       = self::is_pickup_model( $title ) || 'pickup' === self::body_of( $title ) ? 'pickup' : 'suv';
					$attrs['body_label'] = 'pickup' === $attrs['body'] ? 'Pick-up' : 'SUV';
					break;
				}
			}
		}
		if ( ! isset( $attrs['body'] ) ) {
			$body = self::is_pickup_model( $title ) ? 'pickup' : self::body_of( $title );
			if ( '' !== $body ) {
				$attrs['body'] = $body;
			}
		}

		foreach ( $facts as $fact ) {
			list( $label, $value ) = $fact;
			$number = self::number( $value );
			if ( preg_match( '/(^|[^a-z])(pasajeros|plazas|passengers|seats|seating|capacidad de pasajeros)([^a-z]|$)/', $label ) && $number >= 1 && $number <= 80 ) {
				$attrs['seats'] = (int) $number;
			} elseif ( preg_match( '/(^|[^a-z])(asientos)([^a-z]|$)/', $label ) && $number >= 1 && $number <= 80 && ! isset( $attrs['seats'] ) ) {
				$attrs['seats'] = (int) $number;
			} elseif ( preg_match( '/(^|[^a-z])(puertas|doors)([^a-z]|$)/', $label ) && $number >= 1 && $number <= 6 ) {
				$attrs['doors'] = (int) $number;
			} elseif ( preg_match( '/(^|[^a-z])(traccion|traction|drive|drivetrain)([^a-z]|$)/', $label ) ) {
				$traction = self::traction_of( $value );
				if ( '' !== $traction ) {
					$attrs['traction'] = $traction;
				}
			} elseif ( preg_match( '/(^|[^a-z])(transmision|transmission|caja)([^a-z]|$)/', $label ) ) {
				$transmission = self::transmission_of( $value );
				if ( '' !== $transmission ) {
					$attrs['transmission'] = $transmission;
				}
			} elseif ( preg_match( '/(^|[^a-z])(modelo|model|ano|anio|year)([^a-z]|$)/', $label ) && $number >= 1950 && $number <= 2040 ) {
				$attrs['year'] = (int) $number;
			} elseif ( preg_match( '/(^|[^a-z])(kilometraje|kilometros|km|mileage|odometro)([^a-z]|$)/', $label ) && '' !== trim( $value ) ) {
				$attrs['km'] = (int) $number;
			} elseif ( preg_match( '/(^|[^a-z])(c\.? ?c\.?|cilindrada|displacement)([^a-z]|$)/', $label ) && $number >= 49 && $number <= 20000 ) {
				$attrs['cc'] = (int) $number;
			} elseif ( preg_match( '/(capacidad de carga|carga util|payload|tonelaje)/', $label ) && $number > 0 ) {
				$tons             = false !== strpos( self::fold( $value ), 'kg' ) ? $number / 1000 : $number;
				$attrs['cargo_t'] = isset( $attrs['cargo_t'] ) ? max( $attrs['cargo_t'], $tons ) : $tons;
			} elseif ( preg_match( '/(combustible|fuel)/', $label ) ) {
				$fuel = self::fuel_of( $value );
				if ( '' !== $fuel ) {
					$attrs['fuel'] = $fuel;
				}
			}
		}

		// Lo que falte, por los términos del sitio y el título.
		if ( ! isset( $attrs['traction'] ) ) {
			$traction = self::traction_of( $all );
			if ( '' !== $traction ) {
				$attrs['traction'] = $traction;
			}
		}
		if ( ! isset( $attrs['transmission'] ) ) {
			foreach ( $facet_values as $value ) {
				$exact = self::fold( trim( $value ) );
				if ( in_array( $exact, array( 'automatica', 'automatico', 'automatic', 'cvt', 'mecanica', 'mecanico', 'manual', 'estandar' ), true ) ) {
					$attrs['transmission'] = self::transmission_of( $exact );
					break;
				}
			}
		}
		// Combustible: un término exacto del sitio ("Diesel", el distintivo "Híbrido") o el título.
		$fuel = '';
		foreach ( $facet_values as $value ) {
			$fuel = self::fuel_of( $value, true );
			if ( '' !== $fuel ) {
				break;
			}
		}
		$fuel = '' !== $fuel ? $fuel : self::fuel_of( $title );
		if ( in_array( $fuel, array( 'hybrid', 'electric' ), true ) || ( ! isset( $attrs['fuel'] ) && '' !== $fuel ) ) {
			$attrs['fuel'] = $fuel;
		}
		foreach ( $facet_values as $value ) {
			$exact = self::fold( trim( $value ) );
			if ( in_array( $exact, array( 'usado', 'usada', 'seminuevo', 'seminueva', 'used', 'pre owned', 'pre-owned' ), true ) ) {
				$attrs['condition'] = 'used';
				break;
			}
			if ( in_array( $exact, array( 'nuevo', 'nueva', 'new', '0 km' ), true ) ) {
				$attrs['condition'] = 'new';
			}
		}
		if ( ! isset( $attrs['condition'] ) && isset( $attrs['km'] ) ) {
			$attrs['condition'] = $attrs['km'] > 1000 ? 'used' : 'new';
		}
		if ( ! isset( $attrs['year'] ) && preg_match( '/[^0-9](19[5-9][0-9]|20[0-4][0-9])[^0-9]/', ' ' . $title . ' ', $m ) ) {
			$attrs['year'] = (int) $m[1];
		}
		if ( ! isset( $attrs['cargo_t'] ) && preg_match( '/([0-9]+(?:[.,][0-9]+)?)\s*(?:ton|tons|toneladas)\b/', self::fold( $title ), $m ) ) {
			$attrs['cargo_t'] = (float) str_replace( ',', '.', $m[1] );
		}

		// Plazas que faltan, estimadas por carrocería.
		if ( ! isset( $attrs['seats'] ) && isset( $attrs['body'] ) ) {
			if ( 'pickup' === $attrs['body'] ) {
				$double         = preg_match( '/doble cabina|cabina doble|double cab|crew cab|4 puertas/', $all ) || ( isset( $attrs['doors'] ) && $attrs['doors'] >= 4 );
				$attrs['seats'] = $double ? 5 : 3;
			} elseif ( isset( self::SEATS_BY_BODY[ $attrs['body'] ] ) ) {
				$attrs['seats'] = self::SEATS_BY_BODY[ $attrs['body'] ];
			}
			if ( isset( $attrs['seats'] ) ) {
				$attrs['seats_est'] = true;
			}
		}
		return $attrs;
	}

	public function card( array $card, array $attrs ) {
		if ( ! $attrs ) {
			return $card;
		}
		if ( ! empty( $attrs['body_label'] ) ) {
			$card['category'] = $attrs['body_label'];
		} elseif ( ! empty( $attrs['body'] ) ) {
			$card['category'] = self::BODY_LABELS['es'][ $attrs['body'] ];
		}
		// El año no es un código.
		if ( isset( $attrs['year'] ) && (string) $attrs['year'] === trim( (string) $card['code'] ) ) {
			$card['code'] = '';
		}
		$specs = array();
		if ( isset( $attrs['year'] ) ) {
			$specs[] = array( 'label' => 'Año', 'value' => (string) $attrs['year'] );
		}
		if ( isset( $attrs['seats'] ) && empty( $attrs['seats_est'] ) ) {
			$specs[] = array( 'label' => 'Pasajeros', 'value' => (string) $attrs['seats'] );
		}
		if ( isset( $attrs['traction'] ) ) {
			$specs[] = array( 'label' => 'Tracción', 'value' => strtoupper( $attrs['traction'] ) );
		}
		if ( isset( $attrs['transmission'] ) ) {
			$specs[] = array( 'label' => 'Transmisión', 'value' => 'auto' === $attrs['transmission'] ? 'Automática' : 'Mecánica' );
		}
		if ( isset( $attrs['km'] ) && isset( $attrs['condition'] ) && 'used' === $attrs['condition'] ) {
			$specs[] = array( 'label' => 'Kilometraje', 'value' => number_format( $attrs['km'], 0, '.', ',' ) . ' km' );
		}
		if ( $specs ) {
			$card['specs'] = $specs;
		}
		return $card;
	}

	public function parse_query( $message, $language ) {
		$text   = ' ' . self::fold( $message ) . ' ';
		$out    = array( 'constraints' => array(), 'spans' => array(), 'labels' => array(), 'soft' => array() );
		$labels = self::BODY_LABELS[ 'en' === $language ? 'en' : 'es' ];
		$en     = 'en' === $language;

		// Plazas: "7 pasajeros", "para 8 personas", "de 5 asientos".
		if ( preg_match( '/[^0-9]([1-9][0-9]?)\s*(pasajeros|personas|asientos|plazas|passengers|people|seats|seater)[^a-z]/', $text, $m ) ) {
			$out['constraints']['seats'] = array( 'min' => (int) $m[1] );
			$out['spans'][]              = trim( $m[0] );
			$out['labels'][]             = $en ? $m[1] . ' seats' : 'de ' . $m[1] . ' pasajeros';
		}
		// Puertas.
		if ( preg_match( '/[^0-9]([2-5])\s*(puertas|doors|door)[^a-z]/', $text, $m ) ) {
			$out['constraints']['doors'] = array( 'in' => array( (int) $m[1] ) );
			$out['spans'][]              = trim( $m[0] );
			$out['labels'][]             = $en ? $m[1] . ' doors' : 'de ' . $m[1] . ' puertas';
		}
		// Tracción.
		if ( preg_match( '/[^a-z0-9](4 ?x ?4|4wd|doble traccion|traccion (en las|a las) (4|cuatro)|todo terreno|todoterreno|4 by 4)[^a-z0-9]/', $text, $m ) ) {
			$out['constraints']['traction'] = array( 'in' => array( '4x4' ) );
			$out['spans'][]                 = trim( $m[1] );
			$out['labels'][]                = '4x4';
		} elseif ( preg_match( '/[^a-z](awd|traccion integral|all wheel drive)[^a-z]/', $text, $m ) ) {
			$out['constraints']['traction'] = array( 'in' => array( 'awd', '4x4' ) );
			$out['spans'][]                 = trim( $m[1] );
			$out['labels'][]                = 'AWD';
		} elseif ( preg_match( '/[^a-z0-9](4 ?x ?2|2wd|fwd|rwd)[^a-z0-9]/', $text, $m ) ) {
			$out['constraints']['traction'] = array( 'in' => array( '4x2' ) );
			$out['spans'][]                 = trim( $m[1] );
			$out['labels'][]                = '4x2';
		}
		// Combustible.
		$fuels = array(
			'diesel'   => '(diesel|disel)',
			'hybrid'   => '(hibrido|hibrida|hibridos|hybrid)',
			'electric' => '(electrico|electrica|electricos|electric|ev)',
			'gasoline' => '(gasolina|gasoline|petrol)',
		);
		foreach ( $fuels as $fuel => $pattern ) {
			if ( preg_match( '/[^a-z]' . $pattern . '[^a-z]/', $text, $m ) ) {
				$names                      = $en
					? array( 'diesel' => 'diesel', 'hybrid' => 'hybrid', 'electric' => 'electric', 'gasoline' => 'gasoline' )
					: array( 'diesel' => 'diésel', 'hybrid' => 'híbridas', 'electric' => 'eléctricas', 'gasoline' => 'de gasolina' );
				$out['constraints']['fuel'] = array( 'in' => array( $fuel ) );
				$out['spans'][]             = trim( $m[1] );
				$out['labels'][]            = $names[ $fuel ];
				break;
			}
		}
		// Transmisión.
		if ( preg_match( '/[^a-z](automatico|automatica|automaticos|automaticas|automatic|cvt)[^a-z]/', $text, $m ) ) {
			$out['constraints']['transmission'] = array( 'in' => array( 'auto' ) );
			$out['spans'][]                     = trim( $m[1] );
			$out['labels'][]                    = $en ? 'automatic' : 'automáticas';
		} elseif ( preg_match( '/[^a-z](mecanico|mecanica|mecanicos|manual|estandar|de palanca|stick)[^a-z]/', $text, $m ) ) {
			$out['constraints']['transmission'] = array( 'in' => array( 'manual' ) );
			$out['spans'][]                     = trim( $m[1] );
			$out['labels'][]                    = $en ? 'manual' : 'mecánicas';
		}
		// Año: rango, "del 2020 en adelante", o un año suelto.
		if ( preg_match( '/(?:de|del|entre|from|between)\s+(19[5-9][0-9]|20[0-4][0-9])\s*(?:a|al|y|hasta|to|and|-)\s*(19[5-9][0-9]|20[0-4][0-9])/', $text, $m ) ) {
			$out['constraints']['year'] = array( 'min' => (int) $m[1], 'max' => (int) $m[2] );
			$out['spans'][]             = trim( $m[0] );
			$out['labels'][]            = $m[1] . '–' . $m[2];
		} elseif ( preg_match( '/(?:(?:del?|desde|modelo|ano|year|from)\s+)?(19[5-9][0-9]|20[0-4][0-9])\s*(?:en adelante|o mas nuevo|o mas reciente|para arriba|or newer|and up|\+)|(?:a partir del?|desde el|mas nuevo que|newer than)\s+(19[5-9][0-9]|20[0-4][0-9])/', $text, $m ) ) {
			$year                       = (int) ( ! empty( $m[1] ) ? $m[1] : $m[2] );
			$out['constraints']['year'] = array( 'min' => $year );
			$out['spans'][]             = trim( $m[0] );
			$out['labels'][]            = $en ? $year . ' or newer' : 'del ' . $year . ' en adelante';
		} elseif ( preg_match( '/[^0-9](19[5-9][0-9]|20[0-4][0-9])[^0-9]/', $text, $m ) ) {
			$out['constraints']['year'] = array( 'min' => (int) $m[1], 'max' => (int) $m[1] );
			$out['spans'][]             = trim( $m[1] );
			$out['labels'][]            = $m[1];
		}
		// Kilometraje: "menos de 50 mil km", "hasta 80,000 kms".
		if ( preg_match( '/(?:menos de|hasta|maximo|max|no mas de|under|less than|up to)\s+([0-9]{1,3}(?:[.,\s][0-9]{3})*|[0-9]+)\s*(mil|k)?\s*(?:km|kms|kilometros|millas|miles)[^a-z]/', $text, $m ) ) {
			$km                       = (int) preg_replace( '/[^0-9]/', '', $m[1] ) * ( empty( $m[2] ) ? 1 : 1000 );
			$out['constraints']['km'] = array( 'max' => $km );
			$out['spans'][]           = trim( $m[0] );
			$out['labels'][]          = $en ? 'under ' . number_format( $km ) . ' km' : 'de menos de ' . number_format( $km ) . ' km';
		}
		// Nuevo / usado.
		if ( preg_match( '/[^a-z](usado|usada|usados|usadas|seminuevo|seminueva|seminuevos|de segunda|used|pre owned)[^a-z]/', $text, $m ) ) {
			$out['constraints']['condition'] = array( 'in' => array( 'used' ) );
			$out['spans'][]                  = trim( $m[1] );
			$out['labels'][]                 = $en ? 'used' : 'usadas';
		} elseif ( preg_match( '/[^a-z](nuevo|nueva|nuevos|nuevas|0 ?km|cero kilometros|brand new)[^a-z]/', $text, $m ) && ! preg_match( '/nuevo ingreso|nuevos ingresos/', $text ) ) {
			$out['constraints']['condition'] = array( 'in' => array( 'new' ) );
			$out['spans'][]                  = trim( $m[1] );
			$out['labels'][]                 = $en ? 'new' : 'nuevas';
		}
		// Carrocería: se deja también en la búsqueda por palabras (no se quita del texto).
		foreach ( self::BODIES as $body => $words ) {
			foreach ( $words as $word ) {
				if ( strlen( $word ) <= 3 && ! in_array( $word, array( 'suv', 'van', 'bus' ), true ) ) {
					continue; // "hb", "ton": solo al leer fichas.
				}
				if ( preg_match( '/[^a-z]' . preg_quote( $word, '/' ) . 's?[^a-z]/', $text ) ) {
					$out['constraints']['body'] = array( 'in' => self::QUERY_BODIES[ $body ] );
					array_unshift( $out['labels'], $labels[ $body ] ); // "pickup 4x4 y diésel": el tipo va primero.
					$out['soft'][]              = $word;
					break 2;
				}
			}
		}
		return $out;
	}

	// ---------- Lectura de valores ----------

	private static function is_pickup_model( $title ) {
		$title = ' ' . self::fold( $title ) . ' ';
		foreach ( self::PICKUP_MODELS as $model ) {
			if ( preg_match( '/[^a-z0-9]' . preg_quote( $model, '/' ) . '[^a-z0-9]/', $title ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $text  Texto.
	 * @param bool   $exact Solo si el texto completo es esa carrocería (términos del sitio: "SUV",
	 *                      "PICK UP"), para no confundir una característica con un tipo.
	 */
	private static function body_of( $text, $exact = false ) {
		$folded = trim( self::fold( $text ) );
		$text   = ' ' . $folded . ' ';
		foreach ( self::BODIES as $body => $words ) {
			foreach ( $words as $word ) {
				if ( $exact ) {
					if ( $folded === $word || $folded === $word . 's' || $folded === $word . 'es' ) {
						return $body;
					}
					continue;
				}
				if ( preg_match( '/[^a-z0-9]' . preg_quote( $word, '/' ) . 's?[^a-z]/', $text ) ) {
					return $body;
				}
			}
		}
		return '';
	}

	private static function traction_of( $value ) {
		$value = ' ' . self::fold( $value ) . ' ';
		if ( preg_match( '/[^a-z0-9](4 ?x ?4|4wd|doble traccion)[^a-z0-9]/', $value ) ) {
			return '4x4';
		}
		if ( preg_match( '/[^a-z](awd|traccion integral)[^a-z]/', $value ) ) {
			return 'awd';
		}
		if ( preg_match( '/[^a-z0-9](4 ?x ?2|2wd|fwd|rwd|traccion delantera|traccion trasera)[^a-z0-9]/', $value ) ) {
			return '4x2';
		}
		return '';
	}

	private static function transmission_of( $value ) {
		$value = ' ' . self::fold( $value ) . ' ';
		if ( preg_match( '/[^a-z](automatic[oa]?|automatica|cvt|dct|tiptronic|secuencial)[^a-z]/', $value ) ) {
			return 'auto';
		}
		if ( preg_match( '/[^a-z](mecanic[oa]|manual|estandar)[^a-z]/', $value ) ) {
			return 'manual';
		}
		return '';
	}

	private static function fuel_of( $value, $exact = false ) {
		if ( $exact ) {
			$folded = trim( self::fold( $value ) );
			$map    = array(
				'diesel' => 'diesel', 'disel' => 'diesel', 'turbo diesel' => 'diesel', 'gasolina' => 'gasoline', 'gasoline' => 'gasoline',
				'hibrido' => 'hybrid', 'hibrida' => 'hybrid', 'hybrid' => 'hybrid', 'electrico' => 'electric', 'electrica' => 'electric',
				'electric' => 'electric', '100% electrico' => 'electric', 'glp' => 'lpg',
			);
			return isset( $map[ $folded ] ) ? $map[ $folded ] : '';
		}
		$value = ' ' . self::fold( $value ) . ' ';
		if ( preg_match( '/[^a-z](electrico|electrica|electric|100% electrico|ev)[^a-z]/', $value ) && ! preg_match( '/hibrid|hybrid/', $value ) ) {
			return 'electric';
		}
		if ( preg_match( '/[^a-z](hibrido|hibrida|hybrid|hev|phev|dm i|dm-i)[^a-z]/', $value ) ) {
			return 'hybrid';
		}
		if ( preg_match( '/[^a-z](diesel|disel|turbo diesel|crdi|tdi)[^a-z]/', $value ) ) {
			return 'diesel';
		}
		if ( preg_match( '/[^a-z](gasolina|gasoline|petrol|nafta)[^a-z]/', $value ) ) {
			return 'gasoline';
		}
		if ( preg_match( '/[^a-z](glp|lpg|gas propano)[^a-z]/', $value ) ) {
			return 'lpg';
		}
		return '';
	}

	/** Primer número de un texto ("1,815 mm" → 1815, "2.0 Ton" → 2.0, "5" → 5). */
	private static function number( $value ) {
		if ( ! preg_match( '/[0-9][0-9.,]*/', (string) $value, $m ) ) {
			return 0;
		}
		$raw = rtrim( $m[0], '.,' );
		if ( preg_match( '/^[0-9]{1,3}(,[0-9]{3})+$/', $raw ) ) {
			return (float) str_replace( ',', '', $raw );
		}
		return (float) str_replace( ',', '.', $raw );
	}

	private static function samples_are_years( array $samples ) {
		$samples = array_values( array_filter( $samples, 'is_scalar' ) );
		if ( ! $samples ) {
			return false;
		}
		foreach ( $samples as $sample ) {
			$sample = trim( (string) $sample );
			if ( '' !== $sample && ! preg_match( '/^(19[5-9][0-9]|20[0-4][0-9])$/', $sample ) ) {
				return false;
			}
		}
		return true;
	}

	private static function fold( $text ) {
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
		$text = strtr( $text, array( 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n' ) );
		return preg_replace( '/[^a-z0-9%.,\/ -]+/', ' ', $text );
	}
}
