<?php
/**
 * Contexto de una conversación: idioma + país.
 *
 * Es el primer filtro de todo el motor: nada se busca ni se muestra fuera de él.
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || exit;

class ASI_Context {

	/** @var string Slug del idioma en el plugin multilingüe ("es-sv"). */
	public $lang = '';

	/** @var string Código de país en minúsculas ('' = sin filtro de país). */
	public $country = '';

	/** @var string Locale ("es_SV"). */
	public $locale = '';

	/** @var string Idioma de conversación de 2 letras ("es", "en"): elige textos, sinónimos y stopwords. */
	public $language = 'es';

	/** @var string Nombre del país en el idioma de la conversación ("El Salvador"). */
	public $country_name = '';

	/** @var string Portada de ese idioma. */
	public $home_url = '';

	/**
	 * Construye el contexto a partir del slug del idioma, validándolo contra el mapa descubierto.
	 * Si el slug no existe, usa el idioma actual del plugin multilingüe y, si tampoco, el idioma por defecto.
	 *
	 * @param string              $requested_lang Slug pedido (desde la página del chat).
	 * @param ASI_Language_Registry $registry      Registro de idiomas.
	 * @return ASI_Context
	 */
	public static function resolve( $requested_lang, ASI_Language_Registry $registry ) {
		$languages = $registry->all();
		$slug      = sanitize_key( (string) $requested_lang );

		if ( '' === $slug || ! isset( $languages[ $slug ] ) ) {
			$slug = $registry->adapter()->get_current_language();
		}
		if ( '' === $slug || ! isset( $languages[ $slug ] ) ) {
			$slug = $registry->default_slug();
		}

		$context = new self();
		if ( ! isset( $languages[ $slug ] ) ) {
			return $context;
		}

		$record                = $languages[ $slug ];
		$context->lang         = $slug;
		$context->country      = $record['country'];
		$context->locale       = $record['locale'];
		$context->language     = $record['language'];
		$context->country_name = $record['country_name'];
		$context->home_url     = $record['home_url'];

		return $context;
	}

	/**
	 * Clave corta para cachés e historial ("es-sv").
	 *
	 * @return string
	 */
	public function key() {
		return $this->lang ? $this->lang : 'default';
	}

	/**
	 * Datos que se pasan al JS de la página.
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'lang'        => $this->lang,
			'country'     => $this->country,
			'language'    => $this->language,
			'countryName' => $this->country_name,
			'homeUrl'     => $this->home_url,
		);
	}
}

/**
 * Registro de idiomas del sitio con su país deducido.
 *
 * Se calcula una vez (autodescubrimiento) y se guarda en una opción; se recalcula
 * cuando cambian los idiomas del plugin multilingüe.
 */
class ASI_Language_Registry {

	const OPTION = 'asi_languages';

	/** @var ASI_Language_Adapter */
	private $adapter;

	/** @var array|null */
	private $cache = null;

	public function __construct( ASI_Language_Adapter $adapter ) {
		$this->adapter = $adapter;

		// Polylang guarda los idiomas como términos de la taxonomía "language".
		add_action( 'created_language', array( $this, 'flush' ) );
		add_action( 'edited_language', array( $this, 'flush' ) );
		add_action( 'delete_language', array( $this, 'flush' ) );
		// WPML.
		add_action( 'icl_update_active_languages', array( $this, 'flush' ) );
	}

	/**
	 * @return ASI_Language_Adapter
	 */
	public function adapter() {
		return $this->adapter;
	}

	/**
	 * Idiomas indexados por slug: slug, locale, name, home_url, is_default, country, language, country_name.
	 *
	 * @return array<string,array>
	 */
	public function all() {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$stored = get_option( self::OPTION );
		if ( is_array( $stored ) && isset( $stored['adapter'], $stored['languages'] ) && $stored['adapter'] === $this->adapter->get_id() ) {
			$this->cache = $stored['languages'];
			return $this->cache;
		}

		$this->cache = $this->build();
		update_option(
			self::OPTION,
			array(
				'adapter'   => $this->adapter->get_id(),
				'languages' => $this->cache,
				'built_at'  => time(),
			),
			false
		);
		return $this->cache;
	}

