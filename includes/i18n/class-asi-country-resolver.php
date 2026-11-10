<?php
/**
 * Deduce el país de cada idioma configurado en el plugin multilingüe.
 *
 * Clase pura (sin WordPress) para poder probarla por CLI.
 *
 * Reglas, en orden:
 * 1. Códigos de país dentro del slug del idioma: "es-sv", "cr-es", "en_gt", "es-sv-2".
 *    La parte que coincide con el idioma del locale se descarta ("es" en "es-sv" es idioma, no España).
 * 2. Si el slug no trae país, la región del locale ("es_CR" → CR), pero solo cuando el sitio
 *    es multipaís (algún otro idioma sí trae país en el slug) o cuando todos los locales
 *    comparten la misma región.
 * 3. Si ningún idioma permite deducir país → sitio de un solo país: country = '' (sin filtro de país).
 *
 * El slug manda sobre el locale: un idioma "en-gt" puede tener locale "en_US".
 *
 * @package AsesorInteligente
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'ASI_CLI_TESTS' ) ) {
	exit;
}

class ASI_Country_Resolver {

	/**
	 * Códigos ISO 3166-1 alfa-2.
	 *
	 * @var string[]
	 */
	const ISO_COUNTRIES = array(
		'ad','ae','af','ag','ai','al','am','ao','aq','ar','as','at','au','aw','ax','az','ba','bb','bd','be','bf','bg','bh','bi','bj','bl','bm','bn','bo','bq','br','bs','bt','bv','bw','by','bz',
		'ca','cc','cd','cf','cg','ch','ci','ck','cl','cm','cn','co','cr','cu','cv','cw','cx','cy','cz','de','dj','dk','dm','do','dz','ec','ee','eg','eh','er','es','et','fi','fj','fk','fm','fo','fr',
		'ga','gb','gd','ge','gf','gg','gh','gi','gl','gm','gn','gp','gq','gr','gs','gt','gu','gw','gy','hk','hm','hn','hr','ht','hu','id','ie','il','im','in','io','iq','ir','is','it','je','jm','jo','jp',
		'ke','kg','kh','ki','km','kn','kp','kr','kw','ky','kz','la','lb','lc','li','lk','lr','ls','lt','lu','lv','ly','ma','mc','md','me','mf','mg','mh','mk','ml','mm','mn','mo','mp','mq','mr','ms','mt','mu','mv','mw','mx','my','mz',
		'na','nc','ne','nf','ng','ni','nl','no','np','nr','nu','nz','om','pa','pe','pf','pg','ph','pk','pl','pm','pn','pr','ps','pt','pw','py','qa','re','ro','rs','ru','rw','sa','sb','sc','sd','se','sg','sh','si','sj','sk','sl','sm','sn','so','sr','ss','st','sv','sx','sy','sz',
		'tc','td','tf','tg','th','tj','tk','tl','tm','tn','to','tr','tt','tv','tw','tz','ua','ug','um','us','uy','uz','va','vc','ve','vg','vi','vn','vu','wf','ws','ye','yt','za','zm','zw',
	);

	/**
	 * Alias usados a veces en slugs y que no son ISO.
	 *
	 * @var array<string,string>
	 */
	const COUNTRY_ALIASES = array(
		'uk' => 'gb',
	);

	/**
	 * Resuelve el país de cada idioma.
	 *
	 * @param array $languages Lista de array( 'slug' => 'es-sv', 'locale' => 'es_SV' ).
	 * @return array<string,string> slug de idioma => código de país en minúsculas ('' si no aplica).
	 */
	public static function resolve( array $languages ) {
		$from_slug   = array();
		$from_locale = array();

		foreach ( $languages as $language ) {
			$slug   = isset( $language['slug'] ) ? (string) $language['slug'] : '';
			$locale = isset( $language['locale'] ) ? (string) $language['locale'] : '';
			if ( '' === $slug ) {
				continue;
			}
			$from_slug[ $slug ]   = self::country_from_slug( $slug, $locale );
			$from_locale[ $slug ] = self::region_from_locale( $locale );
		}

		$slug_countries = array_filter( $from_slug );
		$result         = array();

		if ( ! empty( $slug_countries ) ) {
			// Sitio multipaís declarado en los slugs.
			foreach ( $from_slug as $slug => $country ) {
				if ( '' === $country && '' !== $from_locale[ $slug ] && in_array( $from_locale[ $slug ], $slug_countries, true ) ) {
					// La región del locale solo cuenta si coincide con un país que el sitio ya usa.
					$country = $from_locale[ $slug ];
				}
				$result[ $slug ] = $country;
			}
			// Un idioma sin país deducible queda '' = todo el sitio en ese idioma.
			return $result;
		}

		// Ningún slug trae país: se usa el locale solo si todos comparten la misma región.
		$regions = array_unique( array_filter( $from_locale ) );
		$shared  = ( 1 === count( $regions ) && count( array_filter( $from_locale ) ) === count( $from_locale ) ) ? reset( $regions ) : '';

		foreach ( $from_locale as $slug => $unused ) {
			$result[ $slug ] = $shared ? $shared : '';
		}
		return $result;
	}

	/**
	 * Extrae el código de país de un slug de idioma.
	 *
	 * @param string $slug   Slug del idioma.
	 * @param string $locale Locale (es_SV, en_US…).
	 * @return string
	 */
	public static function country_from_slug( $slug, $locale = '' ) {
		$language = self::language_from_locale( $locale );
		$tokens   = preg_split( '/[^a-z]+/', strtolower( $slug ), -1, PREG_SPLIT_NO_EMPTY );
		if ( empty( $tokens ) ) {
			return '';
		}

		$language_dropped = false;
		$candidates       = array();

		foreach ( $tokens as $token ) {
			if ( ! $language_dropped && $token === $language ) {
				$language_dropped = true;
				continue;
			}
			$candidates[] = $token;
		}

		// Sin locale útil: el primer token de 2 letras se asume idioma ("es-sv" → idioma es).
		if ( ! $language_dropped && '' === $language && count( $candidates ) > 1 ) {
			array_shift( $candidates );
		}

		foreach ( $candidates as $token ) {
			if ( isset( self::COUNTRY_ALIASES[ $token ] ) ) {
				$token = self::COUNTRY_ALIASES[ $token ];
			}
			if ( 2 === strlen( $token ) && in_array( $token, self::ISO_COUNTRIES, true ) ) {
				// Un slug de un solo token ("es", "en", "fr") es solo idioma.
				if ( 1 === count( $tokens ) ) {
					return '';
				}
				return $token;
			}
		}

		return '';
	}

	/**
	 * Idioma de un locale ("es_SV" → "es").
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	public static function language_from_locale( $locale ) {
		$parts = preg_split( '/[_-]/', strtolower( (string) $locale ) );
		return ( isset( $parts[0] ) && preg_match( '/^[a-z]{2,3}$/', $parts[0] ) ) ? $parts[0] : '';
	}

	/**
	 * Región de un locale ("es_SV" → "sv"; "en_US" → "us"; "de_DE_formal" → "de").
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	public static function region_from_locale( $locale ) {
		$parts = preg_split( '/[_-]/', strtolower( (string) $locale ) );
		if ( isset( $parts[1] ) && 2 === strlen( $parts[1] ) && in_array( $parts[1], self::ISO_COUNTRIES, true ) ) {
			return $parts[1];
		}
		return '';
	}
}
