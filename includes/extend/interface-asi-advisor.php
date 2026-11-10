<?php
/**
 * Contrato opcional de un pack que sabe aconsejar: lee el perfil del cliente en la conversación
 * ("somos 4, con un niño de 3 años", "lo quiero para trabajar en la finca") y lo traduce a qué
 * conviene, con el porqué. El core arma la respuesta, pregunta lo que falta y elige las tarjetas.
 *
 * El perfil es un arreglo plano que el pack define (people, kids, uses…). El core solo lo guarda
 * en el estado de la conversación y le suma el presupuesto (budget_min, budget_max, budget_currency).
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || defined( 'ASI_CLI_TESTS' ) || exit;

interface ASI_Advisor {

	/**
	 * Actualiza el perfil con lo que dice el mensaje.
	 *
	 * @param string $message  Frase original.
	 * @param string $language Idioma.
	 * @param array  $profile  Perfil hasta ahora.
	 * @return array{profile:array,changed:string[]} changed: claves que este mensaje aportó.
	 */
	public function read_profile( $message, $language, array $profile );

	/**
	 * ¿El mensaje pide consejo? ("¿qué me recomiendas?", "¿qué me conviene?").
	 */
	public function asks_advice( $message, $language );

	/**
	 * ¿El perfil alcanza para recomendar?
	 */
	public function can_advise( array $profile );

	/**
	 * Qué conviene para el perfil.
	 *
	 * @param array  $profile  Perfil.
	 * @param string $language Idioma.
	 * @return array{
	 *   weights: array<string,float>,  valor del atributo de agrupación (p. ej. carrocería) => peso,
	 *   group_by: string,              atributo de agrupación ('body'),
	 *   constraints: array,            restricciones duras (ASI_Attributes),
	 *   prefer: array,                 restricciones blandas que suben en el orden,
	 *   summary: string,               cómo nombrar al cliente ("una familia de 4 con un niño"),
	 *   names: array<string,string>,   valor de agrupación => nombre ("una SUV"),
	 *   reasons: string[],             porqué, en frases
	 *   avoid: string                  qué no conviene y por qué ('' si nada)
	 * }
	 */
	public function recommend( array $profile, $language );
}
