<?php
/**
 * Atributos con nombre común (los arma un pack) y las restricciones que pidió el cliente.
 *
 * Clase pura. Una restricción por atributo: min / max (números) o in (uno de varios valores).
 *
 * @package AsesorInteligente
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'ASI_CLI_TESTS' ) ) {
	exit;
}

final class ASI_Attributes {

	/**
	 * ¿Cumple? true = cumple todo; false = incumple algo; null = no se sabe (le falta algún dato o
	 * no es un producto de ese giro).
	 *
	 * @param array $attrs       Atributos del producto.
	 * @param array $constraints Restricciones.
	 * @return bool|null
	 */
	public static function accepts( array $attrs, array $constraints ) {
		if ( ! $constraints ) {
			return true;
		}
		if ( ! $attrs ) {
			return null;
		}
		$unknown = false;
		foreach ( $constraints as $key => $rule ) {
			if ( ! isset( $attrs[ $key ] ) || '' === $attrs[ $key ] || null === $attrs[ $key ] ) {
				$unknown = true;
				continue;
			}
			$value = $attrs[ $key ];
			if ( isset( $rule['min'] ) && (float) $value < (float) $rule['min'] ) {
				return false;
			}
			if ( isset( $rule['max'] ) && (float) $value > (float) $rule['max'] ) {
				return false;
			}
			if ( isset( $rule['in'] ) && ! in_array( (string) $value, array_map( 'strval', (array) $rule['in'] ), true ) ) {
				return false;
			}
			if ( isset( $rule['not_in'] ) && in_array( (string) $value, array_map( 'strval', (array) $rule['not_in'] ), true ) ) {
				return false;
			}
		}
		return $unknown ? null : true;
	}

	/**
	 * Une restricciones: la nueva manda sobre la anterior en el mismo atributo.
	 */
	public static function merge( array $old, array $new ) {
		foreach ( $new as $key => $rule ) {
			$old[ $key ] = $rule;
		}
		return $old;
	}

	/**
	 * Restricciones de varios packs para una frase.
	 *
	 * @param ASI_Pack[] $packs    Packs activos.
	 * @param string     $message  Frase.
	 * @param string     $language Idioma.
	 * @return array{constraints:array,spans:string[],labels:string[]}
	 */
	public static function parse( array $packs, $message, $language ) {
		$out = array( 'constraints' => array(), 'spans' => array(), 'labels' => array(), 'soft' => array() );
		foreach ( $packs as $pack ) {
			if ( ! $pack instanceof ASI_Pack ) {
				continue;
			}
			$part                 = $pack->parse_query( $message, $language );
			$out['constraints']   = self::merge( $out['constraints'], (array) $part['constraints'] );
			$out['spans']         = array_merge( $out['spans'], (array) $part['spans'] );
			$out['labels']        = array_merge( $out['labels'], (array) $part['labels'] );
			$out['soft']          = array_merge( $out['soft'], isset( $part['soft'] ) ? (array) $part['soft'] : array() );
		}
		return $out;
	}
}
