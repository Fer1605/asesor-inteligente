<?php
/**
 * Plugin Name:       Asesor Inteligente
 * Plugin URI:        https://github.com/Fer1605/asesor-inteligente
 * Description:       Asesor de ventas conversacional para WordPress, sin servicios externos de IA por defecto (interpretación con IA gratuita opcional, apagada). Descubre solo el contenido, los campos, los idiomas y los países (Polylang, WPML, ACF, WooCommerce, Elementor), recomienda según lo que necesita el cliente y se conecta con las acciones del sitio (cotizar, comparar).
 * Version:           0.3.0-dev.6
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            Fer Santizo
 * Author URI:        https://github.com/Fer1605
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       asesor-inteligente
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || exit;

define( 'ASI_VERSION', '0.3.0-dev.6' );
define( 'ASI_FILE', __FILE__ );
define( 'ASI_DIR', plugin_dir_path( __FILE__ ) );
define( 'ASI_URL', plugin_dir_url( __FILE__ ) );

require_once ASI_DIR . 'includes/class-asi-settings.php';
require_once ASI_DIR . 'includes/text/class-asi-normalizer.php';
require_once ASI_DIR . 'includes/text/class-asi-money.php';
require_once ASI_DIR . 'includes/i18n/class-asi-country-resolver.php';
require_once ASI_DIR . 'includes/i18n/class-asi-language-adapter.php';
require_once ASI_DIR . 'includes/i18n/class-asi-context.php';
require_once ASI_DIR . 'includes/discovery/class-asi-field-classifier.php';
require_once ASI_DIR . 'includes/discovery/class-asi-schema-discovery.php';
require_once ASI_DIR . 'includes/index/class-asi-index-table.php';
require_once ASI_DIR . 'includes/index/class-asi-elementor-text.php';
require_once ASI_DIR . 'includes/index/class-asi-document-builder.php';
require_once ASI_DIR . 'includes/index/class-asi-indexer.php';
require_once ASI_DIR . 'includes/discovery/class-asi-json-paths.php';
require_once ASI_DIR . 'includes/discovery/class-asi-whatsapp.php';
require_once ASI_DIR . 'includes/engine/class-asi-lexicon.php';
require_once ASI_DIR . 'includes/engine/class-asi-query-analyzer.php';
require_once ASI_DIR . 'includes/engine/class-asi-attributes.php';
require_once ASI_DIR . 'includes/engine/class-asi-search.php';
require_once ASI_DIR . 'includes/engine/class-asi-vocabulary.php';
require_once ASI_DIR . 'includes/index/class-asi-index-repository.php';
require_once ASI_DIR . 'includes/dialogue/class-asi-intent-classifier.php';
require_once ASI_DIR . 'includes/engine/class-asi-extractor.php';
require_once ASI_DIR . 'includes/dialogue/class-asi-tone.php';
require_once ASI_DIR . 'includes/dialogue/class-asi-responder.php';
require_once ASI_DIR . 'includes/dialogue/trait-asi-dialogue-products.php';
require_once ASI_DIR . 'includes/dialogue/trait-asi-dialogue-advice.php';
require_once ASI_DIR . 'includes/dialogue/trait-asi-dialogue-similar.php';
require_once ASI_DIR . 'includes/dialogue/trait-asi-dialogue-actions.php';
require_once ASI_DIR . 'includes/dialogue/class-asi-dialogue.php';
require_once ASI_DIR . 'includes/ai/class-asi-ai-interpreter.php';
require_once ASI_DIR . 'includes/ai/class-asi-ai.php';
require_once ASI_DIR . 'includes/class-asi-appearance.php';
require_once ASI_DIR . 'includes/class-asi-chat-page.php';
require_once ASI_DIR . 'includes/class-asi-search-integration.php';
require_once ASI_DIR . 'includes/class-asi-place-focus.php';
require_once ASI_DIR . 'includes/class-asi-learning.php';
require_once ASI_DIR . 'includes/api/class-asi-rest-controller.php';
require_once ASI_DIR . 'includes/extend/interface-asi-connector.php';
require_once ASI_DIR . 'includes/extend/interface-asi-pack.php';
require_once ASI_DIR . 'includes/extend/interface-asi-advisor.php';
require_once ASI_DIR . 'includes/extend/interface-asi-similarity.php';
require_once ASI_DIR . 'includes/extend/interface-asi-action-runner.php';
require_once ASI_DIR . 'includes/class-asi-mailer.php';
require_once ASI_DIR . 'includes/class-asi-actions.php';
require_once ASI_DIR . 'includes/extend/class-asi-extensions.php';

/**
 * Contenedor del plugin: crea las piezas una sola vez y las conecta.
 */
