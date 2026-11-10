<?php
/**
 * Contrato de un pack: el conocimiento de un giro de negocio (autos, maquinaria, inmobiliaria…).
 *
 * Un pack nunca conoce un sitio concreto. Traduce la ficha de un producto a atributos con nombre
 * común (plazas, tracción, año…) y entiende esos mismos atributos en la frase del cliente. El core
 * aplica las restricciones de forma genérica con ASI_Attributes.
 *
 * Restricciones: atributo => array( 'min' => n, 'max' => n, 'in' => array( valores ) ).
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || defined( 'ASI_CLI_TESTS' ) || exit;

interface ASI_Pack {

	/** Identificador corto y estable. */
	public function id();

	/** Nombre para el panel. */
	public function label();

	/** ¿Aplica a este sitio? Barato: se llama en cada carga. */
	public function detect();

	/**
	 * Corrige el rol de un campo al descubrir el sitio (p. ej. "modelo" con años es el año, no un código).
	 *
	 * @param string $name    Nombre del campo.
	 * @param string $label   Etiqueta.
	 * @param array  $samples Valores de muestra.
	 * @param string $role    Rol que decidió el core.
	 * @return string
	 */
	public function field_role( $name, $label, array $samples, $role );

	/**
	 * Atributos de un producto a partir de su documento del índice (facts, facets, card, terms).
	 *
	 * @param array  $doc       Documento.
	 * @param string $post_type Tipo de contenido.
	 * @return array Atributos; vacío si el producto no es de este giro.
	 */
	public function attributes( array $doc, $post_type );

	/**
	 * Ajusta la tarjeta con los atributos (categoría legible, especificaciones clave).
	 *
	 * @param array $card  Tarjeta.
	 * @param array $attrs Atributos.
	 * @return array
	 */
	public function card( array $card, array $attrs );

	/**
	 * Restricciones que el cliente pidió en su frase.
	 *
	 * @param string $message  Frase original.
	 * @param string $language Idioma de la conversación (es, en).
	 * @return array{constraints:array,spans:string[],labels:string[],soft?:string[]} spans: texto a
	 *         quitar de la búsqueda por palabras; labels: cómo nombrar lo pedido ("de 7 pasajeros",
	 *         "4x4"); soft: palabras que se quedan en la búsqueda pero dejan de ser obligatorias
	 *         porque ya las controla un atributo ("camioneta").
	 */
	public function parse_query( $message, $language );
}
