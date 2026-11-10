<?php
/**
 * Registro de packs (conocimiento por giro de negocio) y conectores (integraciones por sitio).
 *
 * - Packs: carpetas en packs/<id>/pack.php que devuelven un arreglo de configuración. Un pack se
 *   activa si su 'detect' encuentra el tipo de contenido que describe.
 * - Conectores: carpetas en conectores/<id>/connector.php que devuelven una instancia de
 *   ASI_Connector.
 *
 * Otros plugins pueden sumar los suyos con los filtros 'asi_packs' y 'asi_connectors'.
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || defined( 'ASI_CLI_TESTS' ) || exit;

final class ASI_Extensions {

	/** @var array<string,ASI_Pack>|null */
	private static $packs = null;

	/** @var array<string,ASI_Connector>|null */
	private static $connectors = null;

	/**
	 * Todos los packs instalados.
	 *
	 * @return array<string,ASI_Pack>
	 */
	public static function packs() {
		if ( null === self::$packs ) {
			self::$packs = array();
			foreach ( self::load_dir( 'packs', 'pack.php' ) as $pack ) {
				if ( $pack instanceof ASI_Pack ) {
					self::$packs[ $pack->id() ] = $pack;
				}
			}
			if ( function_exists( 'apply_filters' ) ) {
				self::$packs = (array) apply_filters( 'asi_packs', self::$packs );
			}
		}
		return self::$packs;
	}

	/**
	 * Packs que aplican a este sitio.
	 *
	 * @return array<string,ASI_Pack>
	 */
	public static function active_packs() {
		return array_filter(
			self::packs(),
			static function ( ASI_Pack $pack ) {
				return $pack->detect();
			}
		);
	}

	/**
	 * Rol de un campo después de que cada pack activo opine (al descubrir el sitio).
	 */
	public static function field_role( $name, $label, array $samples, $role ) {
		foreach ( self::active_packs() as $pack ) {
			$role = $pack->field_role( $name, $label, $samples, $role );
		}
		return $role;
	}

	/**
	 * Todos los conectores instalados.
	 *
	 * @return array<string,ASI_Connector>
	 */
	public static function connectors() {
		if ( null === self::$connectors ) {
			self::$connectors = array();
			foreach ( self::load_dir( 'conectores', 'connector.php' ) as $connector ) {
				if ( $connector instanceof ASI_Connector ) {
					self::$connectors[ $connector->id() ] = $connector;
				}
			}
			if ( function_exists( 'apply_filters' ) ) {
				self::$connectors = (array) apply_filters( 'asi_connectors', self::$connectors );
			}
		}
		return self::$connectors;
	}

	/**
	 * Conectores que aplican a este sitio.
	 *
	 * @return array<string,ASI_Connector>
	 */
	public static function active_connectors() {
		return array_filter(
			self::connectors(),
			static function ( ASI_Connector $connector ) {
				return $connector->detect();
			}
		);
	}

	/**
	 * Primer conector activo que ofrece una acción.
	 *
	 * @param string $action Acción (quote, compare…).
	 * @return ASI_Connector|null
	 */
	public static function connector_for( $action ) {
		foreach ( self::active_connectors() as $connector ) {
			if ( isset( $connector->actions()[ $action ] ) ) {
				return $connector;
			}
		}
		return null;
	}

	/** Solo para pruebas. */
	public static function reset() {
		self::$packs      = null;
		self::$connectors = null;
	}

	/**
	 * Carga <raíz>/<dir>/<id>/<file> y devuelve lo que retorna cada archivo, por id.
	 */
	private static function load_dir( $dir, $file ) {
		$out  = array();
		$base = dirname( __DIR__, 2 ) . '/' . $dir . '/';
		foreach ( (array) glob( $base . '*/' . $file ) as $path ) {
			$out[ basename( dirname( $path ) ) ] = include $path;
		}
		return $out;
	}
}
