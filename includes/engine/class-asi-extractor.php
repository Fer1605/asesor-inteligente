<?php
/**
 * Encuentra, dentro del texto de una página o ficha, las frases que responden una pregunta.
 *
 * Clase pura. No inventa nada: devuelve frases tal como están escritas en el sitio, para que el
 * chat conteste con el dato ("Atendemos de lunes a sábado de 9:00 a 18:00") y no solo con un enlace.
 *
 * Cada frase gana puntos por las palabras de la pregunta (valen más) y por las palabras que suelen
 * acompañar a ese tipo de respuesta ("lunes", "horario" para horarios; "teléfono" para contacto).
 *
 * @package AsesorInteligente
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'ASI_CLI_TESTS' ) ) {
	exit;
}

class ASI_Extractor {

	const MAX_CHARS     = 240;
	const MIN_WORDS     = 3;
	const MAX_SENTENCES = 400;

	/**
	 * Frases de un texto. Los títulos cortos sin punto ("Horario") se unen a la frase que sigue
	 * ("Horario: lunes a viernes…").
	 *
	 * @param string $text Texto original.
	 * @return string[]
	 */
	public static function sentences( $text ) {
		$text  = str_replace( array( "\r", ' . ' ), array( "\n", "\n" ), (string) $text );
		$parts = array();
		foreach ( explode( "\n", $text ) as $line ) {
			foreach ( preg_split( '/(?<=[.!?])\s+(?=[\p{Lu}¿¡0-9])/u', trim( $line ) ) as $piece ) {
				$piece = trim( $piece, " \t.;" );
				if ( '' !== $piece ) {
					$parts[] = $piece;
				}
			}
			if ( count( $parts ) > self::MAX_SENTENCES ) {
				break;
			}
		}

		$sentences = array();
		$heading   = '';
		foreach ( $parts as $part ) {
			$words = count( preg_split( '/\s+/u', $part ) );
			if ( $words <= 3 && ! preg_match( '/[0-9@]/', $part ) && ! preg_match( '/[.!?:]$/u', $part ) ) {
				$heading = $part; // Título: se une a la siguiente frase.
				continue;
			}
			$sentences[] = '' !== $heading ? $heading . ': ' . $part : $part;
			$heading     = '';
		}
		return $sentences;
	}

	/**
	 * Las mejores frases para una pregunta, en el orden en que aparecen.
	 *
	 * @param string   $text     Texto original.
	 * @param string[] $words    Palabras de la pregunta (forma normal). Si hay, al menos una tiene que estar.
	 * @param string[] $evidence Palabras que suelen acompañar la respuesta (forma normal).
	 * @param int      $max      Cuántas frases.
	 * @return string[]
	 */
	public static function best( $text, array $words, array $evidence = array(), $max = 1 ) {
		$words    = array_flip( array_filter( array_map( array( 'ASI_Lexicon', 'normal_form' ), $words ) ) );
		$evidence = array_flip( array_filter( array_map( array( 'ASI_Lexicon', 'normal_form' ), $evidence ) ) );
		$scored   = array();
		$all      = array();

		foreach ( self::sentences( $text ) as $position => $sentence ) {
			$tokens = array_flip( explode( ' ', ASI_Lexicon::normal_form( $sentence ) ) );
			$count  = count( $tokens );
			if ( $count < self::MIN_WORDS && ! preg_match( '/[0-9@]/', $sentence ) ) {
				continue;
			}
			$word_hits     = count( array_intersect_key( $words, $tokens ) );
			$evidence_hits = count( array_intersect_key( $evidence, $tokens ) );
			if ( $words && ! $word_hits ) {
				continue;
			}
			$score = 2 * $word_hits + $evidence_hits;
			// Horarios, teléfonos y direcciones casi siempre llevan números.
			if ( $evidence_hits && preg_match( '/[0-9]/', $sentence ) ) {
				$score += 0.5;
			}
			// Las frases largas diluyen la respuesta.
			$score -= max( 0, $count - 30 ) * 0.05;
			$all[ $position ] = array( $position, $score, self::shorten( $sentence ) );
			if ( $score >= 2 ) {
				$scored[] = $all[ $position ];
			}
		}

		usort(
			$scored,
			function ( $a, $b ) {
				return $a[1] === $b[1] ? $a[0] - $b[0] : ( $a[1] < $b[1] ? 1 : -1 );
			}
		);
		$best = array_slice( $scored, 0, $max );
		// La frase que sigue continúa la respuesta si también trae alguna pista ("Sábados de 8:00 a 12:00").
		foreach ( $best as $item ) {
			$next = $item[0] + 1;
			if ( count( $best ) < $max && isset( $all[ $next ] ) && $all[ $next ][1] >= 1.5 && ! in_array( $all[ $next ], $best, true ) ) {
				$best[] = $all[ $next ];
			}
		}
		usort(
			$best,
			function ( $a, $b ) {
				return $a[0] - $b[0];
			}
		);

		$out = array();
		foreach ( $best as $item ) {
			if ( ! in_array( $item[2], $out, true ) ) {
				$out[] = $item[2];
			}
		}
		return $out;
	}

	/**
	 * Recorta una frase larga sin cortar palabras y la cierra con punto.
	 *
	 * @param string $sentence Frase.
	 * @return string
	 */
	public static function shorten( $sentence ) {
		$sentence = trim( preg_replace( '/\s+/u', ' ', $sentence ) );
		$length   = function_exists( 'mb_strlen' ) ? mb_strlen( $sentence ) : strlen( $sentence );
		if ( $length > self::MAX_CHARS ) {
			$cut      = function_exists( 'mb_substr' ) ? mb_substr( $sentence, 0, self::MAX_CHARS ) : substr( $sentence, 0, self::MAX_CHARS );
			$sentence = preg_replace( '/\s+\S*$/u', '', $cut ) . '…';
		} elseif ( ! preg_match( '/[.!?…:]$/u', $sentence ) ) {
			$sentence .= '.';
		}
		return function_exists( 'mb_strtoupper' )
			? mb_strtoupper( mb_substr( $sentence, 0, 1 ) ) . mb_substr( $sentence, 1 )
			: ucfirst( $sentence );
	}
}
