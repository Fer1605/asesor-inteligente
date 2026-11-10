<?php
/**
 * Contrato opcional de un pack que sabe decir qué se parece a qué ("algo parecido al Frontier").
 *
 * El core encuentra la referencia (en el catálogo o, si no está, en la tabla del pack), recorre los
 * candidatos y arma la respuesta. El pack pone el criterio: cuánto se parecen dos productos, cómo
 * se describe uno y por qué se parecen.
 *
 * Los atributos que recibe son los del pack más 'price_base' (precio en la moneda base, si hay).
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || defined( 'ASI_CLI_TESTS' ) || exit;

interface ASI_Similarity {

	/**
	 * Modelo conocido del mercado aunque no esté en el catálogo.
	 *
	 * @param string $text     Lo que nombró el cliente ("frontier", "la rav4").
	 * @param string $language Idioma.
	 * @return array|null { name, brand, attrs, traits }
	 */
	public function reference_model( $text, $language );

	/**
	 * Parecido entre dos productos, de 0 (nada) a 1 (iguales).
	 */
	public function similarity( array $a, array $b );

	/**
	 * Cómo es un producto, en una frase corta ("una pickup doble cabina, 4x4 y diésel").
	 */
	public function describe( array $attrs, $language );

	/**
	 * Por qué se parecen (lo que comparten), en una frase corta.
	 */
	public function why_similar( array $reference, array $other, $language );
}
