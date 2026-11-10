<?php
/**
 * Pack autos: qué vehículo se parece a cuál ("algo parecido al Frontier").
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || defined( 'ASI_CLI_TESTS' ) || exit;

trait ASI_Autos_Similar {

	/** Peso de cada dato en el parecido (la carrocería se exige aparte). */
	private static $similar_weights = array(
		'price_base'   => 2.0,
		'traction'     => 1.5,
		'fuel'         => 1.2,
		'seats'        => 1.2,
		'cc'           => 1.0,
		'transmission' => 0.4,
		'year'         => 0.4,
		'condition'    => 0.4,
	);

	public function reference_model( $text, $language ) {
		static $models = null;
		if ( null === $models ) {
			$models = require __DIR__ . '/models.php';
		}
		$text = ' ' . str_replace( '-', ' ', self::fold( $text ) ) . ' ';
		// La clave más larga primero ("mazda 3" antes que un "3" suelto).
		$keys = array_keys( $models );
		usort(
			$keys,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		foreach ( $keys as $key ) {
			if ( preg_match( '/[^a-z0-9]' . preg_quote( str_replace( '-', ' ', $key ), '/' ) . '[^a-z0-9]/', $text ) ) {
				$model = $models[ $key ];
				$lang  = 'en' === $language ? 'en' : 'es';
				return array(
					'key'    => $key,
					'name'   => $model['name'],
					'brand'  => $model['brand'],
					'attrs'  => array_intersect_key( $model, array_flip( array( 'body', 'seats', 'traction', 'fuel', 'cc' ) ) ),
					'traits' => isset( $model['traits'][ $lang ] ) ? $model['traits'][ $lang ] : '',
				);
			}
		}
		return null;
	}

	public function similarity( array $a, array $b ) {
		if ( isset( $a['body'], $b['body'] ) && ! self::same_family( $a['body'], $b['body'] ) ) {
			return 0.0;
		}
		$total = 0.0;
		$score = 0.0;
		foreach ( self::$similar_weights as $key => $weight ) {
			if ( ! isset( $a[ $key ], $b[ $key ] ) || '' === $a[ $key ] || '' === $b[ $key ] ) {
				continue;
			}
			$total += $weight;
			$score += $weight * self::closeness( $key, $a[ $key ], $b[ $key ] );
		}
		return $total > 0 ? round( $score / $total, 4 ) : 0.5;
	}

	/** Qué tan cerca están dos valores de un dato (0 a 1). */
	private static function closeness( $key, $x, $y ) {
		switch ( $key ) {
			case 'price_base':
			case 'cc':
				$x = max( 1.0, (float) $x );
				$y = max( 1.0, (float) $y );
				// 0 % de diferencia = 1; el doble o la mitad = 0.
				return max( 0.0, 1 - abs( log( $x / $y ) ) / log( 2 ) );
			case 'seats':
				return max( 0.0, 1 - abs( (int) $x - (int) $y ) / 3 );
			case 'year':
				return max( 0.0, 1 - abs( (int) $x - (int) $y ) / 6 );
			case 'traction':
				if ( $x === $y ) {
					return 1.0;
				}
				return in_array( $x, array( '4x4', 'awd' ), true ) && in_array( $y, array( '4x4', 'awd' ), true ) ? 0.6 : 0.0;
			case 'fuel':
				if ( $x === $y ) {
					return 1.0;
				}
				$electrified = array( 'hybrid', 'electric' );
				return ( in_array( $x, $electrified, true ) && in_array( $y, $electrified, true ) ) ? 0.6 : ( in_array( 'hybrid', array( $x, $y ), true ) && in_array( 'gasoline', array( $x, $y ), true ) ? 0.4 : 0.0 );
			default:
				return (string) $x === (string) $y ? 1.0 : 0.0;
		}
	}

	/** Sedán, hatchback y automóvil son de la misma familia; lo demás tiene que coincidir. */
	private static function same_family( $a, $b ) {
		$cars = array( 'sedan', 'hatchback', 'car', 'coupe' );
		return $a === $b || ( in_array( $a, $cars, true ) && in_array( $b, $cars, true ) );
	}

	public function describe( array $attrs, $language ) {
		$es    = 'en' !== $language;
		$names = self::$reco_names[ $es ? 'es' : 'en' ];
		$parts = array();
		$body  = isset( $attrs['body'] ) && isset( $names[ $attrs['body'] ] ) ? $names[ $attrs['body'] ] : ( $es ? 'un vehículo' : 'a vehicle' );
		if ( 'pickup' === ( isset( $attrs['body'] ) ? $attrs['body'] : '' ) && isset( $attrs['seats'] ) && $attrs['seats'] >= 5 ) {
			$body .= $es ? ' doble cabina' : ' crew cab';
		} elseif ( isset( $attrs['seats'] ) && $attrs['seats'] >= 7 ) {
			$body .= $es ? ' de ' . $attrs['seats'] . ' plazas' : ' with ' . $attrs['seats'] . ' seats';
		}
		if ( isset( $attrs['traction'] ) && '4x2' !== $attrs['traction'] ) {
			$parts[] = strtoupper( $attrs['traction'] );
		}
		$fuels = $es
			? array( 'diesel' => 'diésel', 'gasoline' => 'de gasolina', 'hybrid' => 'híbrida', 'electric' => 'eléctrica', 'lpg' => 'a gas' )
			: array( 'diesel' => 'diesel', 'gasoline' => 'gasoline', 'hybrid' => 'hybrid', 'electric' => 'electric', 'lpg' => 'LPG' );
		if ( isset( $attrs['fuel'] ) && isset( $fuels[ $attrs['fuel'] ] ) ) {
			$parts[] = $fuels[ $attrs['fuel'] ];
		}
		if ( isset( $attrs['cc'] ) && $attrs['cc'] >= 600 ) {
			$parts[] = ( $es ? 'motor ' : '' ) . number_format( $attrs['cc'] / 1000, 1 ) . ( $es ? ' L' : 'L engine' );
		}
		$and = $es ? ' y ' : ' and ';
		if ( ! $parts ) {
			return $body;
		}
		$last = array_pop( $parts );
		return $body . ', ' . ( $parts ? implode( ', ', $parts ) . $and : '' ) . $last;
	}

	public function why_similar( array $reference, array $other, $language ) {
		$es     = 'en' !== $language;
		$shared = array();
		if ( isset( $reference['traction'], $other['traction'] ) && $reference['traction'] === $other['traction'] && '4x2' !== $reference['traction'] ) {
			$shared[] = strtoupper( $reference['traction'] );
		}
		if ( isset( $reference['fuel'], $other['fuel'] ) && $reference['fuel'] === $other['fuel'] ) {
			$shared[] = $es ? array( 'diesel' => 'diésel', 'gasoline' => 'de gasolina', 'hybrid' => 'híbridas', 'electric' => 'eléctricas', 'lpg' => 'a gas' )[ $reference['fuel'] ] : $reference['fuel'];
		}
		if ( isset( $reference['seats'], $other['seats'] ) && $reference['seats'] === $other['seats'] ) {
			$shared[] = $es ? 'de ' . $reference['seats'] . ' pasajeros' : $reference['seats'] . ' seats';
		}
		if ( isset( $reference['price_base'], $other['price_base'] ) && abs( log( max( 1, $reference['price_base'] ) / max( 1, $other['price_base'] ) ) ) < 0.2 ) {
			$shared[] = $es ? 'de precio parecido' : 'similarly priced';
		}
		if ( ! $shared ) {
			return '';
		}
		$last = array_pop( $shared );
		return ( $shared ? implode( ', ', $shared ) . ( $es ? ' y ' : ' and ' ) : '' ) . $last;
	}
}