final class ASI_Plugin {

	/** @var ASI_Plugin|null */
	private static $instance = null;

	/** @var ASI_Language_Registry */
	public $languages;

	/** @var ASI_Schema_Discovery */
	public $discovery;

	/** @var ASI_Indexer */
	public $indexer;

	/** @var ASI_Chat_Page */
	public $chat_page;

	/**
	 * @return ASI_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Después de que Polylang/WPML/ACF/WooCommerce cargaron.
		add_action( 'plugins_loaded', array( $this, 'boot' ), 20 );
	}

	/**
	 * Arranca las piezas.
	 */
	public function boot() {
		ASI_Index_Table::maybe_install();

		$adapter         = ASI_Language_Adapter::detect();
		$this->languages = new ASI_Language_Registry( $adapter );
		$this->discovery = new ASI_Schema_Discovery( $adapter );
		$this->indexer   = new ASI_Indexer( $this->discovery, new ASI_Document_Builder( $this->languages ) );
		ASI_Learning::maybe_install();
		$this->maybe_reindex_after_update();

		$this->chat_page = new ASI_Chat_Page( $this );
		new ASI_REST_Controller( $this );
		ASI_Actions::register();
		new ASI_Search_Integration( $this );
		new ASI_Place_Focus();

		if ( is_admin() ) {
			require_once ASI_DIR . 'includes/admin/class-asi-admin.php';
			new ASI_Admin( $this );
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once ASI_DIR . 'includes/class-asi-cli.php';
			WP_CLI::add_command( 'asesor', new ASI_CLI( $this ) );
		}
	}

	/**
	 * Si cambió la forma de indexar (una versión nueva guarda más datos), se reindexa en segundo
	 * plano, por lotes, sin detener el sitio. Cuesta una opción ya cargada en memoria.
	 */
	private function maybe_reindex_after_update() {
		if ( (int) get_option( 'asi_doc_version' ) === ASI_Document_Builder::DOC_VERSION ) {
			return;
		}
		update_option( 'asi_doc_version', ASI_Document_Builder::DOC_VERSION, true );
		if ( ASI_Schema_Discovery::get_schema() ) {
			$this->indexer->start_full_reindex();
		}
	}

	/**
	 * Activación: crea la tabla y deja el descubrimiento programado en segundo plano.
	 * No recorre el sitio aquí para que activar sea instantáneo incluso en servidores lentos.
	 */
	public static function activate() {
		ASI_Index_Table::install();
		ASI_Learning::maybe_install();
		ASI_Settings::install_defaults();
		ASI_Indexer::schedule_discovery();
	}

	/**
	 * Desactivación: detiene las tareas programadas. Los datos se conservan.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( ASI_Indexer::CRON_DISCOVER );
		wp_clear_scheduled_hook( ASI_Indexer::CRON_BATCH );
		wp_clear_scheduled_hook( ASI_Indexer::CRON_PENDING );
	}
}

register_activation_hook( __FILE__, array( 'ASI_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ASI_Plugin', 'deactivate' ) );

ASI_Plugin::instance();