	/**
	 * Borra el registro guardado; se recalcula en la siguiente lectura.
	 */
	public function flush() {
		$this->cache = null;
		delete_option( self::OPTION );
	}

	/**
	 * Slug del idioma por defecto.
	 *
	 * @return string
	 */
	public function default_slug() {
		foreach ( $this->all() as $slug => $record ) {
			if ( ! empty( $record['is_default'] ) ) {
				return $slug;
			}
		}
		$all = $this->all();
		return $all ? (string) key( $all ) : '';
	}

	/**
	 * Países del sitio: código => lista de slugs de idioma.
	 *
	 * @return array<string,string[]>
	 */
	public function countries() {
		$countries = array();
		foreach ( $this->all() as $slug => $record ) {
			if ( '' !== $record['country'] ) {
				$countries[ $record['country'] ][] = $slug;
			}
		}
		return $countries;
	}

	/**
	 * ¿El sitio es multipaís?
	 *
	 * @return bool
	 */
	public function is_multi_country() {
		return count( $this->countries() ) > 1;
	}

	/**
	 * Calcula el registro desde el adaptador.
	 *
	 * @return array<string,array>
	 */
	private function build() {
		$languages = $this->adapter->get_languages();
		$countries = ASI_Country_Resolver::resolve( $languages );
		$overrides = (array) apply_filters( 'asi_language_country_map', array(), $languages );
		$result    = array();

		foreach ( $languages as $language ) {
			$slug     = $language['slug'];
			$country  = isset( $overrides[ $slug ] ) ? strtolower( (string) $overrides[ $slug ] ) : ( isset( $countries[ $slug ] ) ? $countries[ $slug ] : '' );
			$lang2    = ASI_Country_Resolver::language_from_locale( $language['locale'] );
			$lang2    = $lang2 ? $lang2 : substr( $slug, 0, 2 );

			$result[ $slug ] = array_merge(
				$language,
				array(
					'country'      => $country,
					'language'     => $lang2,
					'country_name' => $country ? self::country_name( $country, $lang2 ) : '',
				)
			);
		}
		return $result;
	}

	/**
	 * Nombre del país en un idioma. Usa la extensión intl si existe; si no, una tabla corta.
	 *
	 * @param string $country  Código ISO en minúsculas.
	 * @param string $language Idioma de 2 letras.
	 * @return string
	 */
	public static function country_name( $country, $language ) {
		if ( class_exists( 'Locale' ) ) {
			$name = Locale::getDisplayRegion( 'und_' . strtoupper( $country ), $language );
			if ( $name && strtoupper( $country ) !== $name ) {
				return $name;
			}
		}

		$names = array(
			'es' => array( 'gt' => 'Guatemala', 'sv' => 'El Salvador', 'hn' => 'Honduras', 'ni' => 'Nicaragua', 'cr' => 'Costa Rica', 'pa' => 'Panamá', 'mx' => 'México', 'bz' => 'Belice', 'co' => 'Colombia', 'pe' => 'Perú', 'cl' => 'Chile', 'ar' => 'Argentina', 'ec' => 'Ecuador', 'do' => 'República Dominicana', 'us' => 'Estados Unidos', 'es' => 'España' ),
			'en' => array( 'gt' => 'Guatemala', 'sv' => 'El Salvador', 'hn' => 'Honduras', 'ni' => 'Nicaragua', 'cr' => 'Costa Rica', 'pa' => 'Panama', 'mx' => 'Mexico', 'bz' => 'Belize', 'co' => 'Colombia', 'pe' => 'Peru', 'cl' => 'Chile', 'ar' => 'Argentina', 'ec' => 'Ecuador', 'do' => 'Dominican Republic', 'us' => 'United States', 'es' => 'Spain' ),
		);
		$table = isset( $names[ $language ] ) ? $names[ $language ] : $names['en'];
		return isset( $table[ $country ] ) ? $table[ $country ] : strtoupper( $country );
	}
}
