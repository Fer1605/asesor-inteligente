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
		'reportes'   => 'Reportería',
		'ia'         => 'IA',
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
		add_action( 'admin_post_asi_action', array( $this, 'handle_action' ) );
		add_action( 'admin_post_asi_report_csv', array( $this, 'report_csv' ) );
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
		// Si venía de una lista completa (Ver todas), vuelve a la misma página y búsqueda.
		foreach ( array( 'ver', 'pag', 'buscar' ) as $arg ) {
			if ( isset( $_POST[ 'asi_' . $arg ] ) && '' !== $_POST[ 'asi_' . $arg ] ) {
				$extra[ $arg ] = sanitize_text_field( wp_unslash( $_POST[ 'asi_' . $arg ] ) );
			}
		}

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
				// Uno o varios correos de prueba, separados por coma, punto y coma o espacio.
				$email = implode( ', ', ASI_Settings::email_list( isset( $_POST['test_email'] ) ? sanitize_text_field( wp_unslash( $_POST['test_email'] ) ) : '' ) );
				ASI_Settings::update(
					array(
						'actions_mode'     => in_array( $mode, array( 'off', 'test', 'live' ), true ) ? $mode : 'test',
						'test_email'       => $email,
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

			case 'ai_save':
				$this->save_ai();
				break;

			case 'ai_test':
				$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
				set_transient( 'asi_ai_test_' . get_current_user_id(), array_merge( array( 'provider' => $provider ), ASI_AI::test( $provider ) ), 10 * MINUTE_IN_SECONDS );
				break;

			case 'ai_edit':
				ASI_AI::edit(
					isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '',
					isset( $_POST['phrase'] ) ? sanitize_text_field( wp_unslash( $_POST['phrase'] ) ) : ''
				);
				break;

			case 'ai_forget':
				ASI_AI::forget( isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '' );
				break;

			case 'ai_clear':
				delete_option( ASI_AI::CACHE_OPTION );
				break;

			case 'ai_on':
			case 'ai_off':
				ASI_Settings::update( array( 'ai_enabled' => 'ai_on' === $action ) );
				break;

			case 'ai_notices_clear':
				ASI_AI::clear_notices();
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
				<table class="widefat striped asi-table"><thead><tr><th>Mostrar</th><th>Campo del sitio</th><th>Lo tienen</th><th>Se muestra en la tarjeta como</th></tr></thead><tbody>
				<?php foreach ( $shares as $label => $share ) : ?>
					<?php
					$rule = isset( $settings[ $label ] ) ? (array) $settings[ $label ] : array();
					$show = isset( $rule['show'] ) ? (bool) $rule['show'] : $share <= 0.5;
					?>
					<tr>
						<td><input type="checkbox" name="card_flags[<?php echo esc_attr( $label ); ?>][show]" value="1" <?php checked( $show ); ?>></td>
						<td><?php echo esc_html( $label ); ?>
							<?php if ( $share > 0.5 ) : ?><br><span class="asi-muted">Lo tienen casi todos: no distingue a ningún vehículo.</span><?php endif; ?>
							<?php if ( 0.0 === (float) $share ) : ?><br><span class="asi-muted">Hoy ningún producto lo tiene; aparecerá cuando alguno lo tenga.</span><?php endif; ?>
						</td>
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
		'reportes'   => 'dashicons-chart-area',
		'ia'         => 'dashicons-lightbulb',
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
			'ai_save'      => 'Ajustes de la IA guardados.',
			'ai_test'      => 'Prueba de conexión hecha: el resultado está abajo.',
			'ai_forget'    => 'Quitado: la próxima vez que alguien lo escriba se vuelve a preguntar a la IA.',
			'ai_edit'      => 'Guardado: desde ahora el chat lo entiende así.',
			'ai_clear'     => 'Lista de frases interpretadas vaciada.',
			'ai_notices_clear' => 'Avisos de la IA borrados.',
			'ai_on'        => 'IA activada. Ahora elige con qué IA te quieres conectar.',
			'ai_off'       => 'IA desactivada: el chat funciona sin ella. Las claves y lo aprendido se conservan.',
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
						<?php if ( 'ia' === $key && 'ia' !== $tab && class_exists( 'ASI_AI' ) && ( $unseen = ASI_AI::unseen( get_current_user_id() ) ) ) : ?><span class="asi-tabs__badge" title="Avisos nuevos de la IA"><?php echo (int) $unseen; ?></span><?php endif; ?>
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
				<p><label><strong>Correos de prueba</strong> <span class="asi-muted">(uno o varios, separados por coma)</span><br><input type="text" class="large-text" name="test_email" value="<?php echo esc_attr( $settings['test_email'] ); ?>" placeholder="tu@correo.com, jefe@correo.com"></label></p>
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
		$clicks    = ASI_Learning::top_clicks( 200 );
		$titles    = $this->titles_for( array_merge( wp_list_pluck( $clicks, 'post_id' ), $this->learned_ids( $learned ) ) );
		$ver       = $this->list_open();
		$q_list    = $this->list_slice( 'preguntas', $questions, function ( $row ) { return $row['q']; } );
		$taught    = array();
		foreach ( $learned as $lang => $entries ) {
			foreach ( $entries as $key => $entry ) {
				$taught[ $lang . '|' . $key ] = array( 'lang' => $lang, 'key' => $key, 'entry' => $entry );
			}
		}
		$t_list    = $this->list_slice( 'ensenadas', $taught, function ( $row ) { return $row['entry']['q']; } );
		$c_list    = $this->list_slice( 'abren', $clicks, function ( $row ) use ( $titles ) { return $row['qkey'] . ' ' . ( isset( $titles[ (int) $row['post_id'] ] ) ? $titles[ (int) $row['post_id'] ] : '' ); } );
		if ( $ver ) {
			echo '<style>.asi-hero,.asi-hero+p.asi-muted{display:none}</style>';
		}
		// Con la IA activa, ella entiende lo que antes quedaba como "pregunta por revisar": esa lista
		// se oculta y lo aprendido de la IA ocupa su lugar. Sin IA, la lista sigue siendo la forma de enseñarle.
		$ai_on = class_exists( 'ASI_AI' ) && ASI_AI::enabled();
		?>
		<div class="asi-hero">
			<span class="asi-hero__icon dashicons dashicons-welcome-learn-more" aria-hidden="true"></span>
			<p class="asi-hero__lead">
				<?php if ( $ai_on ) : ?>
				<strong>El asistente aprende con el uso.</strong>
				Lo que el chat no tenía claro lo lee la IA, y queda guardado para no volver a preguntarle: aquí ves qué escribió la persona, cómo lo entendió y qué le respondió, y puedes corregirlo.
				Además, observa qué resultados abre la gente después de cada búsqueda y los sube un poco en esa búsqueda.
				<?php else : ?>
				<strong>El asistente aprende con el uso, sin inteligencia artificial externa.</strong>
				Guarda las preguntas que no supo responder bien (sin datos de quien pregunta), para que le enseñes la respuesta una vez y la sepa siempre.
				Además, observa qué resultados abre la gente después de cada búsqueda y los sube un poco en esa búsqueda.
				<?php endif; ?>
			</p>
		</div>

		<p class="asi-muted">¿Cómo enseñarle paso a paso? Está en la <a href="<?php echo esc_url( $this->tab_url( 'guia' ) . '#asi-ensenar' ); ?>">Guía</a>.</p>

		<?php if ( ( ! $ver && ! $ai_on ) || 'preguntas' === $ver ) : ?>
		<?php $this->card_open( 'Preguntas por revisar', 'dashicons-format-status', 'amber' ); ?>
			<?php $this->list_search( 'aprendizaje', 'preguntas', $q_list, 'Buscar una pregunta…' ); ?>
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
					<?php foreach ( $q_list['rows'] as $id => $row ) : ?>
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
				<?php $this->list_footer( 'aprendizaje', 'preguntas', $q_list ); ?>
				<div class="asi-actions"><?php $this->action_button( 'clear_questions', 'Vaciar lista', 'aprendizaje', 'button', 'dashicons-trash' ); ?></div>
			<?php endif; ?>
		<?php $this->card_close(); ?>
		<?php endif; ?>

			<?php
			// Todo lo que la IA interpretó queda guardado y se aplica solo: si alguien vuelve a escribir lo
			// mismo, el chat ya sabe qué hacer sin consultar a la IA. Se puede corregir o quitar.
			$ai_rows = class_exists( 'ASI_AI' ) ? ASI_AI::cache() : array();
			uasort(
				$ai_rows,
				function ( $a, $b ) {
					return $b['last'] - $a['last'];
				}
			);
			$ai_list = $this->list_slice( 'ia', $ai_rows, function ( $row ) { return $row['message'] . ' ' . $row['phrase'] . ' ' . ( isset( $row['context'] ) ? $row['context'] : '' ); } );
			?>
			<?php if ( ! $ver || 'ia' === $ver ) : ?>
			<?php $this->card_open( 'Aprendido de la IA (automático)', 'dashicons-lightbulb', 'purple' ); ?>
				<p class="asi-muted">Cada mensaje que el chat no tenía claro y revisó la IA queda aquí y <strong>se aplica solo</strong>: si alguien vuelve a escribir lo mismo, el chat ya sabe qué hacer <strong>sin volver a consultar a la IA</strong>. Si una está mal, corrígela o quítala.</p>
				<?php $this->list_search( 'aprendizaje', 'ia', $ai_list, 'Buscar lo que escribieron o cómo se entendió…' ); ?>
				<?php if ( ! $ai_list['rows'] ) : ?>
					<div class="asi-empty"><span class="dashicons dashicons-lightbulb" aria-hidden="true"></span><?php echo '' !== $ai_list['query'] ? 'Nada coincide con esa búsqueda.' : ( $ai_on ? 'Todavía nada.' : 'La IA está apagada (pestaña IA). Mientras tanto, lo que el chat no entienda aparece en “Preguntas por revisar”.' ); ?></div>
				<?php else : ?>
					<table class="asi-table asi-table--fit asi-ai-learned">
						<colgroup><col class="asi-col-said"><col class="asi-col-meaning"><col><col class="asi-col-ai-actions"></colgroup>
						<thead><tr><th>Lo que escribió</th><th>Cómo lo entendió</th><th>Qué respondió el chat</th><th><span class="screen-reader-text">Acciones</span></th></tr></thead>
						<tbody>
						<?php foreach ( $ai_list['rows'] as $key => $row ) : ?>
							<tr>
								<td>
									<?php echo esc_html( $row['message'] ); ?>
									<?php if ( ! empty( $row['context'] ) ) : ?><div class="asi-muted asi-ai-learned__context">después de: <?php echo esc_html( $row['context'] ); ?></div><?php endif; ?>
								</td>
								<td class="asi-ai-learned__meaning"><?php echo esc_html( '' !== (string) $row['phrase'] ? $row['phrase'] : 'Lo mismo que entendió el chat' ); ?></td>
								<td class="asi-ai-learned__reply"><?php echo ! empty( $row['reply'] ) ? esc_html( $row['reply'] ) : '<span class="asi-muted">Se verá la próxima vez que alguien lo escriba.</span>'; ?></td>
								<td class="asi-ai-learned__actions">
									<details class="asi-ai-edit">
										<summary class="button button-small"><span class="dashicons dashicons-edit" aria-hidden="true"></span> Editar</summary>
										<div class="asi-teach__box asi-ai-edit__box">
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asi-teach__form">
												<?php $this->hidden_fields( 'ai_edit', 'aprendizaje' ); ?>
												<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
												<p class="asi-ai-edit__said">Escribió: <strong>“<?php echo esc_html( $row['message'] ); ?>”</strong></p>
												<label for="asi-ai-phrase-<?php echo esc_attr( md5( $key ) ); ?>"><strong>¿Qué quiso decir?</strong></label>
												<p class="asi-muted">Escríbelo como se lo pedirías tú al chat, en una frase corta y directa. El chat lo leerá así cada vez que alguien escriba lo mismo.</p>
												<ul class="asi-ai-edit__examples asi-muted">
													<li>“quiero que no valga más de 100 mil” → <code>hasta 100000</code></li>
													<li>“una troca nissan que no pase de 300” → <code>pickup nissan hasta 300000</code></li>
													<li>“dame las cuotas de la segunda y la tercera” → <code>cuotas de la 2 y la 3</code></li>
												</ul>
												<textarea id="asi-ai-phrase-<?php echo esc_attr( md5( $key ) ); ?>" name="phrase" rows="3" required><?php echo esc_textarea( $row['phrase'] ); ?></textarea>
												<p class="asi-ai-edit__buttons"><button type="button" class="button" onclick="this.closest('details').open=false">Cancelar</button><button class="button button-primary">Guardar</button></p>
											</form>
										</div>
									</details>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="asi-inline-form">
										<?php $this->hidden_fields( 'ai_forget', 'aprendizaje' ); ?>
										<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
										<button class="button button-small asi-button-danger" title="La próxima vez que alguien lo escriba se vuelve a preguntar a la IA."><span class="dashicons dashicons-trash" aria-hidden="true"></span> Quitar</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php $this->list_footer( 'aprendizaje', 'ia', $ai_list ); ?>
					<?php if ( $ai_list['full'] ) : ?>
						<div class="asi-actions"><?php $this->action_button( 'ai_clear', 'Vaciar todo lo aprendido de la IA', 'aprendizaje', 'button', 'dashicons-trash' ); ?></div>
					<?php endif; ?>
				<?php endif; ?>
			<?php $this->card_close(); ?>
			<?php endif; ?>

		<?php
		if ( ! $ver ) {
			$this->synonyms_card();
		}
		?>

		<div class="<?php echo $ver ? '' : 'asi-grid'; ?>">
			<?php if ( ! $ver || 'ensenadas' === $ver ) : ?>
			<?php $this->card_open( 'Respuestas enseñadas', 'dashicons-yes-alt', 'green' ); ?>
				<?php $this->list_search( 'aprendizaje', 'ensenadas', $t_list, 'Buscar una pregunta enseñada…' ); ?>
				<p class="asi-muted">Cuando alguien pregunte esto (con cualquier orden de palabras, plurales o sin acentos), el asistente responde con lo que elegiste.</p>
				<?php if ( ! $learned ) : ?>
					<div class="asi-empty"><span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>Todavía no le has enseñado nada.</div>
				<?php else : ?>
					<table class="asi-table">
						<thead><tr><th>Pregunta</th><th>Responde con</th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $t_list['rows'] as $item ) : ?>
							<?php
							$lang  = $item['lang'];
							$key   = $item['key'];
							$entry = $item['entry'];
							?>
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
						</tbody>
					</table>
					<?php $this->list_footer( 'aprendizaje', 'ensenadas', $t_list ); ?>
				<?php endif; ?>
			<?php $this->card_close(); ?>
			<?php endif; ?>

			<?php if ( ! $ver || 'abren' === $ver ) : ?>
			<?php $this->card_open( 'Lo que más abren', 'dashicons-chart-bar', 'blue' ); ?>
				<p class="asi-muted">De lo que el <strong>chat</strong> sugirió para cada búsqueda, qué vehículo (o contenido) abrió la gente. Ejemplo: después de buscar “automático gasolina”, el más abierto fue tal vehículo, tantas veces.
				Cuando un resultado llega a <?php echo (int) ASI_Search::MIN_CLICKS; ?> visitas distintas, el chat lo pone un poco más arriba la próxima vez que alguien busque lo mismo. Cada visitante cuenta una vez al día.
				Los totales de lo más visto, cotizado y comparado (chat y sitio) están en <a href="<?php echo esc_url( $this->tab_url( 'reportes' ) ); ?>">Reportería</a>.</p>
				<?php $this->list_search( 'aprendizaje', 'abren', $c_list, 'Buscar una búsqueda o un resultado…' ); ?>
				<?php if ( ! $c_list['rows'] ) : ?>
					<div class="asi-empty"><span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>Todavía no hay clics registrados.</div>
				<?php else : ?>
					<table class="asi-table">
						<thead><tr><th>Lo que buscó en el chat</th><th>Lo que abrió</th><th>Visitas</th></tr></thead>
						<tbody>
						<?php foreach ( $c_list['rows'] as $click ) : ?>
							<tr>
								<td><?php echo esc_html( $click['qkey'] ); ?> <span class="asi-tag asi-tag--code"><?php echo esc_html( $click['lang'] ); ?></span></td>
								<td><?php echo esc_html( isset( $titles[ (int) $click['post_id'] ] ) ? $titles[ (int) $click['post_id'] ] : '#' . (int) $click['post_id'] ); ?></td>
								<td><strong><?php echo (int) $click['clicks']; ?></strong><?php echo (int) $click['clicks'] >= ASI_Search::MIN_CLICKS ? ' <span class="asi-tag asi-tag--ok">sube</span>' : ''; ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php $this->list_footer( 'aprendizaje', 'abren', $c_list ); ?>
				<?php endif; ?>
			<?php $this->card_close(); ?>
			<?php endif; ?>
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

	// ---------- Reportería ----------

	/** Filtros del reporte (GET): periodo y si cuentan las pruebas. */
	private function report_filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$get    = function ( $key ) {
			return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
		};
		$period = ASI_Reports::period( '' !== $get( 'periodo' ) ? $get( 'periodo' ) : '30', $get( 'desde' ), $get( 'hasta' ) );
		// Sin filtrar todavía: las pruebas cuentan mientras las acciones del chat están en modo prueba.
		$test = '' !== $get( 'f' ) ? '1' === $get( 'pruebas' ) : 'test' === ASI_Actions::mode();
		// phpcs:enable
		return array( $period, $test );
	}

	private function tab_reportes() {
		list( $period, $test ) = $this->report_filters();
		$report = ASI_Reports::build( $period, $test );
		$c      = $report['counts'];
		$total  = function ( array $pair ) {
			return $pair['chat'] + $pair['site'];
		};
		$pct    = function ( $part, $all ) {
			return $all ? round( 100 * $part / $all ) . '%' : '—';
		};
		$csv    = function ( $scope ) use ( $period, $test ) {
			return wp_nonce_url(
				add_query_arg(
					array( 'action' => 'asi_report_csv', 'scope' => $scope, 'periodo' => $period['key'], 'desde' => $period['from'], 'hasta' => $period['to'], 'f' => 1, 'pruebas' => $test ? 1 : 0 ),
					admin_url( 'admin-post.php' )
				),
				'asi_report_csv'
			);
		};
		$conv   = $c['conversation'] ? min( 1, $c['chat_leads'] / $c['conversation'] ) : 0;
		$fmt    = function ( $n ) {
			return number_format_i18n( (int) $n );
		};
		?>
		<div class="asi-hero">
			<span class="asi-hero__icon dashicons dashicons-chart-area" aria-hidden="true"></span>
			<p class="asi-hero__lead">
				<strong>¿El chat da resultados? Aquí se compara con lo que la gente hace normalmente en el sitio.</strong>
				También qué es lo más buscado, visto, cotizado y comparado. No guarda datos de quién visita.
			</p>
		</div>

		<?php $demo = get_option( ASI_Reports::DEMO_OPTION ); ?>
		<?php if ( is_array( $demo ) ) : ?>
			<p class="asi-note asi-note--warn"><strong>Datos de ejemplo.</strong> Los números de <?php echo esc_html( date_i18n( 'j \d\e F', strtotime( $demo['from'] ) ) ); ?> al <?php echo esc_html( date_i18n( 'j \d\e F', strtotime( $demo['to'] ) ) ); ?> son inventados, para ver cómo se ve el panel completo. No son visitas reales; se quitan antes de usarlo en serio.</p>
		<?php endif; ?>

		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="asi-report-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
			<input type="hidden" name="tab" value="reportes">
			<input type="hidden" name="f" value="1">
			<label>Periodo
				<select name="periodo" class="asi-report-period">
					<?php foreach ( array( '7' => 'Últimos 7 días', '30' => 'Últimos 30 días', '90' => 'Últimos 90 días', '365' => 'Último año', 'custom' => 'Entre fechas (máximo un año)…' ) as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $period['key'], (string) $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="asi-report-dates">Desde <input type="date" name="desde" value="<?php echo esc_attr( $period['from'] ); ?>" min="<?php echo esc_attr( gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -' . ( ASI_Reports::KEEP_DAYS - 1 ) . ' days' ) ) ); ?>" max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>"></label>
			<label class="asi-report-dates">Hasta <input type="date" name="hasta" value="<?php echo esc_attr( $period['to'] ); ?>" max="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>"></label>
			<label class="asi-report-check"><input type="checkbox" name="pruebas" value="1" <?php checked( $test ); ?>> Incluir pruebas del chat</label>
			<button class="button button-primary">Ver reporte</button>
		</form>
		<script>
		(function(){var s=document.querySelector('.asi-report-period');if(!s){return;}var t=function(){document.querySelectorAll('.asi-report-dates').forEach(function(l){l.style.display=s.value==='custom'?'':'none';});};s.addEventListener('change',t);t();})();
		</script>

		<div class="asi-report-export">
			<span class="asi-muted">Descargar reporte (Excel / CSV):</span>
			<a class="button" href="<?php echo esc_url( $csv( 'all' ) ); ?>"><span class="dashicons dashicons-download" aria-hidden="true"></span> Global: chat y sitio</a>
			<a class="button" href="<?php echo esc_url( $csv( 'chat' ) ); ?>"><span class="dashicons dashicons-format-chat" aria-hidden="true"></span> Solo chat</a>
			<a class="button" href="<?php echo esc_url( $csv( 'site' ) ); ?>"><span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span> Solo sitio</a>
		</div>

		<div class="asi-stats">
			<?php
			$this->stat( 'dashicons-format-chat', 'blue', $fmt( $c['conversation'] ), 'Conversaciones en el chat', $fmt( $c['search'] ) . ' búsquedas' );
			$this->stat( 'dashicons-admin-site-alt3', 'purple', $fmt( $c['visitor'] ), 'Personas buscando en el sitio', $fmt( $c['search_site'] ) . ' búsquedas con filtros o buscador' );
			$this->stat( 'dashicons-media-document', 'green', $fmt( $total( $c['quote'] ) ), 'Cotizaciones', 'Chat ' . $fmt( $c['quote']['chat'] ) . ' · Sitio ' . $fmt( $c['quote']['site'] ) );
			$this->stat( 'dashicons-columns', 'amber', $fmt( $total( $c['compare'] ) ), 'Comparaciones', 'Chat ' . $fmt( $c['compare']['chat'] ) . ' · Sitio ' . $fmt( $c['compare']['site'] ) );
			?>
		</div>

		<?php $this->card_open( '¿El chat da resultados?', 'dashicons-chart-line', 'blue' ); ?>
			<?php $this->chart_results( $report['rate'] ); ?>
		<?php $this->card_close(); ?>

		<?php $this->card_open( 'Día a día', 'dashicons-calendar-alt', 'purple' ); ?>
			<?php $this->chart_legend(); ?>
			<div class="asi-chart-pair">
				<?php
				$this->chart_days( $report['daily'], 'conversation', 'visitor', 'Personas: en el chat o buscando en el sitio', array( 'chat' => 'personas en el chat', 'site' => 'personas en el sitio' ) );
				$this->chart_days( $report['daily'], 'chat', 'site', 'Cotizaciones y comparaciones', array( 'chat' => 'desde el chat', 'site' => 'desde el sitio' ) );
				?>
			</div>
			<p class="asi-muted">
				Cotizaciones: la de un vehículo y la de varios (favoritos) cuentan igual.
				<?php if ( 'site' === $report['leads'] ) : ?>
					Cotizaciones y comparaciones salen de los Envíos de los formularios, con todo su historial.
				<?php else : ?>
					Este sitio no tiene un conector que lea sus formularios: solo se cuentan las del chat.
				<?php endif; ?>
				Lo demás se cuenta desde que se activó la reportería; se guarda un año y lo más viejo se borra solo.
			</p>
		<?php $this->card_close(); ?>

		<?php $this->card_open( 'Lo más buscado', 'dashicons-search', 'purple' ); ?>
			<p class="asi-muted">Qué características busca más la gente. <strong>Chat</strong>: lo que le pide al chat. <strong>Sitio</strong>: lo que marca en los filtros del listado o escribe en el buscador del sitio.</p>
			<?php if ( ! $report['criteria'] ) : ?>
				<div class="asi-empty"><span class="dashicons dashicons-search" aria-hidden="true"></span>Todavía no hay búsquedas en este periodo.</div>
			<?php else : ?>
				<?php $this->chart_legend(); ?>
				<div class="asi-report-criteria">
				<?php foreach ( $report['criteria'] as $dim => $labels ) : ?>
					<?php
					$rows = array();
					foreach ( $labels as $label => $pair ) {
						$rows[] = array( 'id' => 0, 'title' => (string) $label, 'chat' => $pair['chat'], 'site' => $pair['site'] );
					}
					?>
					<div class="asi-report-criteria__group">
						<h3><?php echo esc_html( $dim ); ?></h3>
						<?php $this->chart_bars( ASI_Reports::top( $rows, 'all' ) ); ?>
					</div>
				<?php endforeach; ?>
				</div>
			<?php endif; ?>
		<?php $this->card_close(); ?>

		<?php
		$sections = array(
			'view'    => array( 'Lo más visto', 'dashicons-visibility', 'Fichas abiertas por cualquier camino (también desde Google o un enlace). "Chat": las que se abrieron desde el chat o se vieron dentro de él.' ),
			'quote'   => array( 'Lo más cotizado', 'dashicons-media-document', 'Cada vehículo de una cotización cuenta una vez (la de favoritos suma cada uno).' ),
			'compare' => array( 'Lo más comparado', 'dashicons-columns', 'Cada vehículo de una comparación cuenta una vez.' ),
		);
		foreach ( $sections as $kind => $info ) :
			$shown = ASI_Reports::top( $report['items'][ $kind ], 'all' );
			$tones = array( 'view' => 'blue', 'quote' => 'green', 'compare' => 'amber' );
			$this->card_open( $info[0], $info[1], $tones[ $kind ] );
			?>
			<p class="asi-muted"><?php echo esc_html( $info[2] ); ?><?php echo $report['totals'][ $kind ] > ASI_Reports::MAX_ROWS ? ' ' . esc_html( sprintf( 'Se muestran los %1$d primeros de %2$d.', ASI_Reports::MAX_ROWS, $report['totals'][ $kind ] ) ) : ''; ?></p>
			<?php if ( ! $shown ) : ?>
				<div class="asi-empty"><span class="dashicons <?php echo esc_attr( $info[1] ); ?>" aria-hidden="true"></span>Nada en este periodo.</div>
			<?php else : ?>
				<?php $this->chart_legend(); ?>
				<?php $this->chart_bars( $shown, true ); ?>
			<?php endif; ?>
			<?php
			$this->card_close();
		endforeach;
		?>
		<div class="asi-chart-tip" role="tooltip" hidden></div>
		<script>
		( function () {
			var tip = document.querySelector( '.asi-chart-tip' );
			if ( ! tip ) { return; }
			var show = function ( e ) {
				var el = e.target.closest ? e.target.closest( '[data-tip]' ) : null;
				if ( ! el ) { tip.hidden = true; return; }
				tip.textContent = el.getAttribute( 'data-tip' );
				tip.hidden = false;
				var r = el.getBoundingClientRect(), w = tip.offsetWidth;
				var x = Math.min( window.innerWidth - w - 8, Math.max( 8, ( e.clientX || r.left + r.width / 2 ) - w / 2 ) );
				tip.style.left = ( x + window.scrollX ) + 'px';
				tip.style.top = ( r.top + window.scrollY - tip.offsetHeight - 8 ) + 'px';
			};
			document.addEventListener( 'mousemove', show );
			document.addEventListener( 'focusin', show );
			document.addEventListener( 'scroll', function () { tip.hidden = true; }, true );
		} )();
		</script>
		<?php
	}

	/** Colores de la reportería: el chat siempre azul y el sitio siempre naranja. */
	private function chart_legend() {
		echo '<p class="asi-chart-legend"><span><i class="asi-chart-key asi-chart-key--chat"></i>Chat</span><span><i class="asi-chart-key asi-chart-key--site"></i>Sitio</span></p>';
	}

	/**
	 * ¿Da resultados el chat? De cada 100 personas, cuántas cotizan o comparan, en el chat y en el sitio.
	 *
	 * @param array $rate chat, site (0-1 o null), base, since.
	 */
	private function chart_results( array $rate ) {
		$per100 = function ( $r ) {
			$n = 100 * $r;
			return $n >= 10 ? (string) round( $n ) : rtrim( rtrim( number_format( $n, 1 ), '0' ), '.' );
		};
		$b = $rate['base'];
		if ( null === $rate['chat'] && null === $rate['site'] ) {
			echo '<div class="asi-empty"><span class="dashicons dashicons-chart-line" aria-hidden="true"></span>Todavía no hay datos en este periodo. Se van llenando con cada conversación y cada visita.</div>';
			return;
		}
		$max = max( (float) $rate['chat'], (float) $rate['site'], 0.0001 );
		if ( null !== $rate['chat'] && null !== $rate['site'] && $rate['site'] > 0 && $rate['chat'] > 0 ) {
			$times = $rate['chat'] / $rate['site'];
			if ( $times >= 1.15 ) {
				$headline = sprintf( 'Quien usa el chat cotiza o compara %s veces más que quien solo navega el sitio.', number_format( $times, 1 ) );
			} elseif ( $times <= 0.87 ) {
				$headline = sprintf( 'Por ahora, quien usa el chat cotiza o compara menos que quien navega el sitio (%s de cada 100 contra %s).', $per100( $rate['chat'] ), $per100( $rate['site'] ) );
			} else {
				$headline = 'Quien usa el chat cotiza o compara más o menos igual que quien navega el sitio.';
			}
		} elseif ( null !== $rate['chat'] && $rate['chat'] > 0 && null !== $rate['site'] && 0.0 === (float) $rate['site'] ) {
			$headline = 'En este periodo cotizaron o compararon desde el chat y nadie desde el sitio.';
		} else {
			$headline = 'Todavía faltan datos de un lado para comparar.';
		}
		?>
		<p class="asi-chart-headline"><?php echo esc_html( $headline ); ?></p>
		<div class="asi-chart-compare">
			<?php
			$rows = array(
				'chat' => array( 'Usan el chat', $b['conversation'], $b['chat'], 'conversaciones' ),
				'site' => array( 'Navegan el sitio sin el chat', $b['visitor'], $b['site'], 'personas que buscaron o vieron vehículos' ),
			);
			foreach ( $rows as $side => $row ) :
				$r   = $rate[ $side ];
				$tip = null === $r ? 'Sin datos todavía' : sprintf( '%s de %s %s cotizaron o compararon', number_format_i18n( $row[2] ), number_format_i18n( $row[1] ), $row[3] );
				?>
				<div class="asi-chart-compare__row" tabindex="0" data-tip="<?php echo esc_attr( $tip ); ?>">
					<span class="asi-chart-compare__label"><i class="asi-chart-key asi-chart-key--<?php echo esc_attr( $side ); ?>"></i><?php echo esc_html( $row[0] ); ?></span>
					<span class="asi-chart-compare__track"><span class="asi-chart-compare__bar asi-chart-fill--<?php echo esc_attr( $side ); ?>" style="width:<?php echo esc_attr( null === $r ? 0 : max( 1, round( 100 * $r / $max ) ) ); ?>%"></span></span>
					<span class="asi-chart-compare__value"><?php echo null === $r ? '—' : '<strong>' . esc_html( $per100( $r ) ) . '</strong> de cada 100'; ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<p class="asi-muted">Cuenta a quienes cotizaron o compararon. Del chat: de cada conversación. Del sitio: de cada persona que filtró, buscó o abrió una ficha sin usar el chat (una vez al día).<?php echo $rate['since'] ? ' Las personas del sitio se cuentan desde el ' . esc_html( date_i18n( 'j \d\e F', strtotime( $rate['since'] ) ) ) . '; antes de eso no entran en esta comparación.' : ''; ?></p>
		<?php
	}

	/**
	 * Columnas por día (o por semana / mes si el periodo es largo) de dos medidas: chat y sitio.
	 *
	 * @param array  $daily Días.
	 * @param string $a     Medida del chat.
	 * @param string $b     Medida del sitio.
	 * @param string $title Título.
	 * @param array  $words chat, site: qué es cada número (para el detalle al pasar el mouse).
	 */
	private function chart_days( array $daily, $a, $b, $title, array $words ) {
		$span    = count( $daily );
		$group   = $span > 180 ? 'm' : ( $span > 62 ? 'w' : 'd' );
		$buckets = array();
		foreach ( $daily as $day => $row ) {
			$t   = strtotime( $day . ' 12:00:00' );
			$key = 'm' === $group ? gmdate( 'Y-m', $t ) : ( 'w' === $group ? gmdate( 'o-W', $t ) : $day );
			if ( ! isset( $buckets[ $key ] ) ) {
				$buckets[ $key ] = array( 'from' => $day, 'to' => $day, 'chat' => 0, 'site' => 0 );
			}
			$buckets[ $key ]['to']    = $day;
			$buckets[ $key ]['chat'] += isset( $row[ $a ] ) ? (int) $row[ $a ] : 0;
			$buckets[ $key ]['site'] += isset( $row[ $b ] ) ? (int) $row[ $b ] : 0;
		}
		$max   = 0;
		$total = array( 'chat' => 0, 'site' => 0 );
		foreach ( $buckets as $bucket ) {
			$max            = max( $max, $bucket['chat'], $bucket['site'] );
			$total['chat'] += $bucket['chat'];
			$total['site'] += $bucket['site'];
		}
		$name = function ( $bucket ) use ( $group ) {
			$from = strtotime( $bucket['from'] );
			if ( 'm' === $group ) {
				return date_i18n( 'F Y', $from );
			}
			return 'w' === $group ? 'Semana del ' . date_i18n( 'j M', $from ) : date_i18n( 'D j M', $from );
		};
		$keys = array_keys( $buckets );
		$show = array( 0 => true, count( $keys ) - 1 => true, (int) floor( ( count( $keys ) - 1 ) / 2 ) => true );
		?>
		<figure class="asi-chart-days">
			<figcaption><strong><?php echo esc_html( $title ); ?></strong>
				<span class="asi-muted"><?php echo esc_html( sprintf( 'chat %s · sitio %s%s', number_format_i18n( $total['chat'] ), number_format_i18n( $total['site'] ), 'd' === $group ? '' : ( 'w' === $group ? ' · por semana' : ' · por mes' ) ) ); ?></span></figcaption>
			<?php if ( ! $max ) : ?>
				<div class="asi-empty asi-empty--small">Sin datos en este periodo.</div>
			<?php else : ?>
				<div class="asi-chart-days__plot">
					<span class="asi-chart-days__max"><?php echo esc_html( number_format_i18n( $max ) ); ?></span>
					<?php foreach ( array_values( $buckets ) as $i => $bucket ) : ?>
						<div class="asi-chart-days__col" tabindex="0" data-tip="<?php echo esc_attr( $name( $bucket ) . ': ' . number_format_i18n( $bucket['chat'] ) . ' ' . $words['chat'] . ' · ' . number_format_i18n( $bucket['site'] ) . ' ' . $words['site'] ); ?>">
							<span class="asi-chart-fill--chat" style="height:<?php echo esc_attr( $bucket['chat'] ? max( 1, round( 100 * $bucket['chat'] / $max, 1 ) ) : 0 ); ?>%"></span>
							<span class="asi-chart-fill--site" style="height:<?php echo esc_attr( $bucket['site'] ? max( 1, round( 100 * $bucket['site'] / $max, 1 ) ) : 0 ); ?>%"></span>
						</div>
					<?php endforeach; ?>
				</div>
				<div class="asi-chart-days__axis">
					<?php foreach ( array_values( $buckets ) as $i => $bucket ) : ?>
						<span><?php echo isset( $show[ $i ] ) ? esc_html( 'm' === $group ? date_i18n( 'M', strtotime( $bucket['from'] ) ) : date_i18n( 'j M', strtotime( $bucket['from'] ) ) ) : ''; ?></span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</figure>
		<?php
	}

	/**
	 * Barras horizontales apiladas (chat + sitio) con el total al final. Las primeras 10 a la vista;
	 * el resto (hasta 20) y la tabla con los números, al abrir "Ver los 20 y la tabla".
	 *
	 * @param array[] $rows  id, title, chat, site (ya ordenadas).
	 * @param bool    $links Enlazar al contenido.
	 */
	private function chart_bars( array $rows, $links = false ) {
		if ( ! $rows ) {
			return;
		}
		$max  = max( 1, $rows[0]['chat'] + $rows[0]['site'] );
		$fmt  = 'number_format_i18n';
		$draw = function ( array $list ) use ( $max, $fmt, $links ) {
			echo '<ol class="asi-chart-bars">';
			foreach ( $list as $row ) {
				$sum  = $row['chat'] + $row['site'];
				$url  = $links && ! empty( $row['id'] ) ? get_permalink( $row['id'] ) : '';
				$name = $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $row['title'] ) . '</a>' : esc_html( $row['title'] );
				printf(
					'<li class="asi-chart-bars__row" tabindex="0" data-tip="%1$s"><span class="asi-chart-bars__label">%2$s</span><span class="asi-chart-bars__track"><span class="asi-chart-fill--chat" style="width:%3$s%%"></span><span class="asi-chart-fill--site" style="width:%4$s%%"></span></span><span class="asi-chart-bars__value">%5$s</span></li>',
					esc_attr( sprintf( '%s: %s en total · chat %s · sitio %s', $row['title'], call_user_func( $fmt, $sum ), call_user_func( $fmt, $row['chat'] ), call_user_func( $fmt, $row['site'] ) ) ),
					$name, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapado arriba.
					esc_attr( round( 100 * $row['chat'] / $max, 2 ) ),
					esc_attr( round( 100 * $row['site'] / $max, 2 ) ),
					esc_html( call_user_func( $fmt, $sum ) )
				);
			}
			echo '</ol>';
		};
		$draw( array_slice( $rows, 0, 10 ) );
		?>
		<details class="asi-details asi-chart-more">
			<summary><?php echo count( $rows ) > 10 ? esc_html( sprintf( 'Ver los %d y la tabla', count( $rows ) ) ) : 'Ver la tabla'; ?></summary>
			<?php if ( count( $rows ) > 10 ) { $draw( array_slice( $rows, 10 ) ); } ?>
			<table class="asi-table asi-table--compact">
				<thead><tr><th>#</th><th></th><th class="asi-report-num">Total</th><th class="asi-report-num">Chat</th><th class="asi-report-num">Sitio</th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $i => $row ) : ?>
					<tr><td class="asi-muted"><?php echo (int) $i + 1; ?></td><td><?php echo esc_html( $row['title'] ); ?></td><td class="asi-report-num"><strong><?php echo esc_html( number_format_i18n( $row['chat'] + $row['site'] ) ); ?></strong></td><td class="asi-report-num"><?php echo esc_html( number_format_i18n( $row['chat'] ) ); ?></td><td class="asi-report-num"><?php echo esc_html( number_format_i18n( $row['site'] ) ); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</details>
		<?php
	}

	/** Descarga del reporte en CSV. */
	public function report_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'asesor-inteligente' ) );
		}
		check_admin_referer( 'asi_report_csv' );
		list( $period, $test ) = $this->report_filters();
		$scope  = isset( $_GET['scope'] ) ? sanitize_key( wp_unslash( $_GET['scope'] ) ) : 'all';
		$names  = array( 'all' => 'global', 'chat' => 'chat', 'site' => 'sitio' );
		$scope  = isset( $names[ $scope ] ) ? $scope : 'all';
		$csv    = ASI_Reports::csv( ASI_Reports::build( $period, $test ), $scope );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="reporte-asesor-' . $names[ $scope ] . '-' . $period['from'] . '-a-' . $period['to'] . '.csv"' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
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

	// ---------- IA ----------

	private function save_ai() {
		$chosen = isset( $_POST['ai_provider'] ) ? sanitize_key( wp_unslash( $_POST['ai_provider'] ) ) : 'groq';
		$chosen = isset( ASI_AI::PROVIDERS[ $chosen ] ) ? $chosen : 'groq';
		$order  = array_values( array_unique( array_merge( array( $chosen ), array_keys( ASI_AI::PROVIDERS ) ) ) );

		// "Otra IA": nombre, dirección (solo https) y precio opcional de su modelo.
		$custom = isset( $_POST['ai_custom'] ) && is_array( $_POST['ai_custom'] ) ? wp_unslash( $_POST['ai_custom'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$url    = isset( $custom['url'] ) ? esc_url_raw( trim( (string) $custom['url'] ), array( 'https' ) ) : '';
		$price  = function ( $key ) use ( $custom ) {
			$value = isset( $custom[ $key ] ) ? str_replace( ',', '.', trim( (string) $custom[ $key ] ) ) : '';
			return '' === $value || ! is_numeric( $value ) ? '' : (string) max( 0, min( 1000, round( (float) $value, 4 ) ) );
		};
		ASI_Settings::update(
			array(
				'ai_custom' => array(
					'name'      => isset( $custom['name'] ) ? mb_substr( sanitize_text_field( (string) $custom['name'] ), 0, 40 ) : '',
					'url'       => $url,
					'price_in'  => $price( 'price_in' ),
					'price_out' => $price( 'price_out' ),
				),
			)
		);

		$models = array();
		foreach ( array_keys( ASI_AI::PROVIDERS ) as $provider ) {
			$text                = isset( $_POST['ai_models'][ $provider ] ) ? sanitize_text_field( wp_unslash( $_POST['ai_models'][ $provider ] ) ) : '';
			$models[ $provider ] = implode( ', ', array_filter( array_map( 'trim', preg_split( '/[\s,]+/', preg_replace( '#[^A-Za-z0-9_./:,\s\-]#', '', $text ) ) ) ) );
			if ( ! empty( $_POST['ai_drop'][ $provider ] ) ) {
				ASI_AI::save_key( $provider, '' );
			}
			$key = isset( $_POST['ai_key'][ $provider ] ) ? trim( sanitize_text_field( wp_unslash( $_POST['ai_key'][ $provider ] ) ) ) : '';
			if ( '' !== $key ) {
				ASI_AI::save_key( $provider, preg_replace( '/[^A-Za-z0-9_\-\.]/', '', $key ) );
				ASI_AI::unblock( $provider );
				ASI_AI::refresh_auto( $provider ); // Elige solos los modelos de la cuenta (si no se escriben).
			}
		}
		$usd = isset( $_POST['ai_daily_usd'] ) ? str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['ai_daily_usd'] ) ) ) : '1';
		ASI_Settings::update(
			array(
				'ai_order'     => $order,
				'ai_models'    => $models,
				'ai_daily_usd' => is_numeric( $usd ) ? max( 0, min( 1000, round( (float) $usd, 2 ) ) ) : 1.0,
			)
		);
		// Guardar es una forma de decir "ya lo revisé": la marca de "sin IA" se vuelve a calcular.
		delete_option( ASI_AI::DOWN_OPTION );
	}

	private function tab_ia() {
		$settings = ASI_AI::settings();
		$usage    = ASI_AI::usage();
		$blocked  = ASI_AI::blocked();
		$test     = get_transient( 'asi_ai_test_' . get_current_user_id() );
		$on       = ASI_AI::enabled();
		$notices  = ASI_AI::notices();
		$seen     = (int) get_user_meta( get_current_user_id(), 'asi_ai_notices_seen', true );
		ASI_AI::mark_seen( get_current_user_id() );
		$chosen   = $settings['provider'];
		$custom   = $settings['custom'];
		$working  = '';
		foreach ( $settings['order'] as $provider ) {
			if ( ASI_AI::ready( $provider ) ) {
				$working = $provider;
				break;
			}
		}
		?>
		<?php $this->card_open( 'Usar IA en este chat', 'dashicons-lightbulb', 'purple' ); ?>
			<p class="asi-muted">La IA lee cada búsqueda del chat y corrige si el chat entendió otra cosa. <strong>No le contesta</strong> al cliente: solo dice qué quiso decir, y el chat responde con los datos del sitio, así que no puede inventar precios ni productos.</p>
			<div class="asi-ai-switch">
				<p>Estado:
					<?php if ( $on ) : ?>
						<span class="asi-tag asi-tag--ok">Activa · <?php echo esc_html( ASI_AI::label( $working ) ); ?></span>
					<?php elseif ( $settings['enabled'] ) : ?>
						<span class="asi-tag asi-tag--warn">Activa, pero falta conectar una IA</span>
					<?php else : ?>
						<span class="asi-tag asi-tag--soft">Desactivada</span>
					<?php endif; ?></p>
				<?php
				if ( $settings['enabled'] ) {
					$this->action_button( 'ai_off', 'Desactivar', 'ia', 'button', 'dashicons-controls-pause' );
				} else {
					$this->action_button( 'ai_on', 'Activar', 'ia', 'button button-primary', 'dashicons-controls-play' );
				}
				?>
			</div>
		<?php $this->card_close(); ?>
		<?php
		if ( ! $settings['enabled'] ) {
			return; // Desactivada: lo demás se muestra al activarla.
		}
		?>

		<?php if ( $notices ) : ?>
			<?php $this->card_open( 'Avisos de la IA', 'dashicons-bell', 'amber', 'asi-ai-avisos' ); ?>
				<?php if ( ASI_AI::is_down() ) : ?>
					<p class="asi-note asi-note--warn"><strong>Ahora el chat funciona sin IA.</strong> Sigue contestando con lo que ya sabe y con lo que la IA le enseñó, pero puede entender peor las frases enredadas. Vuelve solo cuando un modelo se libere o al día siguiente.</p>
				<?php endif; ?>
				<ul class="asi-ai-notices">
					<?php foreach ( array_slice( $notices, 0, 10 ) as $notice ) : ?>
						<li class="asi-ai-notices__item asi-ai-notices__item--<?php echo esc_attr( $notice['type'] ); ?><?php echo $notice['time'] > $seen ? ' is-new' : ''; ?>">
							<span class="dashicons <?php echo esc_attr( 'ok' === $notice['type'] ? 'dashicons-yes-alt' : ( 'stop' === $notice['type'] ? 'dashicons-dismiss' : 'dashicons-warning' ) ); ?>" aria-hidden="true"></span>
							<span class="asi-ai-notices__time"><?php echo esc_html( wp_date( 'd/m H:i', $notice['time'] ) ); ?></span>
							<span><?php echo esc_html( $notice['text'] ); ?></span>
							<?php if ( $notice['time'] > $seen ) : ?><span class="asi-tag asi-tag--warn">Nuevo</span><?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<div class="asi-actions"><?php $this->action_button( 'ai_notices_clear', 'Borrar avisos', 'ia', 'button', 'dashicons-trash' ); ?></div>
			<?php $this->card_close(); ?>
		<?php endif; ?>

		<?php $this->card_open( 'Conectar la IA', 'dashicons-admin-plugins', 'purple' ); ?>
			<p class="asi-muted">Antes de enviarle el mensaje a la IA se le quitan correos, teléfonos y enlaces. Si la IA no responde o se acaba su límite, el chat sigue funcionando sin ella y te deja un aviso aquí.</p>

			<?php // Formularios de "Probar" fuera del principal (no se puede anidar un formulario dentro de otro). ?>
			<?php foreach ( ASI_AI::PROVIDERS as $provider => $info ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="asi-ai-test-<?php echo esc_attr( $provider ); ?>" hidden>
					<?php $this->hidden_fields( 'ai_test', 'ia' ); ?>
					<input type="hidden" name="provider" value="<?php echo esc_attr( $provider ); ?>">
				</form>
			<?php endforeach; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off" class="asi-ai-form">
				<?php $this->hidden_fields( 'ai_save', 'ia' ); ?>
				<p><strong>¿Con qué IA te quieres conectar?</strong></p>
				<div class="asi-ai-providers" role="radiogroup" aria-label="IA">
					<?php foreach ( ASI_AI::PROVIDERS as $provider => $info ) : ?>
						<label class="asi-ai-provider<?php echo $chosen === $provider ? ' is-active' : ''; ?>">
							<input type="radio" name="ai_provider" value="<?php echo esc_attr( $provider ); ?>" <?php checked( $chosen, $provider ); ?>>
							<span class="asi-ai-provider__name"><?php echo esc_html( $info['label'] ); ?></span>
							<span class="asi-ai-provider__tag"><?php echo esc_html( $info['tag'] ); ?></span>
							<?php if ( ASI_AI::has_key( $provider ) ) : ?><span class="asi-ai-provider__key"><span class="dashicons dashicons-yes" aria-hidden="true"></span> Con clave</span><?php endif; ?>
						</label>
					<?php endforeach; ?>
				</div>

				<?php foreach ( ASI_AI::PROVIDERS as $provider => $info ) : ?>
					<?php
					$hint   = ASI_AI::key_hint( $provider );
					$label  = 'custom' === $provider ? 'la IA que elegiste' : $info['label'];
					$auto   = ASI_AI::models_auto( $provider );
					$typed  = isset( $settings['models'][ $provider ] ) ? (string) $settings['models'][ $provider ] : '';
					$step   = 1;
					?>
					<fieldset class="asi-fieldset asi-ai-panel" data-provider="<?php echo esc_attr( $provider ); ?>" <?php echo $chosen === $provider ? '' : 'hidden'; ?>>
						<legend><strong><?php echo esc_html( 'custom' === $provider ? 'Otra IA' : $info['label'] ); ?></strong>
							<?php echo $hint ? '<span class="asi-tag asi-tag--ok">Clave guardada · termina en ' . esc_html( $hint ) . '</span>' : '<span class="asi-tag asi-tag--soft">Sin clave</span>'; ?></legend>

						<?php if ( 'custom' === $provider ) : ?>
							<p class="asi-muted">Sirve para cualquier IA que se conecte igual que ChatGPT (casi todas): DeepSeek, Grok de xAI, Mistral, OpenRouter, Together y otras. En su documentación lo dicen como “compatible con OpenAI”.</p>
						<?php endif; ?>

						<ol class="asi-ai-steps">
							<li><strong>Crea una cuenta.</strong>
								<?php if ( 'custom' === $provider ) : ?>
									Si todavía no tienes, créala en la página de la IA que quieras usar.
								<?php else : ?>
									Si todavía no tienes cuenta en <?php echo esc_html( $info['label'] ); ?>, créala aquí: <a href="<?php echo esc_url( $info['signup'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( preg_replace( '#^https?://([^/]+).*$#', '$1', $info['signup'] ) ); ?> <span class="dashicons dashicons-external" aria-hidden="true"></span></a>
								<?php endif; ?>
								<?php if ( $info['paid'] ) : ?>
									<br><span class="asi-muted">Es de pago: se cobra por uso con saldo que cargas en la cuenta. <?php echo 'custom' === $provider ? '' : 'Ojo: la suscripción mensual (' . ( 'openai' === $provider ? 'ChatGPT Plus' : 'Claude Pro' ) . ') no sirve para esto; la API se paga aparte. '; ?>Te recomendamos poner también un límite de gasto mensual en su panel.</span>
								<?php else : ?>
									<br><span class="asi-muted">Tiene plan gratis: no necesitas tarjeta.</span>
								<?php endif; ?></li>
							<li><strong>Crea tu clave.</strong> Si ya tienes cuenta, entra
								<?php if ( 'custom' !== $provider ) : ?>a <a href="<?php echo esc_url( $info['console'] ); ?>" target="_blank" rel="noopener noreferrer">su página de claves <span class="dashicons dashicons-external" aria-hidden="true"></span></a><?php else : ?>a su panel administrativo<?php endif; ?>.
								<?php echo esc_html( $info['where'] ); ?> Copia la clave. <strong>No la compartas con nadie</strong>: quien la tenga puede gastar de tu cuenta.</li>
							<?php if ( 'custom' === $provider ) : ?>
								<li><strong>Dirección de la API.</strong> Está en su documentación; casi siempre termina en <code>/v1</code>.
									<p><label>Nombre <span class="asi-muted">(para reconocerla en los avisos)</span><br><input type="text" class="regular-text" name="ai_custom[name]" value="<?php echo esc_attr( $custom['name'] ); ?>" placeholder="DeepSeek"></label></p>
									<p><label>Dirección<br><input type="url" class="large-text" name="ai_custom[url]" value="<?php echo esc_attr( $custom['url'] ); ?>" placeholder="https://api.deepseek.com/v1"></label></p>
									<p class="asi-muted">Ejemplos: DeepSeek <code>https://api.deepseek.com/v1</code> · Grok (xAI) <code>https://api.x.ai/v1</code> · Mistral <code>https://api.mistral.ai/v1</code> · OpenRouter <code>https://openrouter.ai/api/v1</code></p></li>
							<?php endif; ?>
							<li><strong>Pégala aquí.</strong>
								<p><label>Clave <span class="asi-muted">(se guarda en el sitio y no se vuelve a mostrar; deja vacío para conservar la actual)</span><br>
									<input type="password" class="regular-text" name="ai_key[<?php echo esc_attr( $provider ); ?>]" value="" autocomplete="new-password" spellcheck="false"></label>
									<?php if ( $hint ) : ?><label><input type="checkbox" name="ai_drop[<?php echo esc_attr( $provider ); ?>]" value="1"> Borrar la clave</label><?php endif; ?></p></li>
							<li><strong>Modelos<?php echo 'custom' === $provider ? '' : ' (opcional)'; ?>.</strong>
								<?php if ( 'custom' === $provider ) : ?>
									Escribe el nombre del modelo tal como lo dice su página (por ejemplo <code>deepseek-chat</code>). Si pones varios, separados por comas, se usan en ese orden: cuando uno se agota, sigue el siguiente.
								<?php else : ?>
									Si lo dejas vacío, el chat los elige solo. Si quieres escoger, escribe los modelos separados por comas en el orden en que quieres que se usen: cuando uno llega a su límite del día, se usa el siguiente.
								<?php endif; ?>
								<p><input type="text" class="large-text" name="ai_models[<?php echo esc_attr( $provider ); ?>]" value="<?php echo esc_attr( $typed ); ?>" placeholder="<?php echo esc_attr( 'custom' === $provider ? 'deepseek-chat' : 'Automático: ' . implode( ', ', ASI_AI::models_for( $provider ) ) ); ?>"></p>
								<?php if ( 'custom' !== $provider && $auto && ASI_AI::has_key( $provider ) ) : ?>
									<p class="asi-muted">Ahora usa: <code><?php echo esc_html( implode( ', ', ASI_AI::models_for( $provider ) ) ); ?></code></p>
								<?php endif; ?>
								<?php if ( ! empty( ASI_AI::RECOMMENDED[ $provider ] ) ) : ?>
									<details class="asi-models-help">
										<summary>¿Cuáles puedo usar?</summary>
										<p class="asi-muted">Para esto basta un modelo pequeño y barato: la IA solo interpreta el mensaje y responde unas pocas palabras. En <?php echo esc_html( $info['label'] ); ?> te sugerimos estos:</p>
										<table class="asi-table">
											<thead><tr><th>Modelo</th><th>Para qué</th><th>Precio por millón de tokens (entrada / salida)</th><th>Por cada 1,000 consultas</th></tr></thead>
											<tbody>
											<?php foreach ( ASI_AI::RECOMMENDED[ $provider ] as $model ) : ?>
												<tr><td><code><?php echo esc_html( $model[0] ); ?></code></td><td><?php echo esc_html( $model[1] ); ?></td><td><?php echo esc_html( $model[2] ); ?></td><td><?php echo esc_html( $model[3] ); ?></td></tr>
											<?php endforeach; ?>
											</tbody>
										</table>
										<p class="asi-muted">Precios de lista al 13 nov 2026<?php echo $info['paid'] ? '' : ' (solo si llegas a pagar el plan; en el gratis no se cobra)'; ?>. Para ver los modelos que tiene tu cuenta usa <strong>Probar la conexión</strong>; la lista completa está en <a href="<?php echo esc_url( ASI_AI::MODELS_PAGE[ $provider ] ); ?>" target="_blank" rel="noopener">su página de modelos</a>.</p>
									</details>
								<?php else : ?>
									<p><label>Precio de ese modelo por millón de tokens, en dólares <span class="asi-muted">(opcional, para el tope diario; si lo dejas vacío se calcula con US$2 / US$8, alto a propósito)</span><br>
										entrada <input type="text" inputmode="decimal" class="small-text" name="ai_custom[price_in]" value="<?php echo esc_attr( (string) $custom['price_in'] ); ?>" placeholder="0.27">
										salida <input type="text" inputmode="decimal" class="small-text" name="ai_custom[price_out]" value="<?php echo esc_attr( (string) $custom['price_out'] ); ?>" placeholder="1.10"></label></p>
								<?php endif; ?></li>
							<li><strong>Guarda</strong> y luego <strong>prueba la conexión</strong>.
								<?php if ( $hint ) : ?>
									<button type="submit" class="button" form="asi-ai-test-<?php echo esc_attr( $provider ); ?>"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> Probar la conexión</button>
									<span class="asi-muted">Hace una consulta real con “pero dame la cuoa de cada uno”.</span>
								<?php else : ?>
									<span class="asi-muted">El botón para probar aparece cuando guardes la clave.</span>
								<?php endif; ?>
								<?php if ( is_array( $test ) && $test['provider'] === $provider ) : ?>
									<p class="asi-note <?php echo $test['ok'] ? 'asi-note--ok' : 'asi-note--warn'; ?>"><?php echo esc_html( $test['text'] ); ?></p>
									<?php if ( $test['models'] ) : ?>
										<details class="asi-details"><summary>Modelos disponibles en tu cuenta (<?php echo (int) count( $test['models'] ); ?>)</summary><p><code><?php echo esc_html( implode( ', ', $test['models'] ) ); ?></code></p></details>
									<?php endif; ?>
								<?php endif; ?></li>
						</ol>
					</fieldset>
				<?php endforeach; ?>

				<p class="asi-muted">La IA elegida es la que trabaja. Si guardaste claves de otras, quedan de respaldo: cuando se acaban los modelos de la elegida, se usan esas antes de quedarse sin IA.</p>

				<p><label><strong>Tope de gasto diario</strong> en este sitio: US$ <input type="text" inputmode="decimal" class="small-text" name="ai_daily_usd" value="<?php echo esc_attr( number_format( $settings['daily_usd'], 2, '.', '' ) ); ?>"></label>
					<span class="asi-muted">(0 = sin tope)</span><br>
					<span class="asi-muted">Cuando el gasto de hoy llega a este monto, la IA se apaga hasta mañana y el chat sigue sin ella. Es un cálculo con lo que la IA dice que usó y el precio de lista del modelo; tu factura real la ves en la cuenta de la IA. El día cambia a la medianoche del sitio (<?php echo esc_html( wp_timezone_string() ); ?>).</span></p>
				<p class="asi-actions"><?php submit_button( 'Guardar', 'primary', 'submit', false ); ?></p>
			</form>
			<script>
			( function () {
				var form = document.querySelector( '.asi-ai-form' );
				if ( ! form ) { return; }
				form.addEventListener( 'change', function ( e ) {
					if ( 'ai_provider' !== e.target.name ) { return; }
					form.querySelectorAll( '.asi-ai-panel' ).forEach( function ( panel ) { panel.hidden = panel.getAttribute( 'data-provider' ) !== e.target.value; } );
					form.querySelectorAll( '.asi-ai-provider' ).forEach( function ( tile ) { tile.classList.toggle( 'is-active', tile.contains( e.target ) ); } );
				} );
			} )();
			</script>
		<?php $this->card_close(); ?>

		<?php $this->card_open( 'Uso de hoy', 'dashicons-chart-bar', 'blue' ); ?>
			<p>Gasto estimado hoy: <strong>US$<?php echo esc_html( number_format( $usage['cost'], $usage['cost'] < 1 ? 4 : 2 ) ); ?></strong><?php echo $settings['daily_usd'] > 0 ? ' de US$' . esc_html( number_format( $settings['daily_usd'], 2 ) ) : ' <span class="asi-muted">(sin tope)</span>'; ?>
				· consultas: <strong><?php echo (int) $usage['calls']; ?></strong>
				<?php if ( $usage['calls'] ) : ?><span class="asi-muted"> · promedio <?php echo (int) round( $usage['ms'] / $usage['calls'] ); ?> ms · <?php echo (int) $usage['tokens']; ?> tokens</span><?php endif; ?></p>
			<?php if ( $usage['by'] ) : ?>
				<ul><?php foreach ( $usage['by'] as $model => $n ) : ?><li><code><?php echo esc_html( $model ); ?></code>: <?php echo (int) $n; ?></li><?php endforeach; ?></ul>
			<?php endif; ?>
			<?php if ( $blocked ) : ?>
				<p><strong>En pausa</strong> <span class="asi-muted">(llegaron a su límite o fallaron; se vuelven a probar solos)</span></p>
				<ul><?php foreach ( $blocked as $what => $until ) : ?><li><code><?php echo esc_html( $what ); ?></code> hasta las <?php echo esc_html( wp_date( 'H:i', $until ) ); ?></li><?php endforeach; ?></ul>
			<?php endif; ?>
			<?php if ( $usage['errors'] ) : ?>
				<details class="asi-details"><summary>Detalle técnico de los últimos errores (<?php echo (int) count( $usage['errors'] ); ?>)</summary><ul>
					<?php foreach ( $usage['errors'] as $error ) : ?><li><?php echo esc_html( wp_date( 'd/m H:i', $error['time'] ) . ' · ' . $error['where'] . ' · ' . $error['text'] ); ?></li><?php endforeach; ?>
				</ul></details>
			<?php endif; ?>
		<?php $this->card_close(); ?>

		<?php $this->card_open( 'Lo que aprendió la IA', 'dashicons-lightbulb', 'amber' ); ?>
			<p class="asi-muted">Todo lo que la IA interpretó queda guardado y se aplica solo (la próxima vez no gasta consultas, y sigue funcionando aunque se acaben los modelos del día). Se revisa, corrige o quita en <a href="<?php echo esc_url( $this->tab_url( 'aprendizaje', array( 'ver' => 'ia' ) ) ); ?>">Aprendizaje → Aprendido de la IA</a> (<?php echo (int) count( ASI_AI::cache() ); ?> en total).</p>
		<?php $this->card_close(); ?>
		<?php
	}

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
		foreach ( array( 'ver', 'pag', 'buscar' ) as $arg ) {
			if ( isset( $_GET[ $arg ] ) && '' !== $_GET[ $arg ] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				echo '<input type="hidden" name="asi_' . esc_attr( $arg ) . '" value="' . esc_attr( sanitize_text_field( wp_unslash( $_GET[ $arg ] ) ) ) . '">'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}
	}

	// ---------- Listas largas: 5 a la vista y "Ver todas" con páginas y buscador ----------

	const LIST_PREVIEW  = 3;
	const LIST_PER_PAGE = 20;

	/** ¿Se está viendo esta lista completa? */
	private function list_open() {
		return isset( $_GET['ver'] ) ? sanitize_key( wp_unslash( $_GET['ver'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Las filas a mostrar: 5 en la pestaña; en "Ver todas", filtradas por el buscador y de 20 en 20.
	 *
	 * @param string   $key      Lista.
	 * @param array    $rows     Todas las filas.
	 * @param callable $haystack function( $row ): string — texto en el que busca el buscador.
	 * @return array { rows, total, page, pages, query, full }
	 */
	private function list_slice( $key, array $rows, callable $haystack ) {
		$full = $this->list_open() === $key;
		if ( ! $full ) {
			return array( 'rows' => array_slice( $rows, 0, self::LIST_PREVIEW, true ), 'total' => count( $rows ), 'page' => 1, 'pages' => 1, 'query' => '', 'full' => false );
		}
		$query = isset( $_GET['buscar'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['buscar'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $query ) {
			$needle = ASI_Normalizer::normalize( $query );
			$rows   = array_filter(
				$rows,
				function ( $row ) use ( $haystack, $needle ) {
					return false !== strpos( ASI_Normalizer::normalize( (string) call_user_func( $haystack, $row ) ), $needle );
				}
			);
		}
		$total = count( $rows );
		$pages = max( 1, (int) ceil( $total / self::LIST_PER_PAGE ) );
		$page  = isset( $_GET['pag'] ) ? min( $pages, max( 1, (int) $_GET['pag'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return array( 'rows' => array_slice( $rows, ( $page - 1 ) * self::LIST_PER_PAGE, self::LIST_PER_PAGE, true ), 'total' => $total, 'page' => $page, 'pages' => $pages, 'query' => $query, 'full' => true );
	}

	/** Buscador de una lista completa. */
	private function list_search( $tab, $key, array $list, $placeholder ) {
		if ( ! $list['full'] ) {
			return;
		}
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="asi-list-search">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
			<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
			<input type="hidden" name="ver" value="<?php echo esc_attr( $key ); ?>">
			<input type="search" name="buscar" class="regular-text" value="<?php echo esc_attr( $list['query'] ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>">
			<button class="button">Buscar</button>
			<?php if ( '' !== $list['query'] ) : ?>
				<a href="<?php echo esc_url( $this->tab_url( $tab, array( 'ver' => $key ) ) ); ?>">Quitar búsqueda</a>
			<?php endif; ?>
			<a class="button button-primary asi-list-back" href="<?php echo esc_url( $this->tab_url( $tab ) ); ?>"><span class="dashicons dashicons-arrow-left-alt" aria-hidden="true"></span> Volver a <?php echo esc_html( self::TABS[ $tab ] ?? $tab ); ?></a>
		</form>
		<?php
	}

	/** "Ver todas (N)" en la pestaña, o las páginas en la lista completa. */
	private function list_footer( $tab, $key, array $list ) {
		if ( ! $list['full'] ) {
			if ( $list['total'] > self::LIST_PREVIEW ) {
				echo '<p class="asi-actions"><a class="button" href="' . esc_url( $this->tab_url( $tab, array( 'ver' => $key ) ) ) . '">Ver todas (' . (int) $list['total'] . ')</a></p>';
			}
			return;
		}
		echo '<p class="asi-pager">';
		$args = array( 'ver' => $key, 'buscar' => $list['query'] );
		if ( $list['page'] > 1 ) {
			echo '<a class="button" href="' . esc_url( $this->tab_url( $tab, array_merge( $args, array( 'pag' => $list['page'] - 1 ) ) ) ) . '">← Anterior</a> ';
		}
		echo '<span class="asi-muted">Página ' . (int) $list['page'] . ' de ' . (int) $list['pages'] . ' · ' . (int) $list['total'] . ' en total</span>';
		if ( $list['page'] < $list['pages'] ) {
			echo ' <a class="button" href="' . esc_url( $this->tab_url( $tab, array_merge( $args, array( 'pag' => $list['page'] + 1 ) ) ) ) . '">Siguiente →</a>';
		}
		echo '</p>';
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
