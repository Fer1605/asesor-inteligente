<?php
/**
 * Quién puede entrar al panel del Asesor.
 *
 * El panel pide su propio permiso (asi_manage) en lugar de "ser administrador". Los
 * administradores lo tienen siempre. Además hay un rol, "Asesor inteligente", para quien solo
 * debe ver este panel (por ejemplo, alguien del cliente que revisa reportes y ajustes): al
 * iniciar sesión entra directo al panel, en el menú solo ve el Asesor y su perfil, y no toca
 * nada más del sitio.
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || exit;

class ASI_Access {

	/** Permiso del panel del Asesor. */
	const CAP = 'asi_manage';

	/** Rol que solo ve el panel. */
	const ROLE = 'asi_asesor';

	public static function init() {
		add_filter( 'user_has_cap', array( __CLASS__, 'grant_admins' ), 10, 4 );
		add_action( 'init', array( __CLASS__, 'ensure_role' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );
		add_action( 'admin_menu', array( __CLASS__, 'trim_menu' ), 999 );
		add_action( 'admin_init', array( __CLASS__, 'keep_in_panel' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'trim_bar' ), 999 );
	}

	/** ¿El usuario actual puede usar el panel? */
	public static function can() {
		return function_exists( 'current_user_can' ) && current_user_can( self::CAP );
	}

	/** Los administradores siempre tienen el permiso del Asesor (sin guardar nada en la base). */
	public static function grant_admins( $allcaps, $caps, $args, $user ) {
		if ( ! empty( $allcaps['manage_options'] ) ) {
			$allcaps[ self::CAP ] = true;
		}
		return $allcaps;
	}

	/** Crea el rol la primera vez (y lo repone si alguien lo borró). */
	public static function ensure_role() {
		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, 'Asesor inteligente', array( 'read' => true, self::CAP => true ) );
		}
	}

	/** ¿Es alguien que solo debe ver el panel? (el rol, sin ser administrador). */
	public static function panel_only( $user = null ) {
		$user = $user instanceof WP_User ? $user : wp_get_current_user();
		return $user instanceof WP_User && $user->exists() && in_array( self::ROLE, (array) $user->roles, true ) && ! user_can( $user, 'manage_options' );
	}

	public static function panel_url() {
		return admin_url( 'admin.php?page=asesor-inteligente' ); // ASI_Admin::PAGE (aquí ASI_Admin no está cargado en el login).
	}

	/** Al iniciar sesión entra directo al panel. */
	public static function login_redirect( $redirect, $requested, $user ) {
		return self::panel_only( $user ) ? self::panel_url() : $redirect;
	}

	/** En el menú solo queda el Asesor (y el perfil, para cambiar su contraseña). */
	public static function trim_menu() {
		if ( ! self::panel_only() ) {
			return;
		}
		remove_menu_page( 'index.php' );
	}

	/** El escritorio de WordPress lo manda al panel. */
	public static function keep_in_panel() {
		global $pagenow;
		if ( self::panel_only() && 'index.php' === $pagenow && ! wp_doing_ajax() ) {
			wp_safe_redirect( self::panel_url() );
			exit;
		}
	}

	/** La barra de arriba sin accesos a otras partes del sitio. */
	public static function trim_bar( $bar ) {
		if ( ! self::panel_only() ) {
			return;
		}
		foreach ( array( 'wp-logo', 'comments', 'new-content', 'updates', 'dashboard', 'appearance', 'customize', 'edit', 'elementor_edit_page' ) as $node ) {
			$bar->remove_node( $node );
		}
	}
}
