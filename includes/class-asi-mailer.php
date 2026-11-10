<?php
/**
 * Correos del asistente con la imagen del sitio: logo, colores del chat (que por defecto son los
 * del Kit de Elementor) y nombre del sitio como remitente. Sin ajustes propios: si cambian los
 * colores del chat en Apariencia, cambian también los correos.
 *
 * HTML de correo: tablas y estilos en línea (lo único que respetan Gmail y Outlook).
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || exit;

final class ASI_Mailer {

	/**
	 * Envía un correo con la plantilla.
	 *
	 * @param string|string[] $to          Destinatarios.
	 * @param string          $subject     Asunto.
	 * @param array           $content     Ver html().
	 * @param array           $headers     Encabezados extra (Reply-To…).
	 * @param string[]        $attachments Archivos.
	 * @return bool
	 */
	public static function send( $to, $subject, array $content, array $headers = array(), array $attachments = array() ) {
		$from_name = static function () {
			return self::site_name();
		};
		add_filter( 'wp_mail_from_name', $from_name, 9999 );
		$headers[] = 'Content-Type: text/html; charset=UTF-8';
		try {
			$sent = wp_mail( $to, $subject, self::html( $content ), $headers, $attachments );
		} finally {
			remove_filter( 'wp_mail_from_name', $from_name, 9999 );
		}
		return (bool) $sent;
	}

	/**
	 * HTML del correo.
	 *
	 * @param array $c {
	 *   badge:   string  franja superior (p. ej. "PRUEBA"),
	 *   title:   string,
	 *   intro:   string,
	 *   rows:    array<string,string|array{text:string,url:string}>  datos (etiqueta => valor),
	 *   items:   array[] { title, url, image, price, meta }  productos,
	 *   button:  array{label:string,url:string},
	 *   note:    string  texto chico al final,
	 * }
	 * @return string
	 */
	public static function html( array $c ) {
		$colors  = self::colors();
		$primary = $colors['primary'];
		$accent  = $colors['accent'];
		$on_acc  = ASI_Appearance::contrast( $accent );
		$text    = '#1f2937';
		$muted   = '#6b7280';
		$font    = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
		$e       = static function ( $value ) {
			return esc_html( (string) $value );
		};
		$logo    = self::logo_url();
		$site    = self::site_name();

		$out  = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e( isset( $c['title'] ) ? $c['title'] : $site ) . '</title></head>';
		$out .= '<body style="margin:0;padding:0;background:#f3f4f6;' . $font . '">';
		$out .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 12px;"><tr><td align="center">';
		$out .= '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden;">';

		if ( ! empty( $c['badge'] ) ) {
			$out .= '<tr><td style="background:#fef3c7;color:#92400e;font-size:12px;font-weight:700;letter-spacing:.08em;text-align:center;padding:6px 12px;' . $font . '">' . $e( $c['badge'] ) . '</td></tr>';
		}
		// Encabezado: un logo claro va sobre el color principal; uno oscuro (o sin logo), sobre blanco
		// con una franja del color principal.
		$light = $logo && self::logo_is_light( $logo );
		$out  .= $light
			? '<tr><td style="background:' . $e( $primary ) . ';padding:22px 28px;" align="left">'
			: '<tr><td style="background:#ffffff;padding:22px 28px;border-bottom:4px solid ' . $e( $primary ) . ';" align="left">';
		$out .= $logo
			? '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $site ) . '" height="44" style="display:block;height:44px;width:auto;max-width:240px;border:0;">'
			: '<span style="color:' . $e( $primary ) . ';font-size:20px;font-weight:700;' . $font . '">' . $e( $site ) . '</span>';
		$out .= '</td></tr>';

		$out .= '<tr><td style="padding:28px 28px 8px;' . $font . 'color:' . $text . ';">';
		if ( ! empty( $c['title'] ) ) {
			$out .= '<h1 style="margin:0 0 10px;font-size:22px;line-height:1.3;color:' . $text . ';">' . $e( $c['title'] ) . '</h1>';
		}
		if ( ! empty( $c['intro'] ) ) {
			$out .= '<p style="margin:0 0 18px;font-size:15px;line-height:1.55;color:' . $muted . ';">' . $e( $c['intro'] ) . '</p>';
		}

		if ( ! empty( $c['rows'] ) ) {
			$out .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:10px;border-collapse:separate;margin:0 0 20px;">';
			$i    = 0;
			foreach ( $c['rows'] as $label => $value ) {
				$border = $i++ ? 'border-top:1px solid #e5e7eb;' : '';
				$cell   = is_array( $value )
					? '<a href="' . esc_url( $value['url'] ) . '" style="color:' . $e( $accent ) . ';text-decoration:none;font-weight:600;">' . $e( $value['text'] ) . '</a>'
					: $e( '' !== (string) $value ? $value : '—' );
				$out   .= '<tr><td style="' . $border . 'padding:11px 14px;font-size:13px;color:' . $muted . ';width:120px;">' . $e( $label ) . '</td><td style="' . $border . 'padding:11px 14px;font-size:15px;color:' . $text . ';">' . $cell . '</td></tr>';
			}
			$out .= '</table>';
		}

		foreach ( isset( $c['items'] ) ? (array) $c['items'] : array() as $item ) {
			$out .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:10px;border-collapse:separate;margin:0 0 12px;"><tr>';
			if ( ! empty( $item['image'] ) ) {
				$out .= '<td width="130" style="padding:10px;vertical-align:middle;"><img src="' . esc_url( $item['image'] ) . '" alt="" width="120" style="display:block;width:120px;height:auto;border-radius:6px;border:0;"></td>';
			}
			$out .= '<td style="padding:12px 14px;vertical-align:middle;' . $font . '">';
			$out .= '<div style="font-size:15px;font-weight:700;color:' . $text . ';margin:0 0 4px;">' . $e( $item['title'] ) . '</div>';
			if ( ! empty( $item['meta'] ) ) {
				$out .= '<div style="font-size:13px;color:' . $muted . ';margin:0 0 4px;">' . $e( $item['meta'] ) . '</div>';
			}
			if ( ! empty( $item['price'] ) ) {
				$out .= '<div style="font-size:15px;font-weight:700;color:' . $e( $accent ) . ';margin:0 0 8px;">' . $e( $item['price'] ) . '</div>';
			}
			if ( ! empty( $item['url'] ) ) {
				$out .= '<a href="' . esc_url( $item['url'] ) . '" style="font-size:13px;font-weight:600;color:' . $e( $accent ) . ';text-decoration:none;">Ver vehículo →</a>';
			}
			$out .= '</td></tr></table>';
		}

		if ( ! empty( $c['button']['url'] ) ) {
			$out .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:18px 0 8px;"><tr><td style="background:' . $e( $accent ) . ';border-radius:999px;">';
			$out .= '<a href="' . esc_url( $c['button']['url'] ) . '" style="display:inline-block;padding:12px 26px;color:' . $e( $on_acc ) . ';font-size:15px;font-weight:700;text-decoration:none;' . $font . '">' . $e( $c['button']['label'] ) . '</a>';
			$out .= '</td></tr></table>';
		}
		$out .= '</td></tr>';

		$out .= '<tr><td style="padding:18px 28px 26px;' . $font . 'font-size:12px;line-height:1.5;color:#9ca3af;border-top:1px solid #f0f0f0;">';
		if ( ! empty( $c['note'] ) ) {
			$out .= $e( $c['note'] ) . '<br>';
		}
		$out .= $e( $site ) . ' · <a href="' . esc_url( home_url( '/' ) ) . '" style="color:#9ca3af;">' . $e( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '</a>';
		$out .= '</td></tr></table></td></tr></table></body></html>';
		return $out;
	}

	/** Colores del chat (Apariencia → por defecto los del Kit de Elementor). */
	public static function colors() {
		$palette = ASI_Appearance::palette();
		return array(
			'primary' => isset( $palette['primary']['value'] ) ? $palette['primary']['value'] : '#1f2937',
			'accent'  => isset( $palette['button']['value'] ) ? $palette['button']['value'] : ( isset( $palette['accent']['value'] ) ? $palette['accent']['value'] : '#2563eb' ),
		);
	}

	/** Logo del sitio: el del tema, el del Kit de Elementor o el ícono del sitio. */
	public static function logo_url() {
		$id = (int) get_theme_mod( 'custom_logo' );
		if ( $id ) {
			$url = wp_get_attachment_image_url( $id, 'medium' );
			if ( $url ) {
				return $url;
			}
		}
		$kit = (int) get_option( 'elementor_active_kit' );
		if ( $kit ) {
			$settings = get_post_meta( $kit, '_elementor_page_settings', true );
			if ( ! empty( $settings['site_logo']['url'] ) ) {
				return $settings['site_logo']['url'];
			}
		}
		return (string) get_site_icon_url( 128 );
	}

	/**
	 * ¿El logo es claro (blanco o casi)? Se mide el brillo promedio de sus píxeles visibles
	 * (se ignora el fondo transparente). Se guarda una semana por URL.
	 */
	public static function logo_is_light( $url ) {
		$key    = 'asi_logo_light_' . md5( $url );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return '1' === $cached;
		}
		$light = false;
		$path  = self::local_path( $url );
		$data  = $path ? file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( $data && function_exists( 'imagecreatefromstring' ) ) {
			$image = @imagecreatefromstring( $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $image ) {
				$w     = imagesx( $image );
				$h     = imagesy( $image );
				$step  = max( 1, (int) floor( max( $w, $h ) / 60 ) );
				$sum   = 0.0;
				$count = 0;
				for ( $x = 0; $x < $w; $x += $step ) {
					for ( $y = 0; $y < $h; $y += $step ) {
						$rgba  = imagecolorat( $image, $x, $y );
						$alpha = ( $rgba >> 24 ) & 0x7F;
						if ( $alpha > 100 ) {
							continue; // Transparente.
						}
						$sum += 0.299 * ( ( $rgba >> 16 ) & 0xFF ) + 0.587 * ( ( $rgba >> 8 ) & 0xFF ) + 0.114 * ( $rgba & 0xFF );
						$count++;
					}
				}
				imagedestroy( $image );
				$light = $count > 0 && ( $sum / $count ) > 200;
			}
		}
		set_transient( $key, $light ? '1' : '0', WEEK_IN_SECONDS );
		return $light;
	}

	/** Ruta en disco de una URL de la biblioteca de medios (o '' si es externa). */
	private static function local_path( $url ) {
		$uploads = wp_upload_dir();
		if ( 0 === strpos( $url, $uploads['baseurl'] ) ) {
			$path = $uploads['basedir'] . substr( $url, strlen( $uploads['baseurl'] ) );
			return is_file( $path ) ? $path : '';
		}
		return '';
	}

	public static function site_name() {
		$settings = ASI_Settings::all();
		return ! empty( $settings['assistant_site_name'] ) ? $settings['assistant_site_name'] : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}
}
