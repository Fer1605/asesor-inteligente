<?php
/**
 * Clasificador de intención.
 *
 * Clase pura, en dos pasos:
 *  1. Anclas: una intención solo es candidata si en el mensaje aparece alguna de sus palabras ancla.
 *     Así una búsqueda normal ("busco tractores") nunca se confunde con "¿dónde están?".
 *  2. Bayes ingenuo entrenado con las frases de ejemplo del idioma: desempata entre candidatas
 *     ("hola, ¿cuál es su teléfono?" → contacto, no saludo).
 *
 * Si no hay candidatas, la intención es "search".
 *
 * @package AsesorInteligente
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'ASI_CLI_TESTS' ) ) {
	exit;
}

class ASI_Intent_Classifier {

	/** Intenciones de conversación (no piden información del sitio). */
	const SMALLTALK = array( 'greeting', 'thanks', 'goodbye', 'help' );

	/** @var ASI_Lexicon */
	private $lexicon;

	/** @var array<string,array> Modelo por intención: tokens => frecuencia, total. */
	private $model = array();

	/** @var int */
	private $vocabulary_size = 1;

	public function __construct( ASI_Lexicon $lexicon ) {
		$this->lexicon = $lexicon;
		$this->train();
	}

	/**
	 * Clasifica un mensaje normalizado.
	 *
	 * @param string $normalized Mensaje normalizado.
	 * @return array intent, confidence (0-1), anchor (texto que la activó), candidates (string[])
	 */
	public function classify( $normalized ) {
		$candidates = array();
		foreach ( $this->lexicon->intents() as $intent => $definition ) {
			$anchor = ASI_Lexicon::find_phrase( $normalized, isset( $definition['anchors'] ) ? $definition['anchors'] : array() );
			if ( '' !== $anchor ) {
				$candidates[ $intent ] = $anchor;
			}
		}

		if ( ! $candidates ) {
			return array( 'intent' => 'search', 'confidence' => 1.0, 'anchor' => '', 'candidates' => array() );
		}
		if ( 1 === count( $candidates ) ) {
			$intent = key( $candidates );
			return array( 'intent' => $intent, 'confidence' => 1.0, 'anchor' => $candidates[ $intent ], 'candidates' => array( $intent ) );
		}

		// Varias candidatas: las informativas ganan a las de conversación ("hola, ¿dónde están?").
		$scores = array();
		$tokens = explode( ' ', $normalized );
		foreach ( $candidates as $intent => $anchor ) {
			$scores[ $intent ] = $this->log_probability( $intent, $tokens ) + str_word_count( $anchor ) * 0.5
				+ ( in_array( $intent, self::SMALLTALK, true ) ? -2.0 : 0.0 );
		}
		arsort( $scores );
		$best = key( $scores );

		return array(
			'intent'     => $best,
			'confidence' => $this->softmax_top( $scores ),
			'anchor'     => $candidates[ $best ],
			'candidates' => array_keys( $scores ),
		);
	}

	/**
	 * ¿El mensaje es solo conversación (saludo, gracias…) sin nada más que buscar?
	 *
	 * @param string $intent Intención.
	 * @return bool
	 */
	public static function is_smalltalk( $intent ) {
		return in_array( $intent, self::SMALLTALK, true );
	}

	/**
	 * Entrena el modelo con las frases de ejemplo y las anclas de cada intención.
	 */
	private function train() {
		$all = array();
		foreach ( $this->lexicon->intents() as $intent => $definition ) {
			$phrases = array_merge(
				isset( $definition['training'] ) ? (array) $definition['training'] : array(),
				isset( $definition['anchors'] ) ? (array) $definition['anchors'] : array()
			);
			$counts = array();
			$total  = 0;
			foreach ( $phrases as $phrase ) {
				foreach ( explode( ' ', ASI_Normalizer::normalize( $phrase ) ) as $token ) {
					if ( '' === $token ) {
						continue;
					}
					$counts[ $token ] = isset( $counts[ $token ] ) ? $counts[ $token ] + 1 : 1;
					$all[ $token ]    = true;
					$total++;
				}
			}
			$this->model[ $intent ] = array( 'counts' => $counts, 'total' => $total );
		}
		$this->vocabulary_size = max( 1, count( $all ) );
	}

	/**
	 * log P(mensaje | intención) con suavizado de Laplace.
	 *
	 * @param string   $intent Intención.
	 * @param string[] $tokens Tokens.
	 * @return float
	 */
	private function log_probability( $intent, array $tokens ) {
		$model = $this->model[ $intent ];
		$score = 0.0;
		foreach ( $tokens as $token ) {
			$count  = isset( $model['counts'][ $token ] ) ? $model['counts'][ $token ] : 0;
			$score += log( ( $count + 1 ) / ( $model['total'] + $this->vocabulary_size ) );
		}
		return $score;
	}

	/**
	 * Probabilidad de la mejor opción frente a las demás.
	 *
	 * @param array $scores Puntajes logarítmicos ordenados de mayor a menor.
	 * @return float
	 */
	private function softmax_top( array $scores ) {
		$max = reset( $scores );
		$sum = 0.0;
		foreach ( $scores as $score ) {
			$sum += exp( $score - $max );
		}
		return round( 1 / $sum, 3 );
	}
}
