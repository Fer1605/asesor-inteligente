<?php
/**
 * Adaptadores de plugins multilingües.
 *
 * Cada adaptador sabe leer, del plugin que corresponda, la lista de idiomas,
 * el idioma actual y el idioma de un post. El resto del plugin solo habla con
 * esta interfaz, así el mismo código funciona con Polylang, WPML o sin plugin.
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || exit;

abstract class ASI_Language_Adapter {

	/**
	 * Detecta el plugin multilingüe activo y devuelve su adaptador.
	 *
	 * @return ASI_Language_Adapter
	 */
	public static function detect() {
		if ( function_exists( 'pll_languages_list' ) && function_exists( 'pll_get_post_language' ) ) {
			return new ASI_Language_Adapter_Polylang();
		}
		if ( defined( 'ICL_SITEPRESS_VERSION' ) && has_filter( 'wpml_active_languages' ) ) {
			return new ASI_Language_Adapter_WPML();
		}
		return new ASI_Language_Adapter_None();
	}

	/**
	 * Identificador del adaptador: polylang | wpml | none.
	 *
	 * @return string
	 */
	abstract public function get_id();

	/**
	 * Idiomas configurados.
	 *
	 * @return array[] Cada uno: slug, locale, name, home_url, is_default.
	 */
	abstract public function get_languages();

	/**
	 * Slug del idioma de la petición actual ('' si no se puede saber, p. ej. en REST).
	 *
	 * @return string
	 */
	abstract public function get_current_language();

	/**
	 * Slug del idioma por defecto.
	 *
	 * @return string
	 */
	abstract public function get_default_language();

	/**
	 * Slug del idioma de un post ('' si no tiene).
	 *
	 * @param int $post_id ID del post.
	 * @return string
	 */
	abstract public function get_post_language( $post_id );

	/**
	 * ¿El plugin multilingüe gestiona este tipo de contenido?
	 *
	 * @param string $post_type Tipo de contenido.
	 * @return bool
	 */
	abstract public function is_translated_post_type( $post_type );
}

/**
 * Polylang (gratis y Pro).
 *
 * Polylang guarda los idiomas como términos de la taxonomía "language" y el idioma
 * de cada post como relación con ese término. Las traducciones son posts distintos,
 * unidos por la taxonomía "post_translations".
 */
class ASI_Language_Adapter_Polylang extends ASI_Language_Adapter {

	public function get_id() {
		return 'polylang';
	}

	public function get_languages() {
		$languages = array();
		$default   = $this->get_default_language();
		// API pública: con fields vacío devuelve los objetos PLL_Language completos.
		$objects   = pll_languages_list( array( 'fields' => '' ) );

		foreach ( (array) $objects as $language ) {
			$slug = isset( $language->slug ) ? (string) $language->slug : '';
			if ( '' === $slug || ( isset( $language->active ) && false === $language->active ) ) {
				continue;
			}
			$languages[] = array(
				'slug'       => $slug,
				'locale'     => isset( $language->locale ) ? (string) $language->locale : '',
				'name'       => isset( $language->name ) ? (string) $language->name : $slug,
				'home_url'   => function_exists( 'pll_home_url' ) ? (string) pll_home_url( $slug ) : home_url( '/' ),
				'is_default' => $slug === $default,
			);
		}
		return $languages;
	}

	public function get_current_language() {
		$slug = pll_current_language( 'slug' );
		return $slug ? (string) $slug : '';
	}

	public function get_default_language() {
		$slug = pll_default_language( 'slug' );
		return $slug ? (string) $slug : '';
	}

	public function get_post_language( $post_id ) {
		$slug = pll_get_post_language( (int) $post_id, 'slug' );
		return $slug ? (string) $slug : '';
	}

	public function is_translated_post_type( $post_type ) {
		return function_exists( 'pll_is_translated_post_type' ) ? (bool) pll_is_translated_post_type( $post_type ) : true;
	}
}

/**
 * WPML, a través de su API pública de filtros.
 */
class ASI_Language_Adapter_WPML extends ASI_Language_Adapter {

	public function get_id() {
		return 'wpml';
	}

	public function get_languages() {
		$languages = array();
		$default   = $this->get_default_language();
		$active    = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );

		foreach ( (array) $active as $code => $language ) {
			$slug        = isset( $language['code'] ) ? (string) $language['code'] : (string) $code;
			$languages[] = array(
				'slug'       => $slug,
				'locale'     => isset( $language['default_locale'] ) ? (string) $language['default_locale'] : '',
				'name'       => isset( $language['native_name'] ) ? (string) $language['native_name'] : $slug,
				'home_url'   => (string) apply_filters( 'wpml_permalink', home_url( '/' ), $slug ),
				'is_default' => $slug === $default,
			);
		}
		return $languages;
	}

	public function get_current_language() {
		return (string) apply_filters( 'wpml_current_language', null );
	}

	public function get_default_language() {
		return (string) apply_filters( 'wpml_default_language', null );
	}

	public function get_post_language( $post_id ) {
		$post_type = get_post_type( $post_id );
		if ( ! $post_type ) {
			return '';
		}
		return (string) apply_filters(
			'wpml_element_language_code',
			null,
			array(
				'element_id'   => (int) $post_id,
				'element_type' => 'post_' . $post_type,
			)
		);
	}

	public function is_translated_post_type( $post_type ) {
		return (bool) apply_filters( 'wpml_is_translated_post_type', false, $post_type );
	}
}

/**
 * Sitio sin plugin multilingüe: un único idioma, tomado del locale de WordPress.
 */
class ASI_Language_Adapter_None extends ASI_Language_Adapter {

	public function get_id() {
		return 'none';
	}

	public function get_languages() {
		$locale = get_locale();
		$slug   = ASI_Country_Resolver::language_from_locale( $locale );
		$slug   = $slug ? $slug : 'es';

		return array(
			array(
				'slug'       => $slug,
				'locale'     => $locale,
				'name'       => $slug,
				'home_url'   => home_url( '/' ),
				'is_default' => true,
			),
		);
	}

	public function get_current_language() {
		return $this->get_default_language();
	}

	public function get_default_language() {
		$languages = $this->get_languages();
		return $languages[0]['slug'];
	}

	public function get_post_language( $post_id ) {
		return $this->get_default_language();
	}

	public function is_translated_post_type( $post_type ) {
		return true;
	}
}
