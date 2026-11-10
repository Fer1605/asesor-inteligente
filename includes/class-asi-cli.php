<?php
/**
 * Comandos WP-CLI: wp asesor status | discover | reindex | show <post_id>
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || exit;

class ASI_CLI {

	/** @var ASI_Plugin */
	private $plugin;

	public function __construct( ASI_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Muestra idiomas, países, esquema descubierto y estado del índice.
	 */
	public function status() {
		WP_CLI::log( 'Multilingüe: ' . $this->plugin->languages->adapter()->get_id() . ' · Modo: ' . ASI_Settings::mode() );

		$rows = array();
		foreach ( $this->plugin->languages->all() as $slug => $language ) {
			$rows[] = array(
				'idioma'  => $slug,
				'locale'  => $language['locale'],
				'país'    => $language['country'] ? strtoupper( $language['country'] ) . ' · ' . $language['country_name'] : '—',
				'defecto' => $language['is_default'] ? 'sí' : '',
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'idioma', 'locale', 'país', 'defecto' ) );

		$schema = ASI_Schema_Discovery::get_schema();
		$rows   = array();
		foreach ( (array) ( isset( $schema['post_types'] ) ? $schema['post_types'] : array() ) as $post_type => $type ) {
			$fields = array();
			foreach ( $type['fields'] as $name => $field ) {
				$fields[] = $name . ':' . $field['role'];
			}
			$rows[] = array(
				'tipo'        => $post_type,
				'rol'         => $type['role'],
				'publicados'  => $type['count'],
				'activo'      => $type['enabled'] ? 'sí' : 'no',
				'taxonomías'  => implode( ', ', array_map( function ( $tax, $info ) { return $tax . ':' . $info['role']; }, array_keys( $type['taxonomies'] ), $type['taxonomies'] ) ),
				'campos'      => implode( ', ', $fields ),
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'tipo', 'rol', 'publicados', 'activo', 'taxonomías', 'campos' ) );

		$state = ASI_Indexer::get_state();
		WP_CLI::log( sprintf( 'Índice: %d/%d recorridos, %d indexados%s', $state['done'], $state['total'], $state['indexed'], ! empty( $state['running'] ) ? ' (en curso)' : '' ) );
		WP_CLI\Utils\format_items( 'table', ASI_Index_Table::stats(), array( 'country', 'lang', 'role', 'total' ) );
	}

	/**
	 * Vuelve a descubrir la estructura del sitio (solo lectura).
	 */
	public function discover() {
		$this->plugin->languages->flush();
		$schema = $this->plugin->discovery->run();
		WP_CLI::success( sprintf( 'Esquema descubierto: %d tipos de contenido.', count( $schema['post_types'] ) ) );
	}

	/**
	 * Reindexa todo, por lotes.
	 *
	 * ## OPTIONS
	 *
	 * [--batch=<n>]
	 * : Posts por lote. Por defecto 20.
	 *
	 * [--pause=<segundos>]
	 * : Pausa entre lotes para no cargar el servidor. Por defecto 2.
	 *
	 * @param array $args       Argumentos.
	 * @param array $assoc_args Opciones.
	 */
	public function reindex( $args, $assoc_args ) {
		$batch = max( 1, (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'batch', 20 ) );
		$pause = max( 0, (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'pause', 2 ) );

		add_filter( 'asi_reindex_batch_size', function () use ( $batch ) { return $batch; } );
		$this->plugin->indexer->start_full_reindex();
		wp_clear_scheduled_hook( ASI_Indexer::CRON_BATCH );

		do {
			$this->plugin->indexer->run_batch();
			wp_clear_scheduled_hook( ASI_Indexer::CRON_BATCH );
			$state = ASI_Indexer::get_state();
			WP_CLI::log( sprintf( '%d/%d', $state['done'], $state['total'] ) );
			if ( ! empty( $state['running'] ) && $pause ) {
				sleep( $pause );
			}
		} while ( ! empty( $state['running'] ) );

		WP_CLI::success( sprintf( '%d posts indexados.', $state['indexed'] ) );
	}

	/**
	 * Muestra la fila del índice de un post (para revisar qué entendió el plugin).
	 *
	 * <post_id>
	 * : ID del post.
	 *
	 * @param array $args Argumentos.
	 */
	public function show( $args ) {
		global $wpdb;
		$this->plugin->indexer->index_post( (int) $args[0], null, true );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . ASI_Index_Table::name() . ' WHERE post_id = %d', (int) $args[0] ), ARRAY_A );
		if ( ! $row ) {
			WP_CLI::error( 'El post no es indexable.' );
		}
		$row['card']      = json_decode( $row['card'], true );
		$row['body_norm'] = substr( $row['body_norm'], 0, 400 ) . '…';
		WP_CLI::log( wp_json_encode( $row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}
}
