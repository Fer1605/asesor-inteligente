<?php
/**
 * Tono de las respuestas: tratamiento (tú o usted) y emojis.
 *
 * Clase pura. Se aplica a las plantillas antes de rellenarlas, así nunca toca nombres de productos
 * ni textos del sitio. El tratamiento de usted sale de la lista "usted" del idioma (palabra por
 * palabra, respetando mayúsculas); los idiomas sin esa lista no cambian.
 *
 * @package AsesorInteligente
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'ASI_CLI_TESTS' ) ) {
	exit;
}

class ASI_Tone {

	/** Emoji de cada tipo de respuesta y dónde va. */
	const EMOJIS = array(
		'greeting'           => array( '👋', 'end' ),
		'thanks'             => array( '😊', 'end' ),
		'goodbye'            => array( '👋', 'end' ),
		'info.location'      => array( '📍', 'start' ),
		'info.location_list' => array( '📍', 'start' ),
		'info.location_specific' => array( '📍', 'start' ),
		'info_quote.location' => array( '📍', 'start' ),
		'info.hours'         => array( '🕒', 'start' ),
		'info_quote.hours'   => array( '🕒', 'start' ),
		'info.contact'       => array( '📞', 'start' ),
		'info_quote.contact' => array( '📞', 'start' ),
		'fact_price'         => array( '💲', 'start' ),
		'cheapest'           => array( '💲', 'start' ),
		'compare'            => array( '⚖️', 'start' ),
		'none'               => array( '🤔', 'end' ),
		'similar'            => array( '✨', 'start' ),
	);

	/** @var array<string,string> */
	private $usted = array();

	/** @var bool */
	private $emojis;

	/**
	 * @param ASI_Lexicon $lexicon Idioma.
	 * @param string     $form    'tu' o 'usted'.
	 * @param bool       $emojis  Agregar emojis.
	 */
	public function __construct( ASI_Lexicon $lexicon, $form = 'tu', $emojis = false ) {
		if ( 'usted' === $form ) {
			$this->usted = (array) $lexicon->get_list( 'usted' );
			// Frases largas primero ("te refieres" antes que "te").
			uksort(
				$this->usted,
				function ( $a, $b ) {
					return strlen( $b ) - strlen( $a );
				}
			);
		}
		$this->emojis = (bool) $emojis;
	}

	/**
	 * Aplica el tratamiento a una plantilla.
	 *
	 * @param string $template Plantilla con marcadores.
	 * @return string
	 */
	public function template( $template ) {
		if ( ! $this->usted || '' === $template ) {
			return $template;
		}
		// Una sola pasada con todas las palabras: lo ya cambiado no se vuelve a cambiar.
		$pattern = array();
		foreach ( array_keys( $this->usted ) as $from ) {
			$pattern[] = preg_quote( $from, '/' );
		}
		$usted = $this->usted;
		return preg_replace_callback(
			'/(?<![\p{L}{])(' . implode( '|', $pattern ) . ')(?![\p{L}}])/u',
			function ( $match ) use ( $usted ) {
				return $usted[ $match[1] ];
			},
			$template
		);
	}

	/**
	 * Agrega el emoji del tipo de respuesta, si están activados.
	 *
	 * @param string $key  Tipo ("greeting", "info.hours"…).
	 * @param string $text Texto final.
	 * @return string
	 */
	public function decorate( $key, $text ) {
		if ( ! $this->emojis || '' === $text || ! isset( self::EMOJIS[ $key ] ) ) {
			return $text;
		}
		list( $emoji, $where ) = self::EMOJIS[ $key ];
		return 'start' === $where ? $emoji . ' ' . $text : $text . ' ' . $emoji;
	}

	/**
	 * Aplica el tratamiento a los textos de la interfaz.
	 *
	 * @param array $ui Textos.
	 * @return array
	 */
	public function ui( array $ui ) {
		foreach ( $ui as $key => $text ) {
			if ( is_string( $text ) && ! in_array( $key, array( 'page_slug', 'page_title' ), true ) ) {
				$ui[ $key ] = $this->template( $text );
			}
		}
		return $ui;
	}
}
