<?php
/**
 * Enlaces del menú del sitio como fuente de respuestas.
 *
 * Los menús dicen qué ofrece el negocio aunque no tenga una página con información: "Otros negocios →
 * Carretones" lleva a otro sitio; "Accesorios" lleva a una página en construcción. El chat los usa
 * para responder "¿qué es Negocios sobre ruedas?" (enlace externo) o "¿tienen accesorios?" (todavía no).
 *
 * Solo se guardan los que el buscador normal no resuelve:
 * - enlaces a otros sitios (external),
 * - páginas del sitio sin contenido real: en construcción o con texto de relleno (status).
 *
 * Se leen los menús asignados a una ubicación del tema (los que se ven); si no hay, todos.
 * Lo leído se guarda unas horas; al reindexar o al guardar un menú/página se vuelve a leer.
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || exit;

class ASI_Site_Links {

	const TRANSIENT = 'asi_site_links';

	/**
	 * @param string $language Idioma del léxico (para reconocer páginas en construcción).
	 * @return array[] { title, url, external (bool), status: ok|construction|placeholder }
	 */
	public static function all( $language ) {
		$key    = self::TRANSIENT . '_' . sanitize_key( $language );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$links = self::read( $language );
		set_transient( $key, $links, 6 * HOUR_IN_SECONDS );
		return $links;
	}

	public static function forget() {
		foreach ( ASI_Lexicon::available() as $language ) {
			delete_transient( self::TRANSIENT . '_' . sanitize_key( $language ) );
		}
	}

	private static function read( $language ) {
		$menus = array_values( array_unique( array_filter( array_map( 'intval', (array) get_nav_menu_locations() ) ) ) );
		if ( ! $menus ) {
			$menus = wp_list_pluck( (array) wp_get_nav_menus(), 'term_id' );
		}
		$home    = wp_parse_url( home_url(), PHP_URL_HOST );
		$lexicon = ASI_Lexicon::get( $language );
		$out     = array();
		$seen    = array();
		foreach ( $menus as $menu ) {
			foreach ( (array) wp_get_nav_menu_items( $menu ) as $item ) {
				$title = trim( wp_strip_all_tags( (string) $item->title ) );
				$url   = (string) $item->url;
				if ( '' === $title || '' === $url || '#' === $url[0] ) {
					continue;
				}
				$norm = ASI_Normalizer::normalize( $title );
				if ( isset( $seen[ $norm ] ) ) {
					continue;
				}
				$host     = wp_parse_url( $url, PHP_URL_HOST );
				$external = $host && $home && strtolower( $host ) !== strtolower( $home );
				$status   = 'ok';
				if ( ! $external && 'page' === $item->object && $item->object_id ) {
					$page   = get_post( (int) $item->object_id );
					$status = $page ? self::status( self::page_text( $page ), $lexicon ) : 'ok';
				}
				if ( ! $external && 'ok' === $status ) {
					continue; // El buscador normal ya encuentra esa página.
				}
				$seen[ $norm ] = true;
				$out[]         = array(
					'title'    => $title,
					'url'      => $url,
					'external' => (bool) $external,
					'status'   => $status,
				);
			}
		}
		return $out;
	}

	/** Texto visible de una página (con Elementor si lo tiene), en forma normal. */
	private static function page_text( WP_Post $page ) {
		$text = (string) $page->post_content;
		$data = get_post_meta( $page->ID, '_elementor_data', true );
		if ( is_string( $data ) && '' !== $data ) {
			$text .= ' ' . $data;
		}
		return ASI_Normalizer::normalize( wp_strip_all_tags( $text ) );
	}

	/**
	 * ¿La página tiene contenido real? construction (en construcción), placeholder (texto de relleno) u ok.
	 */
	public static function status( $normalized_text, ASI_Lexicon $lexicon ) {
		if ( '' !== ASI_Lexicon::find_phrase( $normalized_text, (array) $lexicon->get_list( 'placeholder_markers' ) ) ) {
			return 'placeholder';
		}
		if ( '' !== ASI_Lexicon::find_phrase( $normalized_text, (array) $lexicon->get_list( 'construction_markers' ) ) ) {
			return 'construction';
		}
		return 'ok';
	}
}
