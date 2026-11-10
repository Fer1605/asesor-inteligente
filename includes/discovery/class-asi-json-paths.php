<?php
/**
 * Recorre JSON guardado en campos meta.
 *
 * Clase pura. Aplana un JSON a rutas con puntos ("detalle.codigoPrincipal"); las listas no
 * agregan índice ("caracteristicas.detalle.valor"), así todas las filas comparten el rol de su ruta.
 *
 * @package AsesorInteligente
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'ASI_CLI_TESTS' ) ) {
	exit;
}

class ASI_Json_Paths {

	const MAX_DEPTH = 6;
	const MAX_LEAVES = 400;

	/**
	 * Hojas del JSON como pares [ruta, valor escalar].
	 *
	 * @param mixed  $value  JSON decodificado.
	 * @param string $prefix Ruta acumulada.
	 * @param int    $depth  Profundidad.
	 * @return array[] Lista de [ruta, valor].
	 */
	public static function flatten( $value, $prefix = '', $depth = 0 ) {
		if ( is_object( $value ) ) {
			$value = (array) $value;
		}
		if ( ! is_array( $value ) ) {
			return '' === $prefix ? array() : array( array( $prefix, $value ) );
		}
		if ( $depth > self::MAX_DEPTH ) {
			return array();
		}

		$leaves = array();
		foreach ( $value as $key => $child ) {
			$path = is_int( $key ) ? $prefix : ( '' === $prefix ? (string) $key : $prefix . '.' . $key );
			foreach ( self::flatten( $child, $path, $depth + 1 ) as $leaf ) {
				$leaves[] = $leaf;
				if ( count( $leaves ) >= self::MAX_LEAVES ) {
					return $leaves;
				}
			}
		}
		return $leaves;
	}

	/**
	 * Rutas de varios JSON de muestra con sus valores de muestra (para clasificarlas).
	 *
	 * @param array $decoded_samples Lista de JSON decodificados.
	 * @return array<string,string[]> ruta => muestras en texto.
	 */
	public static function describe( array $decoded_samples ) {
		$paths = array();
		foreach ( $decoded_samples as $decoded ) {
			foreach ( self::flatten( $decoded ) as $leaf ) {
				list( $path, $value ) = $leaf;
				if ( '' === $path || ( isset( $paths[ $path ] ) && count( $paths[ $path ] ) >= 8 ) ) {
					continue;
				}
				$paths[ $path ][] = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : ( null === $value ? '' : (string) $value );
			}
		}
		return $paths;
	}

	/**
	 * Etiqueta legible de una ruta: "detalle.recienteIngreso" → "Reciente ingreso".
	 *
	 * @param string $path Ruta.
	 * @return string
	 */
	public static function label( $path ) {
		$parts = explode( '.', $path );
		$last  = end( $parts );
		$text  = strtolower( preg_replace( '/([a-z])([A-Z])/', '$1 $2', str_replace( array( '_', '-' ), ' ', $last ) ) );
		return ucfirst( trim( $text ) );
	}

	/**
	 * Decodifica un valor meta que parece JSON (o serializado). null si no lo es.
	 *
	 * @param string $raw Valor.
	 * @return array|null
	 */
	public static function decode( $raw ) {
		if ( ! is_string( $raw ) ) {
			return is_array( $raw ) ? $raw : null;
		}
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return null;
		}
		if ( '[' === $raw[0] || '{' === $raw[0] ) {
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $decoded : null;
		}
		if ( preg_match( '/^a:\d+:\{/', $raw ) ) {
			$decoded = @unserialize( $raw, array( 'allowed_classes' => false ) ); // phpcs:ignore
			return is_array( $decoded ) ? $decoded : null;
		}
		return null;
	}
}
