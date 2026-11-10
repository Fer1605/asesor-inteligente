<?php
/**
 * Panel del plugin: menú "Asesor inteligente" con pestañas.
 *
 *  - Inicio:     estado, visibilidad, idiomas y países, índice, y guía de uso.
 *  - Apariencia: colores (del Kit de Elementor por defecto), esquinas y asistente, con vista previa.
 *  - Conexión:   páginas del chat por idioma y cómo se conecta con el buscador del sitio.
 *  - Contenido:  lo que el plugin descubrió, con correcciones opcionales.
 *  - Aprendizaje: preguntas sin buena respuesta, respuestas enseñadas, sinónimos propios y clics.
 *  - Probar:     conversación de prueba en cualquier idioma/país.
 *  - Guía:       manual de uso.
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || exit;

class ASI_Admin {

	const PAGE  = 'asesor-inteligente';
	const NONCE = 'asi_admin_action';

	const TABS = array(
		'inicio'     => 'Inicio',
		'apariencia' => 'Apariencia',
		'conexion'   => 'Conexión',
		'contenido'  => 'Contenido',
		'aprendizaje' => 'Aprendizaje',
		'probar'     => 'Probar',
		'guia'       => 'Guía',
	);

	const POST_TYPE_ROLES = array(
		'catalog' => 'Catálogo (tarjetas)',
		'info'    => 'Información del sitio',
		'article' => 'Artículos / noticias',
	);

	const FIELD_ROLES = array(
		'code'      => 'Código / SKU / modelo',
		'brand'     => 'Marca',
		'price'     => 'Precio',
		'price_old' => 'Precio anterior',
		'currency'  => 'Moneda',
		'summary'   => 'Descripción',
		'attribute' => 'Especificación',
		'flag'      => 'Distintivo (sí/no)',
		'related'   => 'Relacionados',
		'image'     => 'Imagen',
		'document'  => 'Documento / PDF',
		'link'      => 'Enlace',
		'ignore'    => 'Ignorar',
	);

	const COLOR_LABELS = array(
		'primary'    => array( 'Principal', 'Encabezado, mensajes del visitante y botón de enviar.' ),
		'accent'     => array( 'Acento', 'Sugerencias, enlaces, precios y distintivos.' ),
		'text'       => array( 'Texto', 'Color de los textos.' ),
		'background' => array( 'Fondo', 'Fondo del chat y de las tarjetas.' ),
		'surface'    => array( 'Burbujas', 'Mensajes del asistente y campo de texto.' ),
	);

	const BUTTON_LABELS = array(
		'button'       => array( 'Enviar', 'Fondo del botón de enviar.' ),
		'button_hover' => array( 'Enviar · al pasar el mouse', 'Fondo del botón de enviar en hover.' ),
		'reset_bg'     => array( 'Nueva conversación', 'Fondo del botón del encabezado.' ),
		'reset_text'   => array( 'Nueva conversación · texto', 'Texto e ícono de ese botón.' ),
		'reset_hover'  => array( 'Nueva conversación · al pasar el mouse', 'Fondo de ese botón en hover.' ),
	);

	const RADIUS_LABELS = array(
		'square'  => 'Rectas',
		'soft'    => 'Suaves',
		'rounded' => 'Redondeadas',
		'pill'    => 'Muy redondeadas',
	);

	/** @var ASI_Plugin */
	private $plugin;

	public function __construct( ASI_Plugin $plugin ) {
		$this->plugin = $plugin;
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_bi_action', array( $this, 'handle_action' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function menu() {
		add_menu_page( 'Asesor inteligente', 'Asesor inteligente', 'manage_options', self::PAGE, array( $this, 'render' ), 'dashicons-format-chat', 58 );
	}

	/**
	 * Recursos según la pestaña.
	 *
	 * @param string $hook Página actual.
	 */
	public function assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'asi-admin', ASI_URL . 'assets/admin/admin.css', array(), ASI_VERSION );
		$tab = $this->current_tab();
		if ( 'apariencia' === $tab ) {
			wp_enqueue_media();
			wp_enqueue_style( 'wp-color-picker' );
			wp_enqueue_style( 'asi-chat', ASI_URL . 'assets/chat/chat.css', array(), ASI_VERSION );
			wp_enqueue_script( 'asi-admin-appearance', ASI_URL . 'assets/admin/appearance.js', array( 'wp-color-picker', 'jquery' ), ASI_VERSION, true );
		}
		if ( 'aprendizaje' === $tab ) {
			wp_enqueue_script( 'asi-admin-learning', ASI_URL . 'assets/admin/learning.js', array(), ASI_VERSION, true );
			wp_localize_script(
				'asi-admin-learning',
				'asiLearning',
				array(
					'endpoint' => rest_url( ASI_REST_Controller::NAMESPACE_V1 . '/admin/find' ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
				)
			);
		}
		if ( 'probar' === $tab ) {
			wp_enqueue_style( 'asi-chat', ASI_URL . 'assets/chat/chat.css', array(), ASI_VERSION );
			wp_enqueue_script( 'asi-admin-tester', ASI_URL . 'assets/admin/tester.js', array(), ASI_VERSION, true );
			wp_localize_script(
				'asi-admin-tester',
				'asiTester',
				array(
					'endpoint' => rest_url( ASI_REST_Controller::NAMESPACE_V1 . '/chat' ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
				)
			);
		}
	}

	private function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'inicio'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// Los sinónimos ahora viven en Aprendizaje (enlaces viejos siguen funcionando).
		$tab = 'sinonimos' === $tab ? 'aprendizaje' : $tab;
		return isset( self::TABS[ $tab ] ) ? $tab : 'inicio';
	}

	private function tab_url( $tab, array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	// ------------------------------------------------------------------
	// Acciones
	// ------------------------------------------------------------------

	/**
	 * Acciones del panel (POST con nonce).
	 */
	public function handle_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'asesor-inteligente' ) );
		}
		check_admin_referer( self::NONCE );

		$action = isset( $_POST['asi_do'] ) ? sanitize_key( wp_unslash( $_POST['asi_do'] ) ) : '';
		$tab    = isset( $_POST['asi_tab'] ) ? sanitize_key( wp_unslash( $_POST['asi_tab'] ) ) : 'inicio';
		$notice = $action;
		$extra  = array();

		switch ( $action ) {
			case 'mode':
				ASI_Settings::set_mode( isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'admin' );
				break;

			case 'discover':
				// En segundo plano: en servidores lentos no debe correr dentro de la petición del admin.
				$this->plugin->languages->flush();
				ASI_Indexer::schedule_discovery();
				break;

			case 'reindex':
				$this->plugin->indexer->start_full_reindex();
				break;

			case 'batch':
				// Por si WP-Cron está desactivado o atrasado en el servidor.
				$this->plugin->indexer->run_batch();
				break;

			case 'schema':
				$this->save_schema_overrides();
				$this->plugin->indexer->start_full_reindex();
				break;

			case 'appearance':
				$this->save_appearance();
				break;

			case 'money':
				$this->save_money();
				break;

			case 'flags':
				$flags = array();
				foreach ( isset( $_POST['card_flags'] ) && is_array( $_POST['card_flags'] ) ? wp_unslash( $_POST['card_flags'] ) : array() as $label => $rule ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$flags[ sanitize_text_field( (string) $label ) ] = array(
						'show' => ! empty( $rule['show'] ),
						'as'   => sanitize_text_field( isset( $rule['as'] ) ? (string) $rule['as'] : '' ),
					);
				}
				ASI_Settings::update( array( 'card_flags' => $flags ) );
				break;

			case 'actions':
				$mode  = isset( $_POST['actions_mode'] ) ? sanitize_key( wp_unslash( $_POST['actions_mode'] ) ) : 'test';
				$email = isset( $_POST['test_email'] ) ? sanitize_email( wp_unslash( $_POST['test_email'] ) ) : '';
				ASI_Settings::update(
					array(
						'actions_mode'     => in_array( $mode, array( 'off', 'test', 'live' ), true ) ? $mode : 'test',
						'test_email'       => is_email( $email ) ? $email : '',
						'finance_rate'     => isset( $_POST['finance_rate'] ) ? (string) max( 0, (float) str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['finance_rate'] ) ) ) ) : '',
						'finance_down_min' => isset( $_POST['finance_down_min'] ) ? max( 0, min( 90, (int) $_POST['finance_down_min'] ) ) : 20,
					)
				);
				break;

			case 'connection':
				$search_mode = isset( $_POST['search_mode'] ) ? sanitize_key( wp_unslash( $_POST['search_mode'] ) ) : 'none';
				ASI_Settings::update( array( 'search_mode' => in_array( $search_mode, ASI_Settings::SEARCH_MODES, true ) ? $search_mode : 'none' ) );
				break;

			case 'create_pages':
				// El aviso dice cuántas páginas creó de verdad.
				$extra['asi_count'] = $this->plugin->chat_page->create_pages();
				break;

			case 'synonyms':
				$this->save_synonyms();
				break;

			case 'teach':
				$lang = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
				$key  = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
				$q    = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
				$ids  = isset( $_POST['ids'] ) ? array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['ids'] ) ) ) ) : array();
				ASI_Learning::teach( $lang, $key, $q, $ids );
				break;

			case 'teach_synonym':
				$this->teach_synonym();
				break;

			case 'dismiss':
				ASI_Learning::dismiss( isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '' );
				break;

			case 'forget':
				ASI_Learning::forget( isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '', isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '' );
				break;

			case 'clear_questions':
				delete_option( ASI_Learning::QUESTIONS_OPTION );
				delete_option( ASI_REST_Controller::MISSES_OPTION );
				break;
		}

		wp_safe_redirect( $this->tab_url( isset( self::TABS[ $tab ] ) ? $tab : 'inicio', array_merge( array( 'asi_notice' => $notice ), $extra ) ) );
		exit;
	}

	private function save_appearance() {
		$colors = array();
		foreach ( ASI_Settings::COLOR_KEYS as $key ) {
			$value          = isset( $_POST['colors'][ $key ] ) ? sanitize_text_field( wp_unslash( $_POST['colors'][ $key ] ) ) : '';
			$colors[ $key ] = ASI_Appearance::is_color( $value ) ? $value : '';
		}
		$radius  = isset( $_POST['radius'] ) ? sanitize_key( wp_unslash( $_POST['radius'] ) ) : 'rounded';
		$welcome = array();
		foreach ( isset( $_POST['welcome'] ) && is_array( $_POST['welcome'] ) ? wp_unslash( $_POST['welcome'] ) : array() as $lang => $text ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$text = sanitize_textarea_field( $text );
			if ( '' !== $text ) {
				$welcome[ sanitize_key( $lang ) ] = $text;
			}
		}

		$tone = isset( $_POST['tone'] ) ? sanitize_key( wp_unslash( $_POST['tone'] ) ) : 'tu';
		ASI_Settings::update(
			array(
				'tone'             => 'usted' === $tone ? 'usted' : 'tu',
				'emojis'           => ! empty( $_POST['emojis'] ),
				'colors'           => $colors,
				'radius'           => isset( ASI_Settings::RADII[ $radius ] ) ? $radius : 'rounded',
				'assistant_name'   => isset( $_POST['assistant_name'] ) ? sanitize_text_field( wp_unslash( $_POST['assistant_name'] ) ) : '',
				'assistant_avatar' => isset( $_POST['assistant_avatar'] ) ? absint( $_POST['assistant_avatar'] ) : 0,
				'welcome'          => $welcome,
			)
		);
	}

	/**
	 * "Esta pregunta significa lo mismo que…": agrega la pregunta como sinónimo de esa palabra.
	 */
	private function teach_synonym() {
		$lang     = isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
		$q        = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		$word     = isset( $_POST['word'] ) ? sanitize_text_field( wp_unslash( $_POST['word'] ) ) : '';
		$id       = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$all      = $this->plugin->languages->all();
		$language = isset( $all[ $lang ]['language'] ) ? $all[ $lang ]['language'] : '';
		$q        = trim( str_replace( ',', ' ', $q ) );
		$word     = trim( str_replace( ',', ' ', $word ) );
		if ( '' === $language || '' === $q || '' === $word ) {
			return;
		}
		$settings = ASI_Settings::all();
		$texts    = (array) $settings['synonyms_text'];
		$text     = isset( $texts[ $language ] ) ? trim( $texts[ $language ] ) : '';
		$texts[ $language ] = ( '' !== $text ? $text . "\n" : '' ) . $word . ', ' . $q;
		$groups             = (array) $settings['synonyms'];
		$groups[ $language ] = ASI_Settings::parse_synonyms( $texts[ $language ] );
		ASI_Settings::update( array( 'synonyms_text' => $texts, 'synonyms' => $groups ) );
		ASI_Index_Repository::flush_vocabulary();
		ASI_Learning::dismiss( $id );
	}

	/**
	 * Guarda la moneda base, qué significa cada código de moneda del sitio y los tipos de cambio.
	 * No hace falta reindexar: la moneda se resuelve al responder.
	 */
	private function save_money() {
		$known = array_keys( ASI_Money::CURRENCIES );
		$base  = isset( $_POST['currency_base'] ) ? strtoupper( sanitize_key( wp_unslash( $_POST['currency_base'] ) ) ) : '';
		$map   = array();
		foreach ( isset( $_POST['currency_map'] ) && is_array( $_POST['currency_map'] ) ? wp_unslash( $_POST['currency_map'] ) : array() as $value => $iso ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$iso = strtoupper( sanitize_key( $iso ) );
			if ( in_array( $iso, $known, true ) ) {
				$map[ sanitize_text_field( (string) $value ) ] = $iso;
			}
		}
		$rates = array();
		foreach ( isset( $_POST['currency_rates'] ) && is_array( $_POST['currency_rates'] ) ? wp_unslash( $_POST['currency_rates'] ) : array() as $iso => $rate ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$iso  = strtoupper( sanitize_key( $iso ) );
			$rate = (float) str_replace( ',', '.', sanitize_text_field( (string) $rate ) );
			if ( in_array( $iso, $known, true ) && $rate > 0 ) {
				$rates[ $iso ] = $rate;
			}
		}
		ASI_Settings::update(
			array(
				'currency_base'  => in_array( $base, $known, true ) ? $base : '',
				'currency_map'   => $map,
				'currency_rates' => $rates,
			)
		);
	}

	/**
	 * Valores distintos de los campos de moneda del sitio (solo campos sueltos, no dentro de JSON).
	 *
	 * @param array $schema Esquema.
	 * @return string[] valor => cuántos productos lo usan
	 */
	private function currency_values( array $schema ) {
		global $wpdb;
		$values = array();
		foreach ( (array) $schema['post_types'] as $type ) {
			foreach ( (array) $type['fields'] as $name => $field ) {
				if ( 'currency' !== $field['role'] || ! in_array( $field['source'], array( 'meta', 'acf' ), true ) ) {
					continue;
				}
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_value AS v, COUNT(*) AS n FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' GROUP BY meta_value ORDER BY n DESC LIMIT 12", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				foreach ( (array) $rows as $row ) {
					$values[ (string) $row->v ] = ( isset( $values[ (string) $row->v ] ) ? $values[ (string) $row->v ] : 0 ) + (int) $row->n;
				}
			}
		}
		return $values;
	}

	/**
	 * Tarjeta "Precios y moneda" (pestaña Contenido).
	 *
	 * @param array $schema Esquema.
	 */
	private function money_card( array $schema ) {
		$settings = ASI_Settings::all();
		$money    = ASI_Settings::money();
		$values   = $this->currency_values( $schema );
		$isos     = array_keys( ASI_Money::CURRENCIES );
		$used     = array( $money['base'] );
		foreach ( $values as $value => $count ) {
			$iso    = ASI_Money::currency_from_value( $value, $money );
			$used[] = $iso;
		}
		$used = array_values( array_unique( array_filter( array_merge( $used, array_keys( (array) $settings['currency_rates'] ) ) ) ) );
		$this->card_open( 'Precios y moneda', 'dashicons-money-alt', 'green', 'asi-moneda' );
		?>
			<p class="asi-muted">Para filtrar por presupuesto, el asistente convierte todo a la <strong>moneda base</strong> antes de comparar: nunca compara dólares con quetzales. El cliente puede escribir el monto en cualquier moneda (“hasta $40 mil”, “entre 100 y 200 mil quetzales”); si no dice cuál, se usa la base.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php $this->hidden_fields( 'money', 'contenido' ); ?>
				<p><label><strong>Moneda base</strong><br>
					<select name="currency_base">
						<option value="">Automática<?php echo $money['base'] && '' === $settings['currency_base'] ? esc_html( ' (' . $money['base'] . ')' ) : ''; ?></option>
						<?php foreach ( $isos as $iso ) : ?>
							<option value="<?php echo esc_attr( $iso ); ?>" <?php selected( $settings['currency_base'], $iso ); ?>><?php echo esc_html( $iso . ' · ' . ASI_Money::CURRENCIES[ $iso ]['symbol'] ); ?></option>
						<?php endforeach; ?>
					</select></label></p>
				<?php if ( $values ) : ?>
					<p><strong>Qué significa cada valor del campo de moneda</strong></p>
					<table class="widefat striped asi-table"><thead><tr><th>Valor guardado</th><th>Productos</th><th>Moneda</th></tr></thead><tbody>
						<?php foreach ( $values as $value => $count ) : ?>
							<?php $current = ASI_Money::currency_from_value( $value, $money ); ?>
							<tr><td><code><?php echo esc_html( $value ); ?></code></td><td><?php echo esc_html( (string) $count ); ?></td><td>
								<select name="currency_map[<?php echo esc_attr( $value ); ?>]">
									<option value=""><?php echo $current ? esc_html( 'Automática (' . $current . ')' ) : '— Sin definir —'; ?></option>
									<?php foreach ( $isos as $iso ) : ?>
										<option value="<?php echo esc_attr( $iso ); ?>" <?php selected( isset( $settings['currency_map'][ $value ] ) ? $settings['currency_map'][ $value ] : '', $iso ); ?>><?php echo esc_html( $iso ); ?></option>
									<?php endforeach; ?>
								</select>
								<?php if ( ! $current ) : ?><span class="asi-tag asi-tag--warn">Sin definir: esos productos no entran en búsquedas por presupuesto</span><?php endif; ?>
							</td></tr>
						<?php endforeach; ?>
					</tbody></table>
				<?php endif; ?>
				<?php if ( $money['base'] ) : ?>
					<p><strong>Tipo de cambio</strong> <span class="asi-muted">(cuántos <?php echo esc_html( $money['base'] ); ?> vale 1 unidad de cada moneda)</span></p>
					<?php foreach ( array_diff( $used, array( $money['base'] ) ) ?: array( 'USD' === $money['base'] ? 'EUR' : 'USD' ) as $iso ) : ?>
						<?php $reference = ASI_Money::rate( $iso, array( 'base' => $money['base'] ) ); ?>
						<p><label>1 <?php echo esc_html( $iso ); ?> = <input type="text" inputmode="decimal" size="8" name="currency_rates[<?php echo esc_attr( $iso ); ?>]" value="<?php echo esc_attr( isset( $settings['currency_rates'][ $iso ] ) ? (string) $settings['currency_rates'][ $iso ] : '' ); ?>" placeholder="<?php echo esc_attr( null === $reference ? '' : (string) round( $reference, 4 ) ); ?>"> <?php echo esc_html( $money['base'] ); ?></label>
						<?php if ( empty( $settings['currency_rates'][ $iso ] ) && null !== $reference ) : ?><span class="asi-muted">Vacío = valor de referencia <?php echo esc_html( (string) round( $reference, 4 ) ); ?>. Confírmalo con el que usa el negocio.</span><?php endif; ?></p>
					<?php endforeach; ?>
				<?php endif; ?>
				<p class="asi-actions"><?php submit_button( 'Guardar moneda', 'primary', 'submit', false ); ?></p>
			</form>
		<?php
		$this->card_close();
	}

	/**
	 * Tarjeta "Sellos en las tarjetas" (pestaña Contenido).
	 */
	private function flags_card() {
		$lang     = '';
		foreach ( $this->plugin->languages->all() as $slug => $language ) {
			$lang = $slug;
			break;
		}
		$shares   = ASI_Index_Repository::flag_shares( $lang );
		$settings = (array) ASI_Settings::all()['card_flags'];
		if ( ! $shares ) {
			return;
		}
		$this->card_open( 'Sellos en las tarjetas', 'dashicons-awards', 'amber', 'asi-sellos' );
		?>
			<p class="asi-muted">Por defecto solo se muestran los sellos que distinguen: los que tiene la mitad del catálogo o menos. Un sello que tienen casi todos (“Nuevo”, “Garantía activa”) no le dice nada al cliente. Aquí puedes elegirlos y cambiar cómo se llaman.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php $this->hidden_fields( 'flags', 'contenido' ); ?>
				<table class="widefat striped asi-table"><thead><tr><th>Mostrar</th><th>Sello del sitio</th><th>Lo tienen</th><th>Se muestra como</th></tr></thead><tbody>
				<?php foreach ( $shares as $label => $share ) : ?>
					<?php
					$rule = isset( $settings[ $label ] ) ? (array) $settings[ $label ] : array();
					$show = isset( $rule['show'] ) ? (bool) $rule['show'] : $share <= 0.5;
					?>
					<tr>
						<td><input type="checkbox" name="card_flags[<?php echo esc_attr( $label ); ?>][show]" value="1" <?php checked( $show ); ?>></td>
						<td><?php echo esc_html( $label ); ?></td>
						<td><?php echo esc_html( round( $share * 100 ) . ' %' ); ?></td>
						<td><input type="text" name="card_flags[<?php echo esc_attr( $label ); ?>][as]" value="<?php echo esc_attr( isset( $rule['as'] ) ? $rule['as'] : '' ); ?>" placeholder="<?php echo esc_attr( $label ); ?>"></td>
					</tr>
				<?php endforeach; ?>
				</tbody></table>
				<p class="asi-actions"><?php submit_button( 'Guardar sellos', 'primary', 'submit', false ); ?></p>
			</form>
		<?php
		$this->card_close();
	}

	private function save_synonyms() {
		$texts  = array();
		$groups = array();
		foreach ( isset( $_POST['synonyms'] ) && is_array( $_POST['synonyms'] ) ? wp_unslash( $_POST['synonyms'] ) : array() as $language => $text ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$language = sanitize_key( $language );
			$text     = sanitize_textarea_field( $text );
			if ( preg_match( '/^[a-z]{2}$/', $language ) ) {
				$texts[ $language ]  = $text;
				$groups[ $language ] = ASI_Settings::parse_synonyms( $text );
			}
		}
		ASI_Settings::update( array( 'synonyms_text' => $texts, 'synonyms' => $groups ) );
		ASI_Index_Repository::flush_vocabulary();
	}

	/**
	 * Guarda las correcciones del esquema y las marca para que un nuevo descubrimiento no las pise.
	 */
	private function save_schema_overrides() {
		$schema = ASI_Schema_Discovery::get_schema();
		$input  = isset( $_POST['asi_types'] ) && is_array( $_POST['asi_types'] ) ? wp_unslash( $_POST['asi_types'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- se valida campo a campo.

		foreach ( $schema['post_types'] as $post_type => &$type ) {
			$posted = isset( $input[ $post_type ] ) ? (array) $input[ $post_type ] : array();
			$manual = isset( $type['manual'] ) ? $type['manual'] : array( 'fields' => array() );

			$type['enabled']   = ! empty( $posted['enabled'] );
			$manual['enabled'] = true;

			if ( isset( $posted['role'] ) && isset( self::POST_TYPE_ROLES[ $posted['role'] ] ) && $posted['role'] !== $type['role'] ) {
				$type['role']   = $posted['role'];
				$manual['role'] = true;
			}

			foreach ( (array) ( isset( $posted['fields'] ) ? $posted['fields'] : array() ) as $field_name => $role ) {
				$role = sanitize_key( $role );
				if ( isset( $type['fields'][ $field_name ] ) && isset( self::FIELD_ROLES[ $role ] ) && $role !== $type['fields'][ $field_name ]['role'] ) {
					$type['fields'][ $field_name ]['role'] = $role;
					$manual['fields'][]                    = $field_name;
				}
			}
			$manual['fields'] = array_values( array_unique( $manual['fields'] ) );
			$type['manual']   = $manual;
		}
		unset( $type );

		update_option( ASI_Schema_Discovery::OPTION, $schema, false );
	}

	// ------------------------------------------------------------------
	// Página
	// ------------------------------------------------------------------

	const TAB_ICONS = array(
		'inicio'     => 'dashicons-admin-home',
		'apariencia' => 'dashicons-admin-appearance',
		'conexion'   => 'dashicons-admin-links',
		'contenido'  => 'dashicons-database',
		'aprendizaje' => 'dashicons-welcome-learn-more',
		'probar'     => 'dashicons-format-chat',
		'guia'       => 'dashicons-book-alt',
	);

	public function render() {
		$tab      = $this->current_tab();
		$notice   = isset( $_GET['asi_notice'] ) ? sanitize_key( wp_unslash( $_GET['asi_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = array(
			'mode'         => 'Visibilidad guardada.',
			'discover'     => 'El descubrimiento quedó programado en segundo plano; al terminar empieza el reindexado. Recarga en un minuto.',
			'reindex'      => 'Reindexado iniciado en segundo plano, por lotes.',
			'batch'        => 'Se procesó un lote.',
			'schema'       => 'Correcciones guardadas. El reindexado empezó en segundo plano.',
			'appearance'   => 'Apariencia guardada.',
			'connection'   => 'Conexión guardada.',
			'create_pages' => $this->pages_notice(),
			'synonyms'     => 'Sinónimos guardados. Se aplican desde la próxima búsqueda.',
			'clear_questions' => 'Lista de preguntas vaciada.',
			'teach'        => 'Listo: desde ahora el asistente responde esa pregunta con lo que elegiste.',
			'teach_synonym' => 'Sinónimo agregado. Se aplica desde la próxima búsqueda.',
			'dismiss'      => 'Pregunta quitada de la lista. El asistente no cambió nada.',
			'forget'       => 'Respuesta enseñada eliminada.',
		);
		?>
		<div class="wrap asi-admin">
			<header class="asi-header">
				<div class="asi-header__brand">
					<span class="asi-header__logo dashicons dashicons-format-chat" aria-hidden="true"></span>
					<div>
						<h1 class="asi-header__title">Asesor inteligente</h1>
						<p class="asi-header__subtitle">Asistente de búsqueda conversacional · v<?php echo esc_html( ASI_VERSION ); ?></p>
					</div>
				</div>
				<?php $this->mode_badge(); ?>
			</header>

			<?php if ( isset( $messages[ $notice ] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $messages[ $notice ] ); ?></p></div>
			<?php endif; ?>

			<nav class="asi-tabs" aria-label="Secciones">
				<?php foreach ( self::TABS as $key => $label ) : ?>
					<a href="<?php echo esc_url( $this->tab_url( $key ) ); ?>" class="asi-tabs__item<?php echo $tab === $key ? ' is-active' : ''; ?>" <?php echo $tab === $key ? 'aria-current="page"' : ''; ?>>
						<span class="dashicons <?php echo esc_attr( self::TAB_ICONS[ $key ] ); ?>" aria-hidden="true"></span>
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="asi-admin__tab">
				<?php call_user_func( array( $this, 'tab_' . $tab ) ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Aviso tras crear páginas del chat, con lo que pasó de verdad.
	 *
	 * @return string
	 */
	private function pages_notice() {
		$count = isset( $_GET['asi_count'] ) ? absint( $_GET['asi_count'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 0 === $count ) {
			return 'No faltaba ninguna página del chat: ya había una para cada idioma.';
		}
		return 1 === $count ? 'Se creó 1 página del chat.' : sprintf( 'Se crearon %d páginas del chat, una por cada idioma que faltaba.', $count );
	}

	/**
	 * Insignia del modo de visibilidad, siempre a la vista.
	 */
	private function mode_badge() {
		$mode   = ASI_Settings::mode();
		$labels = array(
			'off'    => array( 'Apagado', 'dashicons-hidden' ),
			'admin'  => array( 'Solo administradores', 'dashicons-lock' ),
			'public' => array( 'Público', 'dashicons-visibility' ),
		);
		printf(
			'<a class="asi-badge asi-badge--%1$s" href="%2$s"><span class="dashicons %3$s" aria-hidden="true"></span>%4$s</a>',
			esc_attr( $mode ),
			esc_url( $this->tab_url( 'inicio' ) . '#asi-visibilidad' ),
			esc_attr( $labels[ $mode ][1] ),
			esc_html( $labels[ $mode ][0] )
		);
	}

	/**
	 * Abre una tarjeta con título e ícono de color.
	 *
	 * @param string $title Título.
	 * @param string $icon  Dashicon.
	 * @param string $tone  blue | teal | amber | purple | green.
	 * @param string $id    Ancla opcional.
	 */
	private function card_open( $title, $icon, $tone = 'blue', $id = '' ) {
		printf(
			'<section class="asi-panel asi-panel--%1$s"%2$s><h2 class="asi-panel__title"><span class="asi-icon asi-icon--%1$s dashicons %3$s" aria-hidden="true"></span>%4$s</h2>',
			esc_attr( $tone ),
			$id ? ' id="' . esc_attr( $id ) . '"' : '',
			esc_attr( $icon ),
			esc_html( $title )
		);
	}

	private function card_close() {
		echo '</section>';
	}

	// ---------- Inicio ----------

	private function tab_inicio() {
		$languages = $this->plugin->languages->all();
		$state     = ASI_Indexer::get_state();
		$stats     = ASI_Index_Table::stats();
		$next      = wp_next_scheduled( ASI_Indexer::CRON_BATCH );
		$questions = ASI_Learning::questions();
		$documents = array_sum( array_map( 'intval', wp_list_pluck( $stats, 'total' ) ) );
		$countries = count( $this->plugin->languages->countries() );
		$pages     = 0;
		foreach ( array_keys( $languages ) as $slug ) {
			$pages += ASI_Chat_Page::url_for_language( $slug ) ? 1 : 0;
		}
		?>
		<div class="asi-hero">
			<span class="asi-hero__icon dashicons dashicons-lightbulb" aria-hidden="true"></span>
			<p class="asi-hero__lead">
				<strong>Un asistente que entiende lo que buscan tus visitantes</strong> y responde con los productos, servicios e información de tu sitio,
				en el idioma y país de cada página. Se configura solo al activarse, todo corre en tu servidor y no usa servicios externos ni tiene costo por uso.
			</p>
		</div>
		<p class="asi-note asi-note--ok"><strong>¿Primera vez aquí?</strong> Los pasos para empezar, qué puede preguntar la gente y cómo enseñarle están en la <a href="<?php echo esc_url( $this->tab_url( 'guia' ) ); ?>">Guía</a>.</p>

		<div class="asi-stats">
			<?php
			$this->stat( 'dashicons-media-document', 'blue', number_format_i18n( $documents ), 'Documentos indexados', ! empty( $state['running'] ) ? 'Indexando…' : 'Al día' );
			$this->stat( 'dashicons-translation', 'teal', count( $languages ), 1 === count( $languages ) ? 'Idioma' : 'Idiomas', $this->adapter_label() );
			$this->stat( 'dashicons-admin-site-alt3', 'purple', max( 1, $countries ), 1 >= $countries ? 'País' : 'Países', $countries > 1 ? 'Sitio multipaís' : 'Sin filtro de país' );
			$this->stat( 'dashicons-format-chat', 'green', $pages . ' / ' . count( $languages ), 'Páginas del chat', $pages < count( $languages ) ? 'Faltan páginas' : 'Listas' );
			$this->stat( 'dashicons-welcome-learn-more', 'amber', count( $questions ), 'Preguntas por revisar', count( $questions ) ? 'Enséñale en Aprendizaje' : 'Nada pendiente' );
			?>
		</div>

		<div class="asi-grid">
			<?php $this->card_open( 'Visibilidad', 'dashicons-visibility', 'blue', 'asi-visibilidad' ); ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php $this->hidden_fields( 'mode', 'inicio' ); ?>
					<div class="asi-options">
						<?php
						$labels = array(
							'off'    => array( 'Apagado', 'Nadie lo ve y el buscador del sitio funciona como siempre.', 'dashicons-hidden' ),
							'admin'  => array( 'Solo administradores', 'Para probar en producción sin que los visitantes vean nada.', 'dashicons-lock' ),
							'public' => array( 'Público', 'Todos los visitantes. Las páginas del chat se publican solas.', 'dashicons-visibility' ),
						);
						foreach ( $labels as $mode => $label ) :
							?>
							<label class="asi-option">
								<input type="radio" name="mode" value="<?php echo esc_attr( $mode ); ?>" <?php checked( ASI_Settings::mode(), $mode ); ?>>
								<span class="dashicons <?php echo esc_attr( $label[2] ); ?>" aria-hidden="true"></span>
								<span class="asi-option__text"><strong><?php echo esc_html( $label[0] ); ?></strong><span><?php echo esc_html( $label[1] ); ?></span></span>
							</label>
						<?php endforeach; ?>
					</div>
					<p class="asi-actions"><?php submit_button( 'Guardar visibilidad', 'primary', 'submit', false ); ?></p>
				</form>
			<?php $this->card_close(); ?>

			<?php $this->card_open( 'Índice de búsqueda', 'dashicons-database', 'teal' ); ?>
				<p class="asi-index-status">
					<span class="asi-dot asi-dot--<?php echo ! empty( $state['running'] ) ? 'amber' : 'green'; ?>"></span>
					<?php
					printf(
						'%1$s · %2$d de %3$d recorridos · %4$d indexados',
						! empty( $state['running'] ) ? '<strong>En curso</strong>' : '<strong>Al día</strong>',
						(int) $state['done'],
						(int) $state['total'],
						(int) $state['indexed']
					); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</p>
				<?php if ( ! empty( $state['ended_at'] ) ) : ?>
					<p class="asi-muted">Última actualización completa: <?php echo esc_html( wp_date( 'j M Y, H:i', $state['ended_at'] ) ); ?>. Después se actualiza solo al editar o publicar.</p>
				<?php endif; ?>
				<?php if ( ! empty( $state['running'] ) && ( ! $next || $next < time() - 120 ) ) : ?>
					<p class="asi-note asi-note--warn">WP-Cron parece atrasado o desactivado en este servidor. Avanza el índice a mano con “Procesar un lote ahora”.</p>
				<?php endif; ?>
				<div class="asi-actions">
					<?php $this->action_button( 'discover', 'Volver a descubrir el sitio', 'inicio', 'button', 'dashicons-update' ); ?>
					<?php $this->action_button( 'reindex', 'Reindexar todo', 'inicio', 'button', 'dashicons-database-view' ); ?>
					<?php if ( ! empty( $state['running'] ) ) : ?>
						<?php $this->action_button( 'batch', 'Procesar un lote ahora', 'inicio', 'button', 'dashicons-controls-forward' ); ?>
					<?php endif; ?>
				</div>
			<?php $this->card_close(); ?>
		</div>

		<?php $this->card_open( 'Idiomas y países', 'dashicons-translation', 'purple' ); ?>
			<p class="asi-muted"><?php echo 'none' === $this->plugin->languages->adapter()->get_id() ? 'El sitio no usa un plugin de idiomas: el asistente responde en el idioma del sitio.' : 'Detectados automáticamente desde ' . esc_html( $this->adapter_label() ) . '. Cada página del sitio responde solo con el contenido de su idioma y país.'; ?></p>
			<table class="asi-table">
				<thead><tr><th>Idioma</th><th>Locale</th><th>País</th><th>Documentos</th><th>Página del chat</th></tr></thead>
				<tbody>
				<?php
				$per_lang = array();
				foreach ( $stats as $row ) {
					$per_lang[ $row['lang'] ] = ( isset( $per_lang[ $row['lang'] ] ) ? $per_lang[ $row['lang'] ] : 0 ) + (int) $row['total'];
				}
				foreach ( $languages as $slug => $language ) :
					$url = ASI_Chat_Page::url_for_language( $slug );
					?>
					<tr>
						<td><span class="asi-tag asi-tag--code"><?php echo esc_html( $slug ); ?></span><?php echo $language['is_default'] ? ' <span class="asi-muted">por defecto</span>' : ''; ?></td>
						<td class="asi-muted"><?php echo esc_html( $language['locale'] ); ?></td>
						<td><?php echo $language['country'] ? '<strong>' . esc_html( strtoupper( $language['country'] ) ) . '</strong> ' . esc_html( $language['country_name'] ) : '<span class="asi-muted">Sin filtro de país</span>'; ?></td>
						<td><?php echo (int) ( isset( $per_lang[ $slug ] ) ? $per_lang[ $slug ] : 0 ); ?></td>
						<td>
							<?php if ( $url ) : ?>
								<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( wp_parse_url( $url, PHP_URL_PATH ) ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></a>
							<?php else : ?>
								<a class="asi-tag asi-tag--warn" href="<?php echo esc_url( $this->tab_url( 'conexion' ) ); ?>">Crear página</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php $this->card_close(); ?>

		<?php
	}

	/**
	 * Tarjeta pequeña con una cifra.
	 */
	private function stat( $icon, $tone, $value, $label, $hint ) {
		printf(
			'<div class="asi-stat"><span class="asi-icon asi-icon--%1$s dashicons %2$s" aria-hidden="true"></span><div><strong class="asi-stat__value">%3$s</strong><span class="asi-stat__label">%4$s</span><span class="asi-stat__hint">%5$s</span></div></div>',
			esc_attr( $tone ),
			esc_attr( $icon ),
			esc_html( $value ),
			esc_html( $label ),
			esc_html( $hint )
		);
	}

	// ---------- Apariencia ----------

	private function tab_apariencia() {
		$settings  = ASI_Settings::all();
		$palette   = ASI_Appearance::palette();
		$kit       = ASI_Appearance::kit_colors();
		$avatar    = ASI_Appearance::avatar_url();
		$languages = $this->plugin->languages->all();
		$sources   = array( 'custom' => 'propio', 'kit' => 'Kit de Elementor', 'default' => 'por defecto' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="asi-appearance">
			<?php $this->hidden_fields( 'appearance', 'apariencia' ); ?>
			<div class="asi-split">
				<div>
					<?php $this->card_open( 'Colores', 'dashicons-art', 'purple' ); ?>
						<p class="asi-muted">
							<?php echo $kit ? 'Vacío = se usa el color del Kit de Elementor del sitio. Elige uno propio si el Kit no está bien configurado o quieres colores especiales para el chat.' : 'No se encontró un Kit de Elementor: los colores vacíos usan valores neutros.'; ?>
						</p>
						<div class="asi-colors">
							<?php foreach ( self::COLOR_LABELS as $key => $label ) : ?>
								<?php $this->color_row( $key, $label, $settings, $palette, $kit ); ?>
							<?php endforeach; ?>
						</div>
					<?php $this->card_close(); ?>

					<?php $this->card_open( 'Botones', 'dashicons-button', 'blue' ); ?>
						<p class="asi-muted">Vacío = combinan solos con tus colores (enviar usa el principal y al pasar el mouse el acento). Pasa el mouse por los botones de la vista previa para ver el hover.</p>
						<div class="asi-colors">
							<?php foreach ( self::BUTTON_LABELS as $key => $label ) : ?>
								<?php $this->color_row( $key, $label, $settings, $palette, $kit ); ?>
							<?php endforeach; ?>
						</div>
					<?php $this->card_close(); ?>

					<?php $this->card_open( 'Esquinas', 'dashicons-image-crop', 'teal' ); ?>
						<div class="asi-options asi-options--inline">
							<?php foreach ( self::RADIUS_LABELS as $key => $label ) : ?>
								<label class="asi-option asi-option--compact">
									<input type="radio" name="radius" value="<?php echo esc_attr( $key ); ?>" data-radius="<?php echo (int) ASI_Settings::RADII[ $key ]; ?>" <?php checked( $settings['radius'], $key ); ?>>
									<span class="asi-radius-sample" style="border-radius:<?php echo (int) min( 11, ASI_Settings::RADII[ $key ] ); ?>px"></span>
									<span class="asi-option__text"><strong><?php echo esc_html( $label ); ?></strong></span>
								</label>
							<?php endforeach; ?>
						</div>
					<?php $this->card_close(); ?>

					<?php $this->card_open( 'Asistente', 'dashicons-admin-users', 'blue' ); ?>
						<p class="asi-muted">Opcionales. Sin nombre ni imagen, el chat se muestra sin identidad.</p>
						<div class="asi-assistant">
							<div class="asi-assistant__avatar">
								<img id="asi-avatar-preview" src="<?php echo esc_url( $avatar ); ?>" alt="" style="<?php echo $avatar ? '' : 'display:none;'; ?>">
								<span class="dashicons dashicons-format-chat" id="asi-avatar-empty" aria-hidden="true" style="<?php echo $avatar ? 'display:none;' : ''; ?>"></span>
							</div>
							<div class="asi-assistant__fields">
								<label for="asi-assistant-name"><strong>Nombre</strong></label>
								<input type="text" name="assistant_name" id="asi-assistant-name" class="regular-text" value="<?php echo esc_attr( $settings['assistant_name'] ); ?>" placeholder="Ej.: Asistente virtual">
								<input type="hidden" name="assistant_avatar" id="asi-avatar" value="<?php echo (int) $settings['assistant_avatar']; ?>">
								<p>
									<button type="button" class="button" id="asi-avatar-pick"><span class="dashicons dashicons-format-image" aria-hidden="true"></span> Elegir imagen</button>
									<button type="button" class="button-link asi-link-danger" id="asi-avatar-remove" <?php echo $avatar ? '' : 'style="display:none"'; ?>>Quitar</button>
								</p>
							</div>
						</div>
						<h3 class="asi-subtitle">Mensaje de bienvenida <span class="asi-optional">opcional</span></h3>
						<p class="asi-muted">Si lo dejas vacío, el asistente saluda con frases que varían según la hora.</p>
						<?php foreach ( $languages as $slug => $language ) : ?>
							<p><label><span class="asi-tag asi-tag--code"><?php echo esc_html( $slug ); ?></span> <?php echo esc_html( $language['country_name'] ); ?><br>
								<textarea name="welcome[<?php echo esc_attr( $slug ); ?>]" rows="2" class="large-text"><?php echo esc_textarea( isset( $settings['welcome'][ $slug ] ) ? $settings['welcome'][ $slug ] : '' ); ?></textarea></label></p>
						<?php endforeach; ?>
					<?php $this->card_close(); ?>

					<?php $this->card_open( 'Forma de hablar', 'dashicons-format-quote', 'teal' ); ?>
						<p class="asi-muted">Cómo le habla el asistente a tus visitantes en español. En inglés no cambia.</p>
						<div class="asi-options">
							<?php
							$tones = array(
								'tu'    => array( 'Tú (cercano)', '“¿Qué estás buscando hoy? Te ayudo a encontrarlo.”', 'dashicons-smiley' ),
								'usted' => array( 'Usted (formal)', '“¿Qué está buscando hoy? Le ayudo a encontrarlo.”', 'dashicons-businessperson' ),
							);
							foreach ( $tones as $value => $label ) :
								?>
								<label class="asi-option">
									<input type="radio" name="tone" value="<?php echo esc_attr( $value ); ?>" <?php checked( $settings['tone'], $value ); ?>>
									<span class="dashicons <?php echo esc_attr( $label[2] ); ?>" aria-hidden="true"></span>
									<span class="asi-option__text"><strong><?php echo esc_html( $label[0] ); ?></strong><span><?php echo esc_html( $label[1] ); ?></span></span>
								</label>
							<?php endforeach; ?>
						</div>
						<p><label><input type="checkbox" name="emojis" value="1" <?php checked( ! empty( $settings['emojis'] ) ); ?>> <strong>Usar algunos emojis</strong> <span class="asi-muted">— pocos y en su lugar: 👋 al saludar, 📍 en ubicaciones, 🕒 en horarios, 📞 en contacto.</span></label></p>
					<?php $this->card_close(); ?>
					<p class="asi-actions"><?php submit_button( 'Guardar apariencia', 'primary', 'submit', false ); ?></p>
				</div>

				<div class="asi-sticky">
					<?php $this->card_open( 'Vista previa', 'dashicons-visibility', 'green' ); ?>
						<p class="asi-muted">Así se verá el chat en el sitio. Cambia en vivo mientras editas.</p>
						<?php $this->preview( $settings, $avatar ); ?>
					<?php $this->card_close(); ?>
				</div>
			</div>
		</form>
		<?php
	}

	/**
	 * Una fila de color: selector + de dónde sale el color actual.
	 */
	private function color_row( $key, array $label, array $settings, array $palette, array $kit ) {
		$all_labels = self::COLOR_LABELS + self::BUTTON_LABELS;
		$source     = $palette[ $key ]['source'];
		$texts      = array( 'custom' => 'propio', 'kit' => 'Kit de Elementor', 'default' => 'por defecto' );
		$text       = isset( $texts[ $source ] ) ? $texts[ $source ] : 'igual que ' . strtolower( $all_labels[ $palette[ $key ]['from'] ][0] );
		$derive     = isset( ASI_Appearance::DERIVED[ $key ] ) ? ASI_Appearance::DERIVED[ $key ] : '';
		if ( 'custom' === $source && ! $derive ) {
			$fallback = isset( ASI_Appearance::KIT_MAP[ $key ], $kit[ ASI_Appearance::KIT_MAP[ $key ] ] ) ? $kit[ ASI_Appearance::KIT_MAP[ $key ] ] : ASI_Appearance::FALLBACK[ $key ];
		} else {
			$fallback = $palette[ $key ]['value'];
		}
		?>
		<div class="asi-color-row">
			<div class="asi-color-row__label">
				<strong><?php echo esc_html( $label[0] ); ?></strong>
				<span class="asi-muted"><?php echo esc_html( $label[1] ); ?></span>
			</div>
			<div class="asi-color-row__field">
				<input type="text" class="asi-color" name="colors[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $settings['colors'][ $key ] ); ?>"
					data-var="--asi-<?php echo esc_attr( $key ); ?>" data-fallback="<?php echo esc_attr( $fallback ); ?>" data-derive="<?php echo esc_attr( $derive ); ?>">
				<span class="asi-tag asi-tag--<?php echo 'custom' === $source ? 'ok' : 'soft'; ?>"><i style="background:<?php echo esc_attr( $palette[ $key ]['value'] ); ?>"></i><?php echo esc_html( $text ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Vista previa estática del chat con los estilos reales.
	 */
	private function preview( array $settings, $avatar ) {
		$name = $settings['assistant_name'];
		?>
		<div class="asi-chat<?php echo ( ! $name && ! $avatar ) ? ' asi-chat--anonymous' : ''; ?>" id="asi-preview" style="<?php echo esc_attr( ASI_Appearance::css_variables() ); ?>;height:560px;min-height:0">
			<div class="asi-chat__header">
				<div class="asi-chat__identity">
					<img class="asi-chat__avatar" id="asi-preview-avatar" src="<?php echo esc_url( $avatar ); ?>" alt="" style="<?php echo $avatar ? '' : 'display:none'; ?>">
					<span class="asi-chat__name" id="asi-preview-name"><?php echo esc_html( $name ); ?></span>
				</div>
				<button type="button" class="asi-chat__reset"><svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path fill="currentColor" d="M19 11h-6V5h-2v6H5v2h6v6h2v-6h6z"/></svg>Nueva conversación</button>
			</div>
			<div class="asi-chat__log">
				<div class="asi-msg asi-msg--bot"><div class="asi-msg__bubble">¡Hola! Cuéntame qué necesitas y te ayudo a encontrarlo.</div></div>
				<div class="asi-msg asi-msg--user"><div class="asi-msg__bubble">busco protector solar</div></div>
				<div class="asi-msg asi-msg--bot"><div class="asi-msg__bubble">¡Claro! Encontré 2 opciones de protector solar.</div></div>
				<div class="asi-cards">
					<a class="asi-card" href="#" onclick="return false"><div class="asi-card__media asi-card__media--empty"><span class="asi-card__flag">Oferta</span></div><div class="asi-card__body"><span class="asi-card__meta">Protector solar · Marca</span><strong class="asi-card__title">Producto de ejemplo</strong><span class="asi-card__price">Q120.00</span></div></a>
					<a class="asi-card" href="#" onclick="return false"><div class="asi-card__media asi-card__media--empty"></div><div class="asi-card__body"><span class="asi-card__meta">Protector solar</span><strong class="asi-card__title">Otro producto</strong><span class="asi-card__price">Q80.00</span></div></a>
				</div>
				<div class="asi-chips"><button type="button" class="asi-chip asi-chip--primary">Ver más opciones</button><button type="button" class="asi-chip">Cuidado de la piel</button></div>
			</div>
			<div class="asi-chat__form"><input class="asi-chat__input" placeholder="Escribe lo que buscas…" disabled><button type="button" class="asi-chat__send"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M3.4 20.4 21 12 3.4 3.6 3.4 10l12.6 2-12.6 2z"/></svg></button></div>
		</div>
		<?php
	}

	// ---------- Conexión ----------

	/**
	 * Tarjeta "Cotizar y comparar desde el chat" (pestaña Conexión).
	 */
	private function actions_card() {
		$settings   = ASI_Settings::all();
		$mode       = ASI_Actions::mode();
		$connectors = ASI_Extensions::active_connectors();
		$this->card_open( 'Cotizar y comparar desde el chat', 'dashicons-media-document', 'green', 'asi-acciones' );
		?>
			<p class="asi-muted">Cuando el cliente dice “me interesa” o “mándame la comparación”, el chat le pide nombre y correo, genera el PDF con la función del propio sitio, se lo muestra en la conversación y se lo envía por correo. El interesado queda guardado donde el sitio guarda los de su formulario.</p>
			<?php if ( ! $connectors ) : ?>
				<p><span class="asi-tag asi-tag--soft">Este sitio no tiene un conector: el chat recomienda y manda al formulario normal del sitio.</span></p>
			<?php else : ?>
				<ul>
				<?php foreach ( $connectors as $connector ) : ?>
					<li><span class="asi-tag asi-tag--ok"><?php echo esc_html( $connector->label() ); ?></span> <?php echo esc_html( implode( ', ', array_column( $connector->actions(), 'label' ) ) ); ?></li>
				<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php $this->hidden_fields( 'actions', 'conexion' ); ?>
				<p><strong>Modo</strong></p>
				<p>
					<label><input type="radio" name="actions_mode" value="off" <?php checked( $mode, 'off' ); ?>> Apagado</label><br>
					<label><input type="radio" name="actions_mode" value="test" <?php checked( $mode, 'test' ); ?>> <strong>Prueba</strong>: el PDF le llega al correo que escriban en el chat; el aviso a ventas solo va al correo de prueba</label><br>
					<label><input type="radio" name="actions_mode" value="live" <?php checked( $mode, 'live' ); ?>> En vivo: el aviso va a quienes avisa el formulario del sitio</label>
				</p>
				<p><label><strong>Correo de prueba</strong><br><input type="email" class="regular-text" name="test_email" value="<?php echo esc_attr( $settings['test_email'] ); ?>"></label></p>
				<p><strong>Cuotas</strong> <span class="asi-muted">(solo si el sitio no trae su propia calculadora)</span><br>
					<label>Tasa anual <input type="text" inputmode="decimal" size="5" name="finance_rate" value="<?php echo esc_attr( (string) $settings['finance_rate'] ); ?>"> %</label>
					&nbsp; <label>Enganche mínimo <input type="number" min="0" max="90" name="finance_down_min" value="<?php echo esc_attr( (string) $settings['finance_down_min'] ); ?>"> %</label></p>
				<p class="asi-actions"><?php submit_button( 'Guardar', 'primary', 'submit', false ); ?></p>
			</form>
		<?php
		$this->card_close();
	}

	private function tab_conexion() {
		$settings  = ASI_Settings::all();
		$languages = $this->plugin->languages->all();
		$missing   = 0;
		?>
		<?php $this->actions_card(); ?>
		<?php $this->card_open( 'Buscador del sitio', 'dashicons-search', 'blue' ); ?>
			<p class="asi-muted">Funciona con cualquier formulario de búsqueda de WordPress: el del tema, el widget de búsqueda de Elementor y el de WooCommerce. Solo actúa para quien puede ver el asistente según la visibilidad.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php $this->hidden_fields( 'connection', 'conexion' ); ?>
				<div class="asi-options">
					<?php
					$options = array(
						'none'     => array( 'No conectar', 'El buscador del sitio funciona como siempre. El chat solo se usa desde su página.', 'dashicons-dismiss' ),
						'redirect' => array( 'Envío automático', 'Toda búsqueda del sitio abre el chat del mismo idioma con la pregunta ya hecha.', 'dashicons-controls-forward' ),
						'choice'   => array( 'Que el cliente elija', 'Al buscar aparece una ventana: “Preguntar al asistente” o “Ver resultados de búsqueda”.', 'dashicons-randomize' ),
					);
					foreach ( $options as $mode => $option ) :
						?>
						<label class="asi-option">
							<input type="radio" name="search_mode" value="<?php echo esc_attr( $mode ); ?>" <?php checked( ASI_Settings::search_mode(), $mode ); ?>>
							<span class="dashicons <?php echo esc_attr( $option[2] ); ?>" aria-hidden="true"></span>
							<span class="asi-option__text"><strong><?php echo esc_html( $option[0] ); ?></strong><span><?php echo esc_html( $option[1] ); ?></span></span>
						</label>
					<?php endforeach; ?>
				</div>
				<p class="asi-actions"><?php submit_button( 'Guardar conexión', 'primary', 'submit', false ); ?></p>
			</form>
		<?php $this->card_close(); ?>

		<?php $this->card_open( 'Páginas del chat', 'dashicons-admin-page', 'green' ); ?>
			<p class="asi-muted">Una página por idioma con el shortcode <code>[<?php echo esc_html( ASI_Chat_Page::SHORTCODE ); ?>]</code>, enlazadas como traducciones. Mientras la visibilidad no sea <em>Público</em>, quedan privadas.</p>
			<table class="asi-table">
				<thead><tr><th>Idioma</th><th>Página</th><th>Estado</th></tr></thead>
				<tbody>
				<?php foreach ( $languages as $slug => $language ) : ?>
					<?php
					$page_id = isset( $settings['chat_pages'][ $slug ] ) ? (int) $settings['chat_pages'][ $slug ] : 0;
					$status  = $page_id ? get_post_status( $page_id ) : false;
					$exists  = $status && 'trash' !== $status;
					$missing += $exists ? 0 : 1;
					?>
					<tr>
						<td><span class="asi-tag asi-tag--code"><?php echo esc_html( $slug ); ?></span> <?php echo esc_html( $language['country_name'] ); ?></td>
						<td>
							<?php if ( $exists ) : ?>
								<a href="<?php echo esc_url( get_permalink( $page_id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_the_title( $page_id ) ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></a>
								<span class="asi-muted">·</span> <a href="<?php echo esc_url( get_edit_post_link( $page_id ) ); ?>">Editar</a>
							<?php else : ?>
								<span class="asi-muted">Sin página</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( ! $exists ) : ?>
								<span class="asi-tag asi-tag--warn">Falta</span>
							<?php elseif ( 'publish' === $status ) : ?>
								<span class="asi-tag asi-tag--ok">Publicada</span>
							<?php else : ?>
								<span class="asi-tag asi-tag--soft"><?php echo esc_html( 'private' === $status ? 'Privada' : $status ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $missing ) : ?>
				<p class="asi-note asi-note--warn">
					<?php
					echo esc_html(
						1 === $missing
							? 'Falta 1 página: en ese idioma el buscador del sitio seguirá funcionando como siempre hasta que la crees.'
							: sprintf( 'Faltan %d páginas: en esos idiomas el buscador del sitio seguirá funcionando como siempre hasta que las crees.', $missing )
					);
					?>
				</p>
				<div class="asi-actions"><?php $this->action_button( 'create_pages', 1 === $missing ? 'Crear la página que falta' : sprintf( 'Crear las %d páginas que faltan', $missing ), 'conexion', 'button button-primary', 'dashicons-plus-alt2' ); ?></div>
				<p class="asi-muted">Solo se crean las que faltan; las que ya existen no se tocan. Si alguna vez borras una, vuelve a aparecer este botón.</p>
			<?php else : ?>
				<p class="asi-note asi-note--ok"><strong>Todo listo:</strong> hay una página del chat para cada idioma. No tienes que hacer nada más aquí. Si borras alguna, aquí aparecerá un botón para volver a crearla.</p>
			<?php endif; ?>
		<?php $this->card_close(); ?>
		<?php
	}

	// ---------- Contenido ----------

	private function tab_contenido() {
		$schema = ASI_Schema_Discovery::get_schema();
		if ( empty( $schema['post_types'] ) ) {
			$this->card_open( 'Contenido', 'dashicons-database', 'blue' );
			echo '<p>Todavía no se descubrió la estructura. Se hace sola en segundo plano tras activar; también puedes pulsar “Volver a descubrir el sitio” en Inicio.</p>';
			$this->card_close();
			return;
		}
		$role_tones = array( 'catalog' => 'blue', 'info' => 'teal', 'article' => 'purple' );
		$role_icons = array( 'catalog' => 'dashicons-products', 'info' => 'dashicons-info-outline', 'article' => 'dashicons-media-text' );
		?>
		<div class="asi-hero asi-hero--compact">
			<span class="asi-hero__icon dashicons dashicons-search" aria-hidden="true"></span>
			<p class="asi-hero__lead">Aquí ves <strong>qué contenido del sitio usa el asistente y cómo lo muestra</strong>. Todo se detectó solo al activar el plugin: <strong>no tienes que configurar nada</strong>. Solo cambia algo si el asistente responde con algo que no debería. Al guardar, el índice se rehace en segundo plano.</p>
		</div>

		<?php $this->card_open( 'Qué significa cada opción', 'dashicons-editor-help', 'amber' ); ?>
			<div class="asi-legend">
				<div class="asi-legend__item"><span class="asi-icon asi-icon--blue dashicons dashicons-products" aria-hidden="true"></span><div><strong>Catálogo (tarjetas)</strong><p>Productos, servicios o lo que se vende. Aparecen como tarjetas con imagen, precio y código cuando alguien los busca.</p></div></div>
				<div class="asi-legend__item"><span class="asi-icon asi-icon--teal dashicons dashicons-info-outline" aria-hidden="true"></span><div><strong>Información del sitio</strong><p>Páginas como contacto, políticas o preguntas frecuentes. El asistente responde con un enlace cuando preguntan por ese tema.</p></div></div>
				<div class="asi-legend__item"><span class="asi-icon asi-icon--green dashicons dashicons-location" aria-hidden="true"></span><div><strong>Ubicaciones</strong><p>Tiendas o sucursales detectadas (por ejemplo, las de un plugin de mapas). Nunca salen como producto: se listan cuando preguntan “¿dónde están?” o por horarios.</p></div></div>
				<div class="asi-legend__item"><span class="asi-icon asi-icon--purple dashicons dashicons-media-text" aria-hidden="true"></span><div><strong>Artículos / noticias</strong><p>Entradas del blog. Se ofrecen como enlace cuando el tema coincide con la pregunta.</p></div></div>
				<div class="asi-legend__item"><span class="asi-icon asi-icon--amber dashicons dashicons-yes-alt" aria-hidden="true"></span><div><strong>Interruptor</strong><p>Apagado = el asistente ignora ese contenido por completo.</p></div></div>
				<div class="asi-legend__item"><span class="asi-icon asi-icon--blue dashicons dashicons-list-view" aria-hidden="true"></span><div><strong>Datos que usa</strong><p>Los campos de cada contenido (código, marca, precio, descripción…). Los datos internos de otros plugins se ignoran solos.</p></div></div>
			</div>
		<?php $this->card_close(); ?>
		<?php $this->money_card( $schema ); ?>
		<?php $this->flags_card(); ?>
		<p class="asi-detected">
			<?php foreach ( array( 'acf' => 'ACF', 'woocommerce' => 'WooCommerce', 'elementor' => 'Elementor' ) as $key => $label ) : ?>
				<span class="asi-tag asi-tag--<?php echo $schema[ $key ] ? 'ok' : 'soft'; ?>"><?php echo esc_html( $label . ( $schema[ $key ] ? ' detectado' : ' no' ) ); ?></span>
			<?php endforeach; ?>
			<span class="asi-muted">Descubierto: <?php echo esc_html( wp_date( 'j M Y, H:i', $schema['discovered_at'] ) ); ?></span>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php $this->hidden_fields( 'schema', 'contenido' ); ?>
			<?php foreach ( $schema['post_types'] as $post_type => $type ) : ?>
				<?php
				$fields = array_filter(
					$type['fields'],
					function ( $field ) {
						return 'ignore' !== $field['role'];
					}
				);
				$tone = isset( $role_tones[ $type['role'] ] ) ? $role_tones[ $type['role'] ] : 'blue';
				?>
				<section class="asi-panel asi-panel--<?php echo esc_attr( $tone ); ?> asi-panel--accent<?php echo $type['enabled'] ? '' : ' is-disabled'; ?>">
					<div class="asi-type">
						<label class="asi-switch">
							<input type="checkbox" name="asi_types[<?php echo esc_attr( $post_type ); ?>][enabled]" value="1" <?php checked( $type['enabled'] ); ?>>
							<span class="asi-switch__track" aria-hidden="true"></span>
							<span class="screen-reader-text">Incluir en el buscador</span>
						</label>
						<span class="asi-icon asi-icon--<?php echo esc_attr( $tone ); ?> dashicons <?php echo esc_attr( isset( $role_icons[ $type['role'] ] ) ? $role_icons[ $type['role'] ] : 'dashicons-admin-post' ); ?>" aria-hidden="true"></span>
						<div class="asi-type__name">
							<strong><?php echo esc_html( $type['label'] ); ?></strong>
							<span class="asi-muted"><code><?php echo esc_html( $post_type ); ?></code> · <?php echo (int) $type['count']; ?> publicados<?php echo ! empty( $type['elementor'] ) ? ' · Elementor' : ''; ?></span>
						</div>
						<label class="asi-type__role">Se muestra como
							<select name="asi_types[<?php echo esc_attr( $post_type ); ?>][role]">
								<?php foreach ( self::POST_TYPE_ROLES as $role => $label ) : ?>
									<option value="<?php echo esc_attr( $role ); ?>" <?php selected( $type['role'], $role ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					</div>
					<?php if ( ! empty( $type['auto_disabled'] ) ) : ?>
						<p class="asi-type__note"><span class="dashicons dashicons-info" aria-hidden="true"></span> Apagado automáticamente: es configuración interna de otro plugin (no tiene contenido para responder). Enciéndelo solo si de verdad quieres que el asistente lo use.</p>
					<?php elseif ( isset( $type['kind'] ) && 'location' === $type['kind'] ) : ?>
						<p class="asi-type__note"><span class="dashicons dashicons-location" aria-hidden="true"></span> Detectado como <strong>ubicaciones</strong>: no aparece como producto. Se lista cuando preguntan dónde están, por sucursales o por horarios.</p>
					<?php elseif ( 'catalog' === $type['role'] ) : ?>
						<p class="asi-type__note"><span class="dashicons dashicons-products" aria-hidden="true"></span> Aparece como <strong>tarjetas</strong> cuando alguien busca algo que coincide.</p>
					<?php elseif ( 'info' === $type['role'] ) : ?>
						<p class="asi-type__note"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span> Se ofrece como <strong>enlace</strong> cuando preguntan por ese tema (contacto, políticas, ayuda…).</p>
					<?php else : ?>
						<p class="asi-type__note"><span class="dashicons dashicons-media-text" aria-hidden="true"></span> Se ofrece como <strong>enlace de artículo</strong> cuando el tema coincide.</p>
					<?php endif; ?>
					<?php if ( $type['taxonomies'] ) : ?>
						<p class="asi-type__taxonomies">
							<?php foreach ( $type['taxonomies'] as $taxonomy => $info ) : ?>
								<span class="asi-tag asi-tag--soft" title="<?php echo esc_attr( $taxonomy ); ?>"><?php echo esc_html( $info['label'] ); ?> <em><?php echo esc_html( $info['role'] ); ?></em></span>
							<?php endforeach; ?>
						</p>
					<?php endif; ?>
					<?php if ( $type['fields'] ) : ?>
						<details class="asi-details">
							<summary>
								<?php if ( $fields ) : ?>
									Datos que usa para buscar: <strong><?php echo count( $fields ); ?></strong>
								<?php else : ?>
									<strong>No usa datos extra</strong>: busca por el nombre, el texto y las categorías
								<?php endif; ?>
								<?php $ignored = count( $type['fields'] ) - count( $fields ); ?>
								<?php if ( $ignored ) : ?>
									<span class="asi-muted">· <?php echo (int) $ignored; ?> datos internos ignorados</span>
								<?php endif; ?>
							</summary>
							<p class="asi-muted">“Ignorar” = no se usa para buscar. Los datos internos de otros plugins (mapas, SEO, diseño) se ignoran solos; cambia un rol solo si el asistente no encuentra algo por ese dato.</p>
							<table class="asi-table">
								<thead><tr><th>Campo</th><th>Origen</th><th>Rol</th></tr></thead>
								<tbody>
								<?php foreach ( $type['fields'] as $name => $field ) : ?>
									<tr class="<?php echo 'ignore' === $field['role'] ? 'is-muted' : ''; ?>">
										<td><?php echo esc_html( $field['label'] ); ?> <code><?php echo esc_html( $name ); ?></code></td>
										<td><span class="asi-tag asi-tag--soft"><?php echo esc_html( $field['source'] ); ?></span></td>
										<td>
											<?php if ( 'container' === $field['role'] ) : ?>
												Grupo con <?php echo count( $field['children'] ); ?> subcampos
											<?php else : ?>
												<select name="asi_types[<?php echo esc_attr( $post_type ); ?>][fields][<?php echo esc_attr( $name ); ?>]">
													<?php foreach ( self::FIELD_ROLES as $role => $label ) : ?>
														<option value="<?php echo esc_attr( $role ); ?>" <?php selected( $field['role'], $role ); ?>><?php echo esc_html( $label ); ?></option>
													<?php endforeach; ?>
												</select>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</details>
					<?php endif; ?>
				</section>
			<?php endforeach; ?>
			<p class="asi-actions"><?php submit_button( 'Guardar correcciones y reindexar', 'primary', 'submit', false ); ?></p>
		</form>
		<?php
	}

	// ---------- Sinónimos ----------

	/**
	 * Sinónimos propios (dentro de Aprendizaje): grupos de palabras que significan lo mismo en el negocio.
	 */
	private function synonyms_card() {
		$settings  = ASI_Settings::all();
		$languages = array();
		foreach ( $this->plugin->languages->all() as $language ) {
			$languages[ $language['language'] ] = true;
		}
		$this->card_open( 'Sinónimos propios', 'dashicons-editor-spellcheck', 'purple', 'asi-sinonimos' );
		?>
			<p class="asi-muted">El asistente ya trae sinónimos generales. Aquí van los de tu negocio o tu país: un grupo por línea, separado por comas. Los que guardas desde <strong>Preguntas por revisar</strong> también aparecen aquí, y los puedes editar o borrar.</p>
			<p class="asi-example">playera, camiseta, remera<br>refacción, repuesto, pieza</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php $this->hidden_fields( 'synonyms', 'aprendizaje' ); ?>
				<?php foreach ( array_keys( $languages ) as $language ) : ?>
					<p><label><span class="asi-tag asi-tag--code"><?php echo esc_html( strtoupper( $language ) ); ?></span><br>
						<textarea name="synonyms[<?php echo esc_attr( $language ); ?>]" rows="6" class="large-text code"><?php echo esc_textarea( isset( $settings['synonyms_text'][ $language ] ) ? $settings['synonyms_text'][ $language ] : '' ); ?></textarea></label></p>
				<?php endforeach; ?>
				<p class="asi-actions"><?php submit_button( 'Guardar sinónimos', 'primary', 'submit', false ); ?></p>
			</form>
		<?php
		$this->card_close();
	}

	// ---------- Aprendizaje ----------

	private function tab_aprendizaje() {
		$questions = ASI_Learning::questions();
		uasort(
			$questions,
			function ( $a, $b ) {
				return $b['count'] - $a['count'] ?: $b['at'] - $a['at'];
			}
		);
		$learned   = ASI_Learning::learned();
		$clicks    = ASI_Learning::top_clicks( 15 );
		$titles    = $this->titles_for( array_merge( wp_list_pluck( $clicks, 'post_id' ), $this->learned_ids( $learned ) ) );
		?>
		<div class="asi-hero">
			<span class="asi-hero__icon dashicons dashicons-welcome-learn-more" aria-hidden="true"></span>
			<p class="asi-hero__lead">
				<strong>El asistente aprende con el uso, sin inteligencia artificial externa.</strong>
				Guarda las preguntas que no supo responder bien (sin datos de quien pregunta), para que le enseñes la respuesta una vez y la sepa siempre.
				Además, observa qué resultados abre la gente después de cada búsqueda y los sube un poco en esa búsqueda.
			</p>
		</div>

		<p class="asi-muted">¿Cómo enseñarle paso a paso? Está en la <a href="<?php echo esc_url( $this->tab_url( 'guia' ) . '#asi-ensenar' ); ?>">Guía</a>.</p>

		<?php $this->card_open( 'Preguntas por revisar', 'dashicons-format-status', 'amber' ); ?>
			<p class="asi-muted">
				<span class="asi-tag asi-tag--warn">Sin respuesta</span> no encontró nada.
				<span class="asi-tag asi-tag--soft">Aproximada</span> respondió con lo más parecido, pero no exacto.
				Para cada una puedes <strong>enseñarle qué contenido responde</strong>, decirle que <strong>significa lo mismo que otra palabra</strong>, o quitarla de la lista si no te interesa (por ejemplo, algo que tu sitio no vende).
				<strong>Quitarla no cambia nada en el asistente</strong>: solo limpia la lista, y si alguien la vuelve a preguntar, aparece otra vez.
			</p>
			<?php if ( ! $questions ) : ?>
				<div class="asi-empty"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>No hay preguntas pendientes. Aparecerán aquí cuando alguien pregunte algo que el asistente no sepa responder bien.</div>
			<?php else : ?>
				<table class="asi-table asi-learning">
					<thead><tr><th>Pregunta</th><th>Idioma</th><th>Veces</th><th>Tipo</th><th>Qué hacer</th></tr></thead>
					<tbody>
					<?php foreach ( array_slice( $questions, 0, 60, true ) as $id => $row ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $row['q'] ); ?></strong><br><span class="asi-muted"><?php echo esc_html( human_time_diff( $row['at'] ) ); ?></span></td>
							<td><span class="asi-tag asi-tag--code"><?php echo esc_html( $row['lang'] ); ?></span></td>
							<td><strong><?php echo (int) $row['count']; ?></strong></td>
							<td><span class="asi-tag asi-tag--<?php echo 'none' === $row['kind'] ? 'warn' : 'soft'; ?>"><?php echo 'none' === $row['kind'] ? 'Sin respuesta' : 'Aproximada'; ?></span></td>
							<td class="asi-learning__actions">
								<details class="asi-teach">
									<summary class="button button-small"><span class="dashicons dashicons-welcome-learn-more" aria-hidden="true"></span> Enseñar</summary>
									<div class="asi-teach__box">
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asi-teach__form">
											<?php $this->hidden_fields( 'teach', 'aprendizaje' ); ?>
											<input type="hidden" name="lang" value="<?php echo esc_attr( $row['lang'] ); ?>">
											<input type="hidden" name="key" value="<?php echo esc_attr( $row['key'] ); ?>">
											<input type="hidden" name="q" value="<?php echo esc_attr( $row['q'] ); ?>">
											<input type="hidden" name="ids" value="" class="asi-teach__ids">
											<label><strong>¿Qué contenido responde esta pregunta?</strong></label>
											<input type="search" class="regular-text asi-teach__search" data-lang="<?php echo esc_attr( $row['lang'] ); ?>" placeholder="Escribe el nombre de un producto o página…" autocomplete="off">
											<ul class="asi-teach__results"></ul>
											<ul class="asi-teach__chosen"></ul>
											<button class="button button-primary asi-teach__save" disabled>Guardar respuesta</button>
										</form>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asi-teach__form">
											<?php $this->hidden_fields( 'teach_synonym', 'aprendizaje' ); ?>
											<input type="hidden" name="lang" value="<?php echo esc_attr( $row['lang'] ); ?>">
											<input type="hidden" name="q" value="<?php echo esc_attr( $row['q'] ); ?>">
											<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
											<label><strong>…o significa lo mismo que esta palabra del sitio:</strong></label>
											<span class="asi-teach__row"><input type="text" name="word" class="regular-text" placeholder="Ej.: montacargas" required> <button class="button">Guardar sinónimo</button></span>
										</form>
									</div>
								</details>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asi-inline-form">
									<?php $this->hidden_fields( 'dismiss', 'aprendizaje' ); ?>
									<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
									<button class="button-link asi-link-danger" title="Solo la quita de esta lista. No le enseña ni le prohíbe nada al asistente; si alguien la vuelve a preguntar, aparece de nuevo.">Quitar de la lista</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<div class="asi-actions"><?php $this->action_button( 'clear_questions', 'Vaciar lista', 'aprendizaje', 'button', 'dashicons-trash' ); ?></div>
			<?php endif; ?>
		<?php $this->card_close(); ?>

		<?php $this->synonyms_card(); ?>

		<div class="asi-grid">
			<?php $this->card_open( 'Respuestas enseñadas', 'dashicons-yes-alt', 'green' ); ?>
				<p class="asi-muted">Cuando alguien pregunte esto (con cualquier orden de palabras, plurales o sin acentos), el asistente responde con lo que elegiste.</p>
				<?php if ( ! $learned ) : ?>
					<div class="asi-empty"><span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>Todavía no le has enseñado nada.</div>
				<?php else : ?>
					<table class="asi-table">
						<thead><tr><th>Pregunta</th><th>Responde con</th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $learned as $lang => $entries ) : ?>
							<?php foreach ( $entries as $key => $entry ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $entry['q'] ); ?></strong> <span class="asi-tag asi-tag--code"><?php echo esc_html( $lang ); ?></span></td>
									<td><?php echo esc_html( implode( ', ', array_map( function ( $id ) use ( $titles ) { return isset( $titles[ $id ] ) ? $titles[ $id ] : '#' . $id; }, (array) $entry['ids'] ) ) ); ?></td>
									<td>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asi-inline-form">
											<?php $this->hidden_fields( 'forget', 'aprendizaje' ); ?>
											<input type="hidden" name="lang" value="<?php echo esc_attr( $lang ); ?>">
											<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
											<button class="button-link asi-link-danger">Olvidar</button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php $this->card_close(); ?>

			<?php $this->card_open( 'Lo que más abren', 'dashicons-chart-bar', 'blue' ); ?>
				<p class="asi-muted">Qué resultado abre la gente después de cada búsqueda. Desde 3 visitas distintas, ese resultado sube un poco cuando alguien vuelve a buscar lo mismo. Cada visitante cuenta una vez al día.</p>
				<?php if ( ! $clicks ) : ?>
					<div class="asi-empty"><span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>Todavía no hay clics registrados.</div>
				<?php else : ?>
					<table class="asi-table">
						<thead><tr><th>Búsqueda</th><th>Resultado abierto</th><th>Visitas</th></tr></thead>
						<tbody>
						<?php foreach ( $clicks as $click ) : ?>
							<tr>
								<td><?php echo esc_html( $click['qkey'] ); ?> <span class="asi-tag asi-tag--code"><?php echo esc_html( $click['lang'] ); ?></span></td>
								<td><?php echo esc_html( isset( $titles[ (int) $click['post_id'] ] ) ? $titles[ (int) $click['post_id'] ] : '#' . (int) $click['post_id'] ); ?></td>
								<td><strong><?php echo (int) $click['clicks']; ?></strong><?php echo (int) $click['clicks'] >= ASI_Search::MIN_CLICKS ? ' <span class="asi-tag asi-tag--ok">sube</span>' : ''; ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php $this->card_close(); ?>
		</div>
		<?php
	}

	/**
	 * IDs de las respuestas enseñadas.
	 */
	private function learned_ids( array $learned ) {
		$ids = array();
		foreach ( $learned as $entries ) {
			foreach ( $entries as $entry ) {
				$ids = array_merge( $ids, (array) $entry['ids'] );
			}
		}
		return $ids;
	}

	/**
	 * Títulos de varios posts (una consulta).
	 */
	private function titles_for( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$titles = array();
		foreach ( get_posts( array( 'post__in' => $ids, 'post_type' => 'any', 'post_status' => 'any', 'numberposts' => count( $ids ), 'suppress_filters' => true ) ) as $post ) {
			$titles[ $post->ID ] = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
		}
		return $titles;
	}

	// ---------- Guía ----------

	private function tab_guia() {
		?>
		<div class="asi-hero">
			<span class="asi-hero__icon dashicons dashicons-book-alt" aria-hidden="true"></span>
			<p class="asi-hero__lead">
				<strong>Manual de uso.</strong> Cómo ponerlo a funcionar, qué le puede preguntar la gente, cómo enseñarle lo que no sepa y algunos trucos.
				No hace falta leerlo entero: el asistente ya funciona desde que se activa.
			</p>
		</div>

		<?php $this->card_open( 'Cómo empezar', 'dashicons-flag', 'green' ); ?>
			<p class="asi-note asi-note--ok"><strong>No hay que agregar clases CSS, códigos ni widgets.</strong> El plugin ya descubrió e indexó el sitio. En cuanto la visibilidad está activa, el buscador del sitio queda conectado solo, con la opción que elijas en <a href="<?php echo esc_url( $this->tab_url( 'conexion' ) ); ?>">Conexión</a>: funciona con el buscador del tema, el widget de búsqueda de Elementor y el de WooCommerce.</p>
			<ol class="asi-steps">
				<li><h3>Elige la visibilidad</h3><p>En <a href="<?php echo esc_url( $this->tab_url( 'inicio' ) . '#asi-visibilidad' ); ?>">Inicio → Visibilidad</a>: “Solo administradores” para probar sin que nadie más lo vea, o “Público” para todos.</p></li>
				<li><h3>Elige cómo se conecta el buscador</h3><p>En <a href="<?php echo esc_url( $this->tab_url( 'conexion' ) ); ?>">Conexión</a>: envío automático al chat, que el cliente elija, o no conectar. Las páginas del chat (una por idioma) ya se crearon solas al activar el plugin.</p></li>
				<li><h3>Dale tu estilo</h3><p>Colores, esquinas y asistente en <a href="<?php echo esc_url( $this->tab_url( 'apariencia' ) ); ?>">Apariencia</a>. Si no tocas nada, usa los colores de Elementor.</p></li>
				<li><h3>Pruébalo</h3><p>Conversa en <a href="<?php echo esc_url( $this->tab_url( 'probar' ) ); ?>">Probar</a> o busca algo en el buscador de tu sitio. </p></li>
				<li class="asi-steps__wide"><h3>Enséñale lo que no sepa</h3><p>Revisa <a href="<?php echo esc_url( $this->tab_url( 'aprendizaje' ) ); ?>">Aprendizaje</a> de vez en cuando (por ejemplo, una vez por semana). Ahí aparecen las preguntas que no supo responder bien; en cada una eliges qué producto o página la responde, o qué palabra del sitio significa lo mismo, y desde ese momento la responde siempre. Lo demás lo aprende solo con los clics de los visitantes.</p></li>
			</ol>
		<?php $this->card_close(); ?>


		<?php $this->card_open( 'Cómo funciona', 'dashicons-info-outline', 'amber' ); ?>
			<div class="asi-features">
				<?php
				$features = array(
					array( 'dashicons-search', 'blue', 'Descubre solo el sitio', 'Tipos de contenido, categorías, marcas, campos ACF, WooCommerce, Elementor, idiomas y países. Lo puedes corregir en Contenido.' ),
					array( 'dashicons-database', 'teal', 'Índice propio y liviano', 'Se arma en segundo plano, por lotes, para no cargar el servidor, y se actualiza solo al editar o publicar.' ),
					array( 'dashicons-format-chat', 'purple', 'Entiende y recuerda', 'Corrige errores, reconoce códigos y sinónimos, y recuerda la conversación: “más opciones”, “el segundo”, “solo de tal marca”.' ),
					array( 'dashicons-editor-quote', 'teal', 'Responde con datos', 'Cita la frase exacta de tus páginas (horario, teléfono, quiénes somos) y contesta datos de un producto: precio, colores, peso, lo que diga su ficha. También compara dos productos y encuentra el más económico.' ),
					array( 'dashicons-welcome-learn-more', 'purple', 'Aprende con el uso', 'Guarda las preguntas que no supo responder bien para que le enseñes la respuesta en Aprendizaje, y sube un poco los resultados que más abren los visitantes después de cada búsqueda.' ),
					array( 'dashicons-admin-site-alt3', 'green', 'Por idioma y país', 'Responde en el idioma de la página y solo con productos e información de su país, sin mezclar.' ),
					array( 'dashicons-shield', 'amber', 'Sin servicios externos', 'Todo corre en tu servidor: sin claves, sin costo por uso y sin enviar datos a terceros.' ),
				);
				foreach ( $features as $feature ) :
					?>
					<div class="asi-feature">
						<span class="asi-icon asi-icon--<?php echo esc_attr( $feature[1] ); ?> dashicons <?php echo esc_attr( $feature[0] ); ?>" aria-hidden="true"></span>
						<div><h3><?php echo esc_html( $feature[2] ); ?></h3><p><?php echo esc_html( $feature[3] ); ?></p></div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php $this->card_close(); ?>

		<?php $this->card_open( 'Qué le puede preguntar la gente', 'dashicons-format-chat', 'blue' ); ?>
			<p class="asi-muted">Con sus propias palabras, con errores de ortografía o sin acentos. Estos son ejemplos de lo que entiende:</p>
			<div class="asi-features">
				<?php
				$examples = array(
					array( 'dashicons-search', 'blue', 'Buscar', '“busco montacargas eléctricos”, “shampoo para caspa”, un código o SKU.' ),
					array( 'dashicons-filter', 'teal', 'Afinar y seguir', '“solo John Deere”, “de menos de 500”, “que no sea eléctrico”, “ver más opciones”.' ),
					array( 'dashicons-list-view', 'purple', 'Referirse a lo que vio', '“el segundo”, “cuéntame más de ese”, “¿cuánto cuesta el primero?”.' ),
					array( 'dashicons-tag', 'green', 'Datos de un producto', '“¿en qué colores viene?”, “¿cuánto pesa?”, “¿trae garantía?”. Responde con su ficha o su descripción.' ),
					array( 'dashicons-columns', 'amber', 'Comparar y precios', '“diferencia entre el FB20 y el FGL25T”, “compáralo con el otro”, “el más barato”, “el más caro”.' ),
					array( 'dashicons-info-outline', 'blue', 'Información del sitio', '“¿cuál es su horario?”, “¿cómo los contacto?”, “¿quiénes son?”, “¿qué es [nombre del sitio]?”: cita la frase de la página.' ),
					array( 'dashicons-location', 'teal', 'Ubicaciones', '“¿dónde están?”, “¿tienen tienda en zona 10?”, “¿qué zonas tienen?”. Abre el mapa con esa tienda marcada.' ),
				);
				foreach ( $examples as $example ) :
					?>
					<div class="asi-feature">
						<span class="asi-icon asi-icon--<?php echo esc_attr( $example[1] ); ?> dashicons <?php echo esc_attr( $example[0] ); ?>" aria-hidden="true"></span>
						<div><h3><?php echo esc_html( $example[2] ); ?></h3><p><?php echo esc_html( $example[3] ); ?></p></div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php $this->card_close(); ?>

		<?php $this->card_open( 'Cómo enseñarle', 'dashicons-welcome-learn-more', 'purple', 'asi-ensenar' ); ?>
			<ol class="asi-steps">
				<li><h3>Revisa las preguntas</h3><p>En <a href="<?php echo esc_url( $this->tab_url( 'aprendizaje' ) ); ?>">Aprendizaje → Preguntas por revisar</a>, están las que no supo responder bien, con cuántas veces las hicieron. Empieza por las que más se repiten.</p></li>
				<li><h3>Enséñale la respuesta</h3><p>Pulsa <strong>Enseñar</strong>, escribe el nombre de un producto o página y elige hasta 3. Desde ese momento, cuando alguien pregunte eso (con plurales, sin acentos o en otro orden), responde con lo que elegiste.</p></li>
				<li><h3>O dile qué significa</h3><p>Si la pregunta usa otra palabra para algo que el sitio sí tiene (“carretilla” en vez de “montacargas”), escribe la palabra del sitio y pulsa <strong>Guardar sinónimo</strong>. Sirve para todas las búsquedas con esa palabra, no solo para esta pregunta, y queda en <a href="<?php echo esc_url( $this->tab_url( 'aprendizaje' ) . '#asi-sinonimos' ); ?>">Sinónimos propios</a>.</p></li>
				<li><h3>Quita lo que no aplica</h3><p>Si preguntan por algo que no vendes o no te interesa, pulsa <strong>Quitar de la lista</strong>. No cambia nada en el asistente, solo limpia la lista.</p></li>
			</ol>
			<p class="asi-muted">Además aprende solo: cuando varios visitantes abren el mismo resultado después de una búsqueda, ese resultado sube un poco para esa búsqueda (lo ves en <strong>Lo que más abren</strong>). Lo enseñado se puede deshacer en <strong>Respuestas enseñadas</strong> con “Olvidar”.</p>
		<?php $this->card_close(); ?>

		<?php $this->card_open( 'Trucos y ajustes', 'dashicons-admin-tools', 'teal' ); ?>
			<ul class="asi-list">
				<li><strong>En otra página:</strong> pega el shortcode <code>[<?php echo esc_html( ASI_Chat_Page::SHORTCODE ); ?>]</code> donde quieras.</li>
				<li><strong>Con una pregunta ya hecha:</strong> enlaza a la página del chat agregando <code>?q=tu pregunta</code> (sirve para botones como “Pregúntale al asistente”).</li>
				<li><strong>Tú o usted, y emojis:</strong> en <a href="<?php echo esc_url( $this->tab_url( 'apariencia' ) ); ?>">Apariencia → Forma de hablar</a>.</li>
				<li><strong>Si algo se clasifica mal</strong> (un tipo de contenido que no debería salir, un campo que no es precio): corrígelo en <a href="<?php echo esc_url( $this->tab_url( 'contenido' ) ); ?>">Contenido</a>.</li>
				<li><strong>Contenido nuevo o editado:</strong> se actualiza solo al publicar o guardar. No hay que reindexar a mano.</li>
			</ul>
		<?php $this->card_close(); ?>
		<?php
	}

	// ---------- Probar ----------

	private function tab_probar() {
		$languages = $this->plugin->languages->all();
		$default   = $this->plugin->languages->default_slug();
		?>
		<?php $this->card_open( 'Probar el asistente', 'dashicons-format-chat', 'green' ); ?>
			<p class="asi-muted">Conversa como lo haría un visitante, con los colores reales. Cambiar de idioma empieza una conversación nueva.</p>
			<?php if ( 'off' === ASI_Settings::mode() ) : ?>
				<p class="asi-note asi-note--warn">La visibilidad está en <strong>Apagado</strong>: cámbiala a <strong>Solo administradores</strong> en Inicio para poder probar.</p>
			<?php endif; ?>
			<div class="asi-toolbar">
				<label for="asi-lang"><strong>Idioma / país</strong></label>
				<select id="asi-lang">
					<?php foreach ( $languages as $slug => $language ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, $default ); ?>><?php echo esc_html( $slug . ( $language['country_name'] ? ' · ' . $language['country_name'] : '' ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="button" class="button" id="asi-reset"><span class="dashicons dashicons-update" aria-hidden="true"></span> Nueva conversación</button>
			</div>
			<div class="asi-chat" style="<?php echo esc_attr( ASI_Appearance::css_variables() ); ?>;height:600px">
				<div class="asi-chat__log" id="asi-log"></div>
				<form class="asi-chat__form" id="asi-form">
					<input type="text" id="asi-input" class="asi-chat__input" placeholder="Escribe como lo haría un cliente…" autocomplete="off">
					<button class="asi-chat__send" aria-label="Enviar"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M3.4 20.4 21 12 3.4 3.6 3.4 10l12.6 2-12.6 2z"/></svg></button>
				</form>
			</div>
			<details class="asi-details"><summary>Respuesta técnica (JSON y tiempo)</summary><pre id="asi-json" class="asi-json"></pre></details>
		<?php $this->card_close(); ?>
		<?php
	}

	// ------------------------------------------------------------------
	// Utilidades
	// ------------------------------------------------------------------

	private function adapter_label() {
		$labels = array( 'polylang' => 'Polylang', 'wpml' => 'WPML', 'none' => 'sin plugin de idiomas' );
		$id     = $this->plugin->languages->adapter()->get_id();
		return isset( $labels[ $id ] ) ? $labels[ $id ] : $id;
	}

	private function hidden_fields( $action, $tab ) {
		wp_nonce_field( self::NONCE );
		echo '<input type="hidden" name="action" value="asi_action">';
		echo '<input type="hidden" name="asi_do" value="' . esc_attr( $action ) . '">';
		echo '<input type="hidden" name="asi_tab" value="' . esc_attr( $tab ) . '">';
	}

	private function action_button( $action, $label, $tab, $class = 'button', $icon = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="asi-inline-form">';
		$this->hidden_fields( $action, $tab );
		echo '<button class="' . esc_attr( $class ) . '">';
		if ( $icon ) {
			echo '<span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span> ';
		}
		echo esc_html( $label ) . '</button></form>';
	}
}
