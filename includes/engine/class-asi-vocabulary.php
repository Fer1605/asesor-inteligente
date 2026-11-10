<?php
/**
 * Vocabulario de un contexto (idioma + país), construido desde las filas del índice.
 *
 * Clase pura: la usan el repositorio de WordPress y las pruebas.
 *   tokens  => [forma_normal => en cuántos documentos aparece] (títulos, términos, códigos)
 *   phrases => [forma_normal => [label, kind, df]] (términos del sitio: categorías, marcas, distintivos…)
 *   facts   => [forma_normal => etiqueta] nombres de los datos de las fichas ("Potencia", "Peso")
 *   docs, catalog => documentos del contexto y cuántos son de catálogo
 *
 * @package AsesorInteligente
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'ASI_CLI_TESTS' ) ) {
	exit;
}

class ASI_Vocabulary {

	/**
	 * @param array[] $rows Filas con title_norm, terms_norm, codes y facets ("tipo:Nombre" por línea).
	 * @return array
	 */
	public static function build( array $rows ) {
		$tokens  = array();
		$phrases = array();
		$labels  = array();
		$catalog = 0;

		foreach ( $rows as $row ) {
			if ( isset( $row['role'] ) && 'catalog' === $row['role'] ) {
				$catalog++;
			}
			$seen = array();
			$text = $row['title_norm'] . ' ' . str_replace( '|', ' ', $row['terms_norm'] ) . ' ' . $row['codes'];
			foreach ( explode( ' ', $text ) as $token ) {
				if ( '' !== $token && ! isset( $seen[ $token ] ) ) {
					$seen[ $token ]   = true;
					$tokens[ $token ] = isset( $tokens[ $token ] ) ? $tokens[ $token ] + 1 : 1;
				}
			}

			$seen_phrases = array();
			foreach ( explode( "\n", (string) $row['facets'] ) as $line ) {
				$separator = strpos( $line, ':' );
				if ( false === $separator ) {
					continue;
				}
				$kind   = substr( $line, 0, $separator );
				$label  = trim( substr( $line, $separator + 1 ) );
				$normal = ASI_Lexicon::normal_form( $label );
				// Etiquetas de datos de la ficha ("Potencia", "Peso"): no son términos que se busquen.
				if ( 'fact' === $kind ) {
					if ( '' !== $normal ) {
						$labels[ $normal ] = $label;
					}
					continue;
				}
				if ( '' === $normal || isset( $seen_phrases[ $normal ] ) ) {
					continue;
				}
				$seen_phrases[ $normal ] = true;
				if ( ! isset( $phrases[ $normal ] ) ) {
					$phrases[ $normal ] = array( 'label' => $label, 'kind' => $kind, 'df' => 0 );
				}
				$phrases[ $normal ]['df']++;
			}
		}

		return array(
			'docs'    => count( $rows ),
			'catalog' => $catalog,
			'tokens'  => $tokens,
			'phrases' => $phrases,
			'facts'   => $labels,
		);
	}
}
