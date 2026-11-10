<?php
/**
 * Generador de respuestas en lenguaje natural.
 *
 * Clase pura. Elige una variante de cada tipo de respuesta evitando las usadas hace poco
 * (la memoria de frases viaja en el estado de la conversación), rellena los marcadores con
 * datos reales y concuerda plurales. Así dos respuestas seguidas no suenan igual.
 *
 * @package AsesorInteligente
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'ASI_CLI_TESTS' ) ) {
	exit;
}

class ASI_Responder {

	const MEMORY = 16;

	/** @var ASI_Lexicon */
	private $lexicon;

	/** @var array Variables fijas de la conversación (site, en_pais, saludo). */
	private $base_vars;

	/** @var string[] Variantes usadas recientemente ("results.3"). */
	private $recent;

	/** @var callable Número aleatorio entre 0 y $max. */
	private $random;

	/** @var ASI_Tone */
	private $tone;

	/**
	 * @param ASI_Lexicon    $lexicon Idioma.
	 * @param array         $context site_name, country_name, multi_country, hour, tone ('tu'|'usted'), emojis.
	 * @param string[]      $recent  Variantes usadas recientemente.
	 * @param callable|null $random  Generador (para pruebas); por defecto mt_rand.
	 */
	public function __construct( ASI_Lexicon $lexicon, array $context, array $recent = array(), $random = null ) {
		$this->lexicon = $lexicon;
		$this->recent  = array_slice( array_values( array_filter( $recent, 'is_string' ) ), -self::MEMORY );
		$this->random  = $random ? $random : function ( $max ) {
			return mt_rand( 0, $max );
		};

		$this->tone = new ASI_Tone( $lexicon, isset( $context['tone'] ) ? $context['tone'] : 'tu', ! empty( $context['emojis'] ) );

		$hour  = isset( $context['hour'] ) ? (int) $context['hour'] : 12;
		$greet = $hour < 12 ? 'morning' : ( $hour < 19 ? 'afternoon' : 'evening' );

		$this->base_vars = array(
			'site'    => isset( $context['site_name'] ) ? $context['site_name'] : '',
			// El país solo se menciona si el sitio es multipaís: en un sitio de un país sobra (y el locale puede ser genérico).
			'en_pais' => ! empty( $context['multi_country'] ) && ! empty( $context['country_name'] ) ? ' ' . $lexicon->word( 'in' ) . ' ' . $context['country_name'] : '',
			'saludo'  => $lexicon->word( $greet ),
		);
	}

	/**
	 * Una variante de la respuesta $key con los marcadores rellenos. '' si no hay variantes.
	 *
	 * @param string $key  Tipo ("results", "info.contact"…).
	 * @param array  $vars Marcadores.
	 * @return string
	 */
	public function say( $key, array $vars = array() ) {
		$variants = $this->lexicon->responses( $key );
		if ( ! $variants ) {
			return '';
		}

		$fresh = array();
		foreach ( $variants as $index => $text ) {
			if ( ! in_array( $key . '.' . $index, $this->recent, true ) ) {
				$fresh[] = $index;
			}
		}
		if ( ! $fresh ) {
			// Todas usadas: se libera la memoria de este tipo y se evita solo la última.
			$last  = $this->last_used( $key );
			$fresh = array_values( array_diff( array_keys( $variants ), array( $last ) ) );
			$fresh = $fresh ? $fresh : array_keys( $variants );
		}

		$index          = $fresh[ call_user_func( $this->random, count( $fresh ) - 1 ) ];
		$this->recent[] = $key . '.' . $index;
		$this->recent   = array_slice( $this->recent, -self::MEMORY );

		return $this->tone->decorate( $key, $this->fill( $this->tone->template( $variants[ $index ] ), $vars ) );
	}

	/**
	 * Variantes usadas (para guardarlas en el estado de la conversación).
	 *
	 * @return string[]
	 */
	public function recent() {
		return $this->recent;
	}

	/**
	 * "3 opciones" / "1 opción".
	 *
	 * @param int $count Cantidad.
	 * @return string
	 */
	public function count_options( $count ) {
		return $count . ' ' . ( 1 === (int) $count ? $this->lexicon->word( 'option' ) : $this->lexicon->word( 'options' ) );
	}

	/**
	 * "a, b y c" (o "a, b o c").
	 *
	 * @param string[] $items       Elementos.
	 * @param string   $conjunction Palabra de unión ('and' u 'or').
	 * @return string
	 */
	public function join_list( array $items, $conjunction = 'and' ) {
		$items = array_values( array_filter( array_map( 'strval', $items ) ) );
		if ( count( $items ) <= 1 ) {
			return implode( '', $items );
		}
		$last = array_pop( $items );
		return implode( ', ', $items ) . ' ' . $this->lexicon->word( $conjunction ) . ' ' . $last;
	}

	/**
	 * Rellena marcadores y limpia espacios dobles o signos sueltos.
	 *
	 * @param string $text Plantilla.
	 * @param array  $vars Marcadores.
	 * @return string
	 */
	private function fill( $text, array $vars ) {
		$vars    = array_merge( $this->base_vars, $vars );
		$replace = array();
		foreach ( $vars as $name => $value ) {
			$replace[ '{' . $name . '}' ] = (string) $value;
		}
		$text = strtr( $text, $replace );
		$text = preg_replace( '/\{[a-z_]+\}/', '', $text );
		$text = preg_replace( '/\s+([,.:;!?])/u', '$1', $text );
		$text = preg_replace( '/“\s*”/u', '', $text );
		return trim( preg_replace( '/\s{2,}/', ' ', $text ) );
	}

	/**
	 * Última variante usada de un tipo.
	 *
	 * @param string $key Tipo.
	 * @return int|null
	 */
	private function last_used( $key ) {
		for ( $i = count( $this->recent ) - 1; $i >= 0; $i-- ) {
			if ( 0 === strpos( $this->recent[ $i ], $key . '.' ) ) {
				$index = substr( $this->recent[ $i ], strlen( $key ) + 1 );
				if ( ctype_digit( $index ) ) {
					return (int) $index;
				}
			}
		}
		return null;
	}
}
