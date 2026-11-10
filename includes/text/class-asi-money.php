<?php
/**
 * Dinero: monedas, montos escritos por el cliente y conversión a la moneda base del sitio.
 *
 * Clase pura (sin WordPress). Reglas:
 *  - Todo filtro y todo orden por precio se hace en la moneda base del sitio. Nunca se compara un
 *    número en dólares con uno en quetzales.
 *  - La moneda de un producto sale de su campo de moneda (traducido con el mapa del panel), del
 *    símbolo de su precio ("$ 61,900") o, si no hay nada, se asume la base.
 *  - Los montos del cliente se leen de la frase original, con símbolos y separadores: "$30,000",
 *    "Q.250,000.00", "entre 100 y 200 mil", "1.5 millones", "presupuesto de 300 mil".
 *  - Un número seguido de una unidad ("50 mil km", "7 pasajeros", "2.5 toneladas") no es dinero.
 *
 * Configuración (arreglo $money):
 *  - base:  código ISO de la moneda base ('GTQ'). Vacío = no se convierte nada.
 *  - map:   valor guardado en el sitio => ISO, p. ej. array( '1' => 'USD', '2' => 'GTQ' ).
 *  - rates: ISO => cuántas unidades de la base vale 1 unidad de esa moneda (p. ej. 'USD' => 7.7).
 *           Si falta, se usa la tabla de referencia REFERENCE_PER_USD.
 *
 * @package AsesorInteligente
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'ASI_CLI_TESTS' ) ) {
	exit;
}

final class ASI_Money {

	/**
	 * Monedas conocidas: símbolo para mostrar, si el símbolo es "$", y cómo se nombran.
	 */
	const CURRENCIES = array(
		'USD' => array( 'symbol' => '$', 'dollar' => true, 'words' => array( 'dolar', 'dolares', 'dollar', 'dollars', 'usd', 'us', 'verdes' ) ),
		'GTQ' => array( 'symbol' => 'Q', 'dollar' => false, 'words' => array( 'quetzal', 'quetzales', 'gtq', 'q' ) ),
		'HNL' => array( 'symbol' => 'L', 'dollar' => false, 'words' => array( 'lempira', 'lempiras', 'hnl', 'lps' ) ),
		'NIO' => array( 'symbol' => 'C$', 'dollar' => false, 'words' => array( 'cordoba', 'cordobas', 'nio' ) ),
		'CRC' => array( 'symbol' => '₡', 'dollar' => false, 'words' => array( 'colon', 'colones', 'crc' ) ),
		'PAB' => array( 'symbol' => 'B/.', 'dollar' => false, 'words' => array( 'balboa', 'balboas', 'pab' ) ),
		'MXN' => array( 'symbol' => '$', 'dollar' => true, 'words' => array( 'mxn' ) ),
		'COP' => array( 'symbol' => '$', 'dollar' => true, 'words' => array( 'cop' ) ),
		'DOP' => array( 'symbol' => 'RD$', 'dollar' => false, 'words' => array( 'dop' ) ),
		'PEN' => array( 'symbol' => 'S/', 'dollar' => false, 'words' => array( 'sol', 'soles', 'pen' ) ),
		'CLP' => array( 'symbol' => '$', 'dollar' => true, 'words' => array( 'clp' ) ),
		'EUR' => array( 'symbol' => '€', 'dollar' => false, 'words' => array( 'euro', 'euros', 'eur' ) ),
	);

	/**
	 * Valores de referencia (unidades por 1 USD) para que funcione sin configurar. Aproximados: el
	 * panel avisa que hay que confirmarlos.
	 */
	const REFERENCE_PER_USD = array(
		'USD' => 1.0,
		'PAB' => 1.0,
		'GTQ' => 7.7,
		'HNL' => 26.0,
		'NIO' => 36.6,
		'CRC' => 505.0,
		'MXN' => 18.5,
		'COP' => 4000.0,
		'DOP' => 61.0,
		'PEN' => 3.7,
		'CLP' => 940.0,
		'EUR' => 0.92,
	);

	/**
	 * País (ISO-2) → moneda local, para deducir la base cuando nadie la configuró.
	 */
	const COUNTRY_CURRENCY = array(
		'gt' => 'GTQ', 'sv' => 'USD', 'hn' => 'HNL', 'ni' => 'NIO', 'cr' => 'CRC', 'pa' => 'USD',
		'mx' => 'MXN', 'co' => 'COP', 'do' => 'DOP', 'pe' => 'PEN', 'cl' => 'CLP', 'ec' => 'USD',
		'us' => 'USD', 'es' => 'EUR',
	);

	/**
	 * Unidades que, después de un número, dicen que no es dinero.
	 */
	const NOT_MONEY_UNITS = '(?:km|kms|kilometros?|kilometraje|millas|mi|cc|hp|caballos|ton|tons|toneladas?|kg|kilos?|libras?|lbs?|pasajeros?|personas?|asientos?|plazas?|puertas?|anos?|year|years|modelo|cilindros?|litros?|lts?|pulgadas?|pies|metros?|mts?|ninos?|hijos?|meses|cuotas?|seats?|doors?|people|passengers|miles|months|kids|children|tons?|feet|ft)\b';

	/**
	 * Configuración completa con valores por defecto.
	 *
	 * @param array $money base, map, rates.
	 * @return array
	 */
	public static function config( array $money ) {
		$money = array_merge( array( 'base' => '', 'map' => array(), 'rates' => array() ), $money );
		$money['base'] = strtoupper( (string) $money['base'] );
		return $money;
	}

	/**
	 * Moneda (ISO) a partir de un valor guardado en el sitio: el mapa del panel manda; luego códigos
	 * ISO, símbolos y nombres ("Dólares", "USD", "$", "Q.").
	 *
	 * @param mixed $raw   Valor.
	 * @param array $money Configuración.
	 * @return string ISO o '' si no se reconoce.
	 */
	public static function currency_from_value( $raw, array $money ) {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}
		$raw   = trim( (string) $raw );
		$money = self::config( $money );
		if ( '' === $raw ) {
			return '';
		}
		foreach ( (array) $money['map'] as $value => $iso ) {
			if ( (string) $value === $raw && isset( self::CURRENCIES[ strtoupper( (string) $iso ) ] ) ) {
				return strtoupper( (string) $iso );
			}
		}
		if ( is_numeric( $raw ) ) {
			return ''; // Un código numérico solo se entiende con el mapa.
		}
		$upper = strtoupper( $raw );
		if ( isset( self::CURRENCIES[ $upper ] ) ) {
			return $upper;
		}
		$found = self::currency_in_text( $raw, $money );
		return $found;
	}

	/**
	 * Primera moneda que aparece en un texto (símbolo o nombre). '' si ninguna.
	 *
	 * @param string $text  Texto original.
	 * @param array  $money Configuración.
	 * @return string
	 */
	public static function currency_in_text( $text, array $money ) {
		$text = self::fold( $text );
		if ( preg_match( '/(us\$|usd|rd\$|c\$|b\/\.|s\/|₡|€)/u', $text, $m ) ) {
			return self::symbol_to_iso( $m[1], $money );
		}
		if ( preg_match( '/(?:^|[^a-z])(q\.?|l\.|lps\.?)\s?[0-9]/', $text, $m ) ) {
			return self::symbol_to_iso( $m[1], $money );
		}
		if ( preg_match( '/\$/', $text ) ) {
			return self::symbol_to_iso( '$', $money );
		}
		foreach ( self::CURRENCIES as $iso => $info ) {
			foreach ( $info['words'] as $word ) {
				if ( strlen( $word ) <= 2 ) {
					continue; // "q", "us": solo como símbolo pegado a un número.
				}
				if ( preg_match( '/(?:^|[^a-z])' . preg_quote( $word, '/' ) . '(?:[^a-z]|$)/', $text ) ) {
					return $iso;
				}
			}
		}
		return '';
	}

	/**
	 * Base efectiva: la configurada; si no, la del país.
	 *
	 * @param string $configured ISO configurado.
	 * @param string $country    País del contexto (ISO-2).
	 * @return string
	 */
	public static function resolve_base( $configured, $country = '' ) {
		$configured = strtoupper( trim( (string) $configured ) );
		if ( isset( self::CURRENCIES[ $configured ] ) ) {
			return $configured;
		}
		$country = strtolower( (string) $country );
		return isset( self::COUNTRY_CURRENCY[ $country ] ) ? self::COUNTRY_CURRENCY[ $country ] : '';
	}

	/**
	 * Convierte un monto a la moneda base.
	 *
	 * @param float  $amount Monto.
	 * @param string $iso    Moneda del monto ('' = base).
	 * @param array  $money  Configuración.
	 * @return float|null null si no se puede convertir.
	 */
	public static function to_base( $amount, $iso, array $money ) {
		$money = self::config( $money );
		$iso   = strtoupper( (string) $iso );
		if ( '' === $money['base'] || '' === $iso || $iso === $money['base'] ) {
			return (float) $amount;
		}
		$rate = self::rate( $iso, $money );
		return null === $rate ? null : (float) $amount * $rate;
	}

	/**
	 * Cuántas unidades de la base vale 1 unidad de $iso.
	 *
	 * @param string $iso   Moneda.
	 * @param array  $money Configuración.
	 * @return float|null
	 */
	public static function rate( $iso, array $money ) {
		$money = self::config( $money );
		$iso   = strtoupper( (string) $iso );
		if ( $iso === $money['base'] ) {
			return 1.0;
		}
		if ( isset( $money['rates'][ $iso ] ) && (float) $money['rates'][ $iso ] > 0 ) {
			return (float) $money['rates'][ $iso ];
		}
		$ref = self::REFERENCE_PER_USD;
		if ( isset( $ref[ $iso ], $ref[ $money['base'] ] ) ) {
			return $ref[ $money['base'] ] / $ref[ $iso ];
		}
		return null;
	}

	/**
	 * Precio de un producto en la moneda base.
	 *
	 * @param array $card  Tarjeta del índice (price, currency_raw).
	 * @param array $money Configuración.
	 * @return array{amount:float|null,currency:string,base:float|null}
	 */
	public static function card_price( array $card, array $money ) {
		$money  = self::config( $money );
		$text   = isset( $card['price'] ) ? (string) $card['price'] : '';
		$amount = self::parse_display_price( $text );
		if ( null === $amount || $amount <= 0 ) {
			return array( 'amount' => null, 'currency' => '', 'base' => null );
		}
		$raw = isset( $card['currency_raw'] ) ? trim( (string) $card['currency_raw'] ) : '';
		$iso = '' !== $raw ? self::currency_from_value( $raw, $money ) : '';
		if ( '' !== $raw && '' === $iso ) {
			// Trae moneda pero no se sabe cuál: no se adivina (no entra en filtros por presupuesto).
			return array( 'amount' => $amount, 'currency' => '', 'base' => null );
		}
		if ( '' === $iso ) {
			$iso = self::currency_in_text( $text, $money );
		}
		if ( '' === $iso ) {
			$iso = $money['base'];
		}
		return array( 'amount' => $amount, 'currency' => $iso, 'base' => self::to_base( $amount, $iso, $money ) );
	}

	/**
	 * "Q 12,500.00" → 12500.0; "$1.299,90" → 1299.9; "61900" → 61900.0; sin números → null.
	 *
	 * @param string $raw Precio como se guarda o se muestra.
	 * @return float|null
	 */
	public static function parse_display_price( $raw ) {
		if ( ! preg_match( '/[0-9][0-9.,\s]*/', (string) $raw, $m ) ) {
			return null;
		}
		$number = preg_replace( '/\s/', '', rtrim( $m[0], " .," ) );
		if ( preg_match( '/^(.*)[.,](\d{1,2})$/', $number, $parts ) ) {
			return (float) ( preg_replace( '/[^0-9]/', '', $parts[1] ) . '.' . $parts[2] );
		}
		return (float) preg_replace( '/[^0-9]/', '', $number );
	}

	/**
	 * Monto con su símbolo: "Q 250,000", "$ 61,900".
	 *
	 * @param float  $amount Monto.
	 * @param string $iso    Moneda.
	 * @return string
	 */
	public static function format( $amount, $iso ) {
		$iso      = strtoupper( (string) $iso );
		$symbol   = isset( self::CURRENCIES[ $iso ] ) ? self::CURRENCIES[ $iso ]['symbol'] : '';
		$decimals = ( abs( $amount - round( $amount ) ) > 0.004 ) ? 2 : 0;
		return trim( $symbol . ' ' . number_format( (float) $amount, $decimals, '.', ',' ) );
	}

	/**
	 * Lee el presupuesto o rango de precio de una frase del cliente.
	 *
	 * @param string $message Frase original.
	 * @param array  $words   Palabras por idioma: max, min, between, between_join, budget, around (listas).
	 * @param array  $money   Configuración.
	 * @return array{min:float|null,max:float|null,currency:string,budget:bool,spans:string[]}
	 *         min/max en la moneda que dijo el cliente (currency; '' = no dijo → base).
	 */
	public static function extract_budget( $message, array $words, array $money ) {
		$money  = self::config( $money );
		$text   = self::fold( $message );
		$result = array( 'min' => null, 'max' => null, 'currency' => '', 'budget' => false, 'spans' => array() );
		$amount = self::amount_pattern();
		$alt    = static function ( $list ) {
			$list = array_filter( array_map( 'trim', (array) $list ) );
			usort(
				$list,
				static function ( $a, $b ) {
					return strlen( $b ) - strlen( $a );
				}
			);
			return $list ? implode( '|', array_map( static function ( $w ) { return preg_quote( $w, '/' ); }, $list ) ) : '(?!)';
		};
		$filler = '(?:\s+(?:de|del|es|es de|como|unos|unas|aproximadamente|aprox|maximo|max|of|is|about|around|max|up to))*';
		// Que el número no siga ("15000 km" no es "1500") ni lo siga una unidad ("50 mil km", "7 pasajeros").
		$end    = '(?![0-9]|[.,][0-9])(?!\s*(?:mil\s*|k\s*)?' . self::NOT_MONEY_UNITS . ')';

		// 1. Rango: "entre 100 y 200 mil", "de Q100,000 a Q150,000", "between 20k and 30k".
		$between = '/(?:^|[^a-z])(' . $alt( isset( $words['between'] ) ? $words['between'] : array() ) . ')\s+' . $amount . '\s*(?:' . $alt( isset( $words['between_join'] ) ? $words['between_join'] : array() ) . '|-)\s*' . $amount . $end . '/u';
		if ( preg_match( $between, $text, $m ) ) {
			$m = array_pad( $m, 10, '' );
			$a = self::read_amount( $m[2], $m[3], $m[4], $m[5] );
			$b = self::read_amount( $m[6], $m[7], $m[8], $m[9] );
			// "entre 100 y 200 mil": el multiplicador del segundo aplica al primero.
			if ( '' === trim( $m[4] ) && '' !== trim( $m[8] ) && $a['value'] < 1000 ) {
				$a['value'] *= self::multiplier( $m[8] );
			}
			$weak  = in_array( trim( $m[1] ), array( 'de', 'from', 'desde' ), true );
			$years = ! $a['marked'] && ! $b['marked'] && $a['value'] >= 1950 && $b['value'] <= 2040;
			if ( ! $years && $a['value'] > 0 && $b['value'] > $a['value'] && ( ! $weak || $a['marked'] || $b['marked'] || $b['value'] >= 1000 ) ) {
				$result['min']      = $a['value'];
				$result['max']      = $b['value'];
				$result['currency'] = $a['currency'] ? $a['currency'] : $b['currency'];
				$result['spans'][]  = $m[0];
				$text               = str_replace( $m[0], ' ', $text );
			}
		}

		// 2. Tope, piso y presupuesto.
		$kinds = array(
			'max'    => isset( $words['max'] ) ? $words['max'] : array(),
			'min'    => isset( $words['min'] ) ? $words['min'] : array(),
			'budget' => isset( $words['budget'] ) ? $words['budget'] : array(),
			'around' => isset( $words['around'] ) ? $words['around'] : array(),
		);
		foreach ( $kinds as $kind => $list ) {
			$pattern = '/(?:^|[^a-z])(' . $alt( $list ) . ')' . $filler . '\s*' . $amount . $end . '/u';
			if ( ! preg_match( $pattern, $text, $m ) ) {
				continue;
			}
			$m     = array_pad( $m, 6, '' );
			$value = self::read_amount( $m[2], $m[3], $m[4], $m[5] );
			// Con "menos de / hasta / más de" cualquier número es precio ("camisas de menos de 200").
			// Con "tengo", "unos", "como" hace falta moneda, multiplicador o un monto grande ("tengo 3").
			$loose = in_array( $kind, array( 'budget', 'around' ), true );
			if ( $value['value'] <= 0 || ( $loose && ! $value['marked'] && $value['value'] < 1000 ) ) {
				continue;
			}
			if ( 'max' === $kind || 'budget' === $kind ) {
				$result['max']    = null === $result['max'] ? $value['value'] : $result['max'];
				$result['budget'] = $result['budget'] || 'budget' === $kind;
			} elseif ( 'min' === $kind ) {
				$result['min'] = null === $result['min'] ? $value['value'] : $result['min'];
			} else {
				$result['min']    = null === $result['min'] ? round( $value['value'] * 0.85 ) : $result['min'];
				$result['max']    = null === $result['max'] ? round( $value['value'] * 1.15 ) : $result['max'];
				$result['budget'] = true;
			}
			$result['currency'] = $result['currency'] ? $result['currency'] : $value['currency'];
			$result['spans'][]  = $m[0];
			$text               = str_replace( $m[0], ' ', $text );
		}

		// 3. Monto suelto con moneda: "un carro de $30,000", "algo de 200 mil quetzales" → presupuesto.
		if ( null === $result['max'] && null === $result['min'] && preg_match( '/' . $amount . $end . '/u', $text, $m ) ) {
			$m     = array_pad( $m, 5, '' );
			$value = self::read_amount( $m[1], $m[2], $m[3], $m[4] );
			if ( $value['value'] > 0 && $value['marked'] && ( '' !== $value['currency'] || $value['value'] >= 1000 ) ) {
				$result['max']      = $value['value'];
				$result['budget']   = true;
				$result['currency'] = $value['currency'];
				$result['spans'][]  = $m[0];
			}
		}

		// Moneda dicha aparte: "en dólares", "precio en quetzales".
		if ( ( null !== $result['max'] || null !== $result['min'] ) && '' === $result['currency'] ) {
			$result['currency'] = self::currency_in_text( $message, $money );
		}
		if ( '' !== $result['currency'] ) {
			$result['currency'] = self::symbol_to_iso( $result['currency'], $money );
		}
		return $result;
	}

	/**
	 * Patrón de un monto: [prefijo de moneda] número [multiplicador] [moneda en palabras].
	 * Grupos: 1 prefijo, 2 número, 3 multiplicador, 4 moneda en palabras.
	 */
	private static function amount_pattern() {
		$words = array();
		foreach ( self::CURRENCIES as $info ) {
			foreach ( $info['words'] as $word ) {
				if ( strlen( $word ) > 2 ) {
					$words[] = preg_quote( $word, '/' );
				}
			}
		}
		return '(us\$|usd\s?|rd\$|c\$|b\/\.\s?|s\/\s?|\$\s?|q\.?\s?|gtq\s?|lps\.?\s?|l\.\s?|₡\s?|€\s?)?'
			. '([0-9]{1,3}(?:[.,\s][0-9]{3})+(?:[.,][0-9]{1,2})?|[0-9]+(?:[.,][0-9]+)?)'
			. '(\s*(?:mil|k|millon|millones|mill|mm)\b)?'
			. '(\s*(?:de\s+)?(?:' . implode( '|', $words ) . ')\b)?';
	}

	/**
	 * Valor de un monto ya separado en sus partes.
	 *
	 * @return array{value:float,currency:string,marked:bool} marked = traía moneda o multiplicador.
	 */
	private static function read_amount( $prefix, $number, $mult, $word ) {
		$prefix = trim( (string) $prefix );
		$word   = trim( preg_replace( '/^de\s+/', '', trim( (string) $word ) ) );
		$mult   = trim( (string) $mult );
		$value  = self::parse_number( $number, '' !== $mult ) * self::multiplier( $mult );
		$cur    = '' !== $prefix ? $prefix : $word;
		return array(
			'value'    => $value,
			'currency' => $cur,
			'marked'   => '' !== $prefix || '' !== $word || '' !== $mult,
		);
	}

	/**
	 * Número escrito: "250,000.00", "25.000", "1.5" (con multiplicador), "12 500".
	 */
	private static function parse_number( $raw, $has_multiplier ) {
		$raw = trim( (string) $raw );
		// "250,000.00" / "1.250.000,50": miles + decimales.
		if ( preg_match( '/^([0-9]{1,3}(?:[.,\s][0-9]{3})+)[.,]([0-9]{1,2})$/', $raw, $p ) ) {
			return (float) ( preg_replace( '/[^0-9]/', '', $p[1] ) . '.' . $p[2] );
		}
		// "250,000" / "25.000" / "12 500": solo miles (salvo "1.500 millones", que también son miles).
		if ( preg_match( '/^[0-9]{1,3}(?:[.,\s][0-9]{3})+$/', $raw ) ) {
			return (float) preg_replace( '/[^0-9]/', '', $raw );
		}
		// "1.5", "2,75": decimal.
		if ( preg_match( '/^([0-9]+)[.,]([0-9]+)$/', $raw, $p ) ) {
			return (float) ( $p[1] . '.' . $p[2] );
		}
		return (float) preg_replace( '/[^0-9]/', '', $raw );
	}

	private static function multiplier( $mult ) {
		$mult = trim( (string) $mult );
		if ( '' === $mult ) {
			return 1;
		}
		return in_array( $mult, array( 'mil', 'k' ), true ) ? 1000 : 1000000;
	}

	/**
	 * Símbolo o palabra → ISO. "$" es dólar salvo que la base use "$" (peso mexicano, etc.).
	 */
	private static function symbol_to_iso( $symbol, array $money ) {
		$symbol = trim( self::fold( $symbol ) );
		$symbol = rtrim( $symbol, ' ' );
		$fixed  = array(
			'us$' => 'USD', 'usd' => 'USD', 'rd$' => 'DOP', 'c$' => 'NIO', 'b/.' => 'PAB', 's/' => 'PEN',
			'₡'   => 'CRC', '€' => 'EUR', 'q' => 'GTQ', 'q.' => 'GTQ', 'gtq' => 'GTQ', 'l.' => 'HNL', 'lps' => 'HNL', 'lps.' => 'HNL',
		);
		if ( isset( $fixed[ $symbol ] ) ) {
			return $fixed[ $symbol ];
		}
		if ( '$' === $symbol ) {
			$base = strtoupper( (string) $money['base'] );
			return ( isset( self::CURRENCIES[ $base ] ) && self::CURRENCIES[ $base ]['dollar'] ) ? $base : 'USD';
		}
		foreach ( self::CURRENCIES as $iso => $info ) {
			if ( in_array( $symbol, $info['words'], true ) ) {
				return $iso;
			}
		}
		return isset( self::CURRENCIES[ strtoupper( $symbol ) ] ) ? strtoupper( $symbol ) : '';
	}

	/**
	 * Minúsculas y sin acentos, conservando símbolos, cifras y separadores.
	 */
	public static function fold( $text ) {
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
		return strtr(
			$text,
			array( 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', ' ' => ' ' )
		);
	}
}
