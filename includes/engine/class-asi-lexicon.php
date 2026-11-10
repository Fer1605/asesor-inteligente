<?php
/**
 * Conocimiento de un idioma: palabras vacías, sinónimos, intenciones y respuestas.
 *
 * Clase pura. Carga includes/lang/{idioma}.php; un idioma sin archivo usa el inglés.
 * En WordPress, el filtro "asi_lexicon" permite agregar sinónimos o frases sin tocar el plugin.
 *
 * @package AsesorInteligente
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'ASI_CLI_TESTS' ) ) {
	exit;
}

class ASI_Lexicon {

	/** @var array<string,ASI_Lexicon> */
	private static $loaded = array();

	/** @var string */
	public $code;

	/** @var array Datos crudos del archivo de idioma. */
	private $data;

	/** @var array<string,bool> */
	private $stopwords = array();

	/** @var array<string,bool> */
	private $fillers = array();

	/** @var array<string,string[]> singular normalizado => grupo de sinónimos (normalizados). */
	private $synonym_index = array();

	/**
	 * Lexicón de un idioma (cacheado).
	 *
	 * @param string $code        Idioma de 2 letras.
	 * @param array  $extra_synonyms Grupos adicionales (del admin).
	 * @return ASI_Lexicon
	 */
	public static function get( $code, array $extra_synonyms = array() ) {
		$code = preg_match( '/^[a-z]{2}$/', (string) $code ) ? $code : 'en';
		$key  = $code . md5( serialize( $extra_synonyms ) );
		if ( ! isset( self::$loaded[ $key ] ) ) {
			self::$loaded[ $key ] = new self( $code, $extra_synonyms );
		}
		return self::$loaded[ $key ];
	}

	/**
	 * Idiomas con archivo propio.
	 *
	 * @return string[]
	 */
	public static function available() {
		$codes = array();
		foreach ( glob( dirname( __DIR__ ) . '/lang/*.php' ) as $file ) {
			$codes[] = basename( $file, '.php' );
		}
		return $codes;
	}

	private function __construct( $code, array $extra_synonyms ) {
		$file = dirname( __DIR__ ) . '/lang/' . $code . '.php';
		if ( ! file_exists( $file ) ) {
			$code = 'en';
			$file = dirname( __DIR__ ) . '/lang/en.php';
		}
		$data = require $file;
		if ( function_exists( 'apply_filters' ) ) {
			$data = apply_filters( 'asi_lexicon', $data, $code );
		}

		$this->code = $code;
		$this->data = $data;

		foreach ( $data['stopwords'] as $word ) {
			$this->stopwords[ ASI_Normalizer::normalize( $word ) ] = true;
		}
		foreach ( $data['fillers'] as $word ) {
			$this->fillers[ ASI_Normalizer::normalize( $word ) ] = true;
		}
		foreach ( array_merge( $data['synonyms'], $extra_synonyms ) as $group ) {
			$group = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'normal_form' ), (array) $group ) ) ) );
			foreach ( $group as $word ) {
				$this->synonym_index[ $word ] = isset( $this->synonym_index[ $word ] )
					? array_values( array_unique( array_merge( $this->synonym_index[ $word ], $group ) ) )
					: $group;
			}
		}
	}

	/**
	 * Forma de comparación de una palabra o frase: normalizada y en singular aproximado.
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	public static function normal_form( $text ) {
		$tokens = ASI_Normalizer::tokens( $text );
		return implode( ' ', array_map( array( 'ASI_Normalizer', 'singular' ), $tokens ) );
	}

	/** Idioma de 2 letras de este lexicón. */
	public function language() {
		return $this->code;
	}

	public function is_stopword( $token ) {
		return isset( $this->stopwords[ $token ] );
	}

	public function is_filler( $token ) {
		return isset( $this->fillers[ $token ] );
	}

	/**
	 * Sinónimos de una palabra o frase (en forma normal), sin incluirla.
	 *
	 * @param string $normal_form Forma normal.
	 * @return string[]
	 */
	public function synonyms( $normal_form ) {
		if ( ! isset( $this->synonym_index[ $normal_form ] ) ) {
			return array();
		}
		return array_values( array_diff( $this->synonym_index[ $normal_form ], array( $normal_form ) ) );
	}

	/**
	 * Lista cruda del archivo de idioma.
	 *
	 * @param string $key Clave.
	 * @return mixed
	 */
	public function get_list( $key ) {
		return isset( $this->data[ $key ] ) ? $this->data[ $key ] : array();
	}

	/**
	 * Definición de las intenciones.
	 *
	 * @return array
	 */
	public function intents() {
		return $this->get_list( 'intents' );
	}

	/**
	 * Variantes de una respuesta. $key admite "info.contact".
	 *
	 * @param string $key Clave.
	 * @return string[]
	 */
	public function responses( $key ) {
		$node = $this->get_list( 'responses' );
		foreach ( explode( '.', $key ) as $part ) {
			if ( ! is_array( $node ) || ! isset( $node[ $part ] ) ) {
				return array();
			}
			$node = $node[ $part ];
		}
		return (array) $node;
	}

	/**
	 * Palabra de apoyo ("option", "and"…).
	 *
	 * @param string $key Clave.
	 * @return string
	 */
	public function word( $key ) {
		$words = $this->get_list( 'words' );
		return isset( $words[ $key ] ) ? $words[ $key ] : $key;
	}

	/**
	 * Clave estable de una búsqueda: sus palabras con contenido, en forma normal y ordenadas.
	 * "Busco los montacargas eléctricos" y "montacargas electrico" dan la misma clave.
	 * La usan el aprendizaje por clics y las respuestas enseñadas desde el panel.
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	public function query_key( $text ) {
		$words = array();
		foreach ( explode( ' ', ASI_Normalizer::normalize( $text ) ) as $token ) {
			if ( '' === $token || $this->is_stopword( $token ) || $this->is_filler( $token ) ) {
				continue;
			}
			$words[ ASI_Normalizer::looks_like_code( $token ) ? $token : ASI_Normalizer::singular( $token ) ] = true;
		}
		$words = array_keys( $words );
		sort( $words );
		return substr( implode( ' ', $words ), 0, 190 );
	}

	/**
	 * Formas normales de una lista del idioma (para comparar con texto normalizado).
	 *
	 * @param string $key Clave de la lista.
	 * @return string[]
	 */
	public function normal_list( $key ) {
		return array_values( array_filter( array_map( array( __CLASS__, 'normal_form' ), (array) $this->get_list( $key ) ) ) );
	}

	/**
	 * ¿El texto normalizado contiene la frase (normalizada) como palabras completas?
	 *
	 * @param string $normalized Texto normalizado.
	 * @param string $phrase     Frase.
	 * @return bool
	 */
	public static function contains_phrase( $normalized, $phrase ) {
		$phrase = ASI_Normalizer::normalize( $phrase );
		return '' !== $phrase && false !== strpos( ' ' . $normalized . ' ', ' ' . $phrase . ' ' );
	}

	/**
	 * ¿Contiene alguna de las frases? Devuelve la más larga encontrada o ''.
	 *
	 * @param string   $normalized Texto normalizado.
	 * @param string[] $phrases    Frases.
	 * @return string
	 */
	public static function find_phrase( $normalized, array $phrases ) {
		$found = '';
		foreach ( $phrases as $phrase ) {
			if ( strlen( $phrase ) > strlen( $found ) && self::contains_phrase( $normalized, $phrase ) ) {
				$found = ASI_Normalizer::normalize( $phrase );
			}
		}
		return $found;
	}

	/**
	 * Idioma más probable de un mensaje entre los disponibles, por palabras vacías.
	 * Devuelve '' si el mensaje es muy corto o ambiguo.
	 *
	 * @param string   $normalized Texto normalizado.
	 * @param string[] $candidates Idiomas a considerar.
	 * @return string
	 */
	public static function detect_language( $normalized, array $candidates ) {
		$tokens = explode( ' ', $normalized );
		if ( count( $tokens ) < 3 ) {
			return '';
		}
		$scores = array();
		foreach ( $candidates as $code ) {
			$lexicon         = self::get( $code );
			$scores[ $code ] = 0;
			foreach ( $tokens as $token ) {
				if ( $lexicon->is_stopword( $token ) || $lexicon->is_filler( $token ) ) {
					$scores[ $code ]++;
				}
			}
		}
		arsort( $scores );
		$codes = array_keys( $scores );
		$best  = $scores[ $codes[0] ];
		$next  = isset( $codes[1] ) ? $scores[ $codes[1] ] : 0;
		return ( $best >= 2 && $best >= $next * 2 ) ? $codes[0] : '';
	}
}
