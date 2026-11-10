<?php
/**
 * Normalización de texto para índice y consultas.
 *
 * Clase pura (sin WordPress). Minúsculas, sin acentos, sin signos; conserva
 * códigos alfanuméricos ("FGL25T", "5075E") y su forma compacta.
 *
 * @package AsesorInteligente
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'ASI_CLI_TESTS' ) ) {
	exit;
}

class ASI_Normalizer {

	/**
	 * Texto normalizado: minúsculas, sin acentos, solo letras/dígitos separados por un espacio.
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	public static function normalize( $text ) {
		$text = html_entity_decode( strip_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = self::remove_accents( self::lower( $text ) );
		$text = preg_replace( '/[^a-z0-9ñ]+/u', ' ', $text );
		return trim( preg_replace( '/\s+/', ' ', $text ) );
	}

	/**
	 * Tokens de un texto normalizado.
	 *
	 * @param string $text      Texto (se normaliza si hace falta).
	 * @param array  $stopwords Palabras a descartar (ya normalizadas).
	 * @return string[]
	 */
	public static function tokens( $text, array $stopwords = array() ) {
		$tokens = explode( ' ', self::normalize( $text ) );
		$tokens = array_filter(
			$tokens,
			function ( $token ) use ( $stopwords ) {
				return '' !== $token && ! isset( $stopwords[ $token ] );
			}
		);
		return array_values( $tokens );
	}

	/**
	 * Forma compacta de un código: sin espacios ni signos ("FGL-25 T" → "fgl25t").
	 *
	 * @param string $code Código.
	 * @return string
	 */
	public static function compact_code( $code ) {
		return preg_replace( '/[^a-z0-9]/', '', self::remove_accents( self::lower( (string) $code ) ) );
	}

	/**
	 * ¿Parece un código de producto/modelo? Mezcla letras y dígitos, 3 a 24 caracteres.
	 *
	 * @param string $token Token.
	 * @return bool
	 */
	public static function looks_like_code( $token ) {
		$compact = self::compact_code( $token );
		$length  = strlen( $compact );
		return $length >= 3 && $length <= 24 && preg_match( '/[0-9]/', $compact ) && preg_match( '/[a-z]/', $compact );
	}

	/**
	 * Singular aproximado en español/inglés, para comparar ("tractores" → "tractor", "forklifts" → "forklift").
	 *
	 * @param string $token Token normalizado.
	 * @return string
	 */
	public static function singular( $token ) {
		$length = strlen( $token );
		if ( $length <= 3 || preg_match( '/[0-9]/', $token ) ) {
			return $token;
		}
		if ( $length > 5 && preg_match( '/(ores|ones|ales|eres|ines|anes)$/', $token ) ) {
			return substr( $token, 0, -2 );
		}
		if ( $length > 4 && preg_match( '/ies$/', $token ) ) {
			return substr( $token, 0, -3 ) . 'y';
		}
		if ( $length > 4 && preg_match( '/(ches|shes|xes|sses)$/', $token ) ) {
			return substr( $token, 0, -2 );
		}
		if ( preg_match( '/[^s]s$/', $token ) ) {
			return substr( $token, 0, -1 );
		}
		return $token;
	}

	/**
	 * Minúsculas multibyte.
	 *
	 * @param string $text Texto.
	 * @return string
	 */
	private static function lower( $text ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/**
	 * Quita acentos conservando la ñ (en español cambia el significado: "año" ≠ "ano").
	 *
	 * @param string $text Texto en minúsculas.
	 * @return string
	 */
	private static function remove_accents( $text ) {
		return strtr(
			$text,
			array(
				'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
				'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
				'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
				'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
				'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
				'ç' => 'c', 'ß' => 'ss', 'œ' => 'oe', 'æ' => 'ae',
			)
		);
	}
}
