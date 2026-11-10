<?php
/**
 * Mantiene el índice al día.
 *
 * - Al guardar, borrar o despublicar un post, actualiza solo su fila.
 * - El reindexado completo corre por lotes pequeños en WP-Cron, con pausa entre lotes,
 *   pensado para servidores lentos en producción: nunca recorre todo el sitio en una petición.
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || exit;

class ASI_Indexer {

	const CRON_DISCOVER = 'asi_discover';
	const CRON_BATCH    = 'asi_reindex_batch';
	const STATE_OPTION  = 'asi_reindex_state';

	/** Posts por lote y segundos entre lotes. Ajustables por filtro. */
	const BATCH_SIZE  = 20;
	const BATCH_DELAY = 30;
	const TIME_BUDGET = 8;

	/** @var ASI_Schema_Discovery */
	private $discovery;

	/** @var ASI_Document_Builder */
	private $builder;

	public function __construct( ASI_Schema_Discovery $discovery, ASI_Document_Builder $builder ) {
		$this->discovery = $discovery;
		$this->builder   = $builder;

		add_action( 'wp_after_insert_post', array( $this, 'on_post_saved' ), 20, 2 );
		add_action( 'deleted_post', array( $this, 'on_post_removed' ) );
		add_action( 'trashed_post', array( $this, 'on_post_removed' ) );
		add_action( 'set_object_terms', array( $this, 'on_terms_changed' ), 20, 1 );

		add_action( self::CRON_DISCOVER, array( $this, 'run_discovery' ) );
		add_action( self::CRON_BATCH, array( $this, 'run_batch' ) );
	}

	/**
	 * Descubre el esquema y arranca un reindexado completo.
	 */
	public function run_discovery() {
		$this->discovery->run();
		do_action( 'asi_discovered' );
		$this->start_full_reindex();
	}

	/**
	 * Programa el descubrimiento en segundo plano (no bloquea la activación).
	 */
	public static function schedule_discovery() {
		if ( ! wp_next_scheduled( self::CRON_DISCOVER ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_DISCOVER );
		}
	}

	/**
	 * Empieza (o reinicia) el reindexado completo desde el primer post.
	 */
	public function start_full_reindex() {
		update_option(
			self::STATE_OPTION,
			array(
				'running'    => true,
				'cursor'     => 0,
				'done'       => 0,
				'indexed'    => 0,
				'total'      => $this->count_candidates(),
				'started_at' => time(),
				'ended_at'   => 0,
			),
			false
		);
		wp_clear_scheduled_hook( self::CRON_BATCH );
		wp_schedule_single_event( time() + 1, self::CRON_BATCH );
	}

	/**
	 * Estado del reindexado para el panel.
	 *
	 * @return array
	 */
	public static function get_state() {
		$state = get_option( self::STATE_OPTION );
		return is_array( $state ) ? $state : array( 'running' => false, 'done' => 0, 'indexed' => 0, 'total' => 0, 'ended_at' => 0 );
	}

	/**
	 * Procesa un lote y programa el siguiente. Se detiene al agotar el tiempo del lote.
	 */
	public function run_batch() {
		$state = self::get_state();
		if ( empty( $state['running'] ) ) {
			return;
		}

		$schema = ASI_Schema_Discovery::get_schema();
		$ids    = $this->next_ids( (int) $state['cursor'], (int) apply_filters( 'asi_reindex_batch_size', self::BATCH_SIZE ) );
		$start  = microtime( true );

		foreach ( $ids as $post_id ) {
			$state['cursor'] = $post_id;
			$state['done']++;
			if ( $this->index_post( $post_id, $schema ) ) {
				$state['indexed']++;
			}
			if ( microtime( true ) - $start > self::TIME_BUDGET ) {
				break;
			}
		}

		if ( empty( $ids ) ) {
			ASI_Index_Table::purge_orphans( $this->enabled_post_types() );
			ASI_Index_Repository::flush_vocabulary();
			$state['running']  = false;
			$state['ended_at'] = time();
			update_option( self::STATE_OPTION, $state, false );
			do_action( 'asi_reindex_finished', $state );
			return;
		}

		update_option( self::STATE_OPTION, $state, false );
		wp_schedule_single_event( time() + (int) apply_filters( 'asi_reindex_batch_delay', self::BATCH_DELAY ), self::CRON_BATCH );
	}

	/**
	 * Indexa (o saca del índice) un post. Devuelve true si quedó indexado.
	 *
	 * @param int        $post_id Post.
	 * @param array|null $schema  Esquema (se lee si no se pasa).
	 * @param bool       $force   Reindexar aunque la firma no haya cambiado.
	 * @return bool
	 */
	public function index_post( $post_id, $schema = null, $force = false ) {
		$schema = null === $schema ? ASI_Schema_Discovery::get_schema() : $schema;
		$post   = get_post( $post_id );

		if ( ! $post || empty( $schema['post_types'] ) || ! $this->builder->is_indexable( $post, $schema ) ) {
			ASI_Index_Table::delete( $post_id );
			return false;
		}
		if ( ! $force && ASI_Index_Table::get_signature( $post_id ) === $this->builder->signature( $post, $schema ) ) {
			return true;
		}

		$row = $this->builder->build( $post, $schema );
		// El vocabulario de ese idioma se recalcula en la próxima búsqueda
		// (en el reindexado completo se borra una sola vez, al terminar).
		if ( ! doing_action( self::CRON_BATCH ) ) {
			ASI_Index_Repository::flush_vocabulary( $row['lang'] );
		}
		return ASI_Index_Table::upsert( $row );
	}

	/**
	 * Al guardar un post. wp_after_insert_post corre después de que el plugin multilingüe,
	 * las taxonomías y ACF ya guardaron lo suyo.
	 *
	 * @param int     $post_id Post.
	 * @param WP_Post $post    Post.
	 */
	public function on_post_saved( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$schema = ASI_Schema_Discovery::get_schema();
		if ( empty( $schema['post_types'][ $post->post_type ] ) ) {
			return;
		}
		$this->index_post( $post_id, $schema, true );
	}

	/**
	 * Cambió el idioma o una categoría sin pasar por el guardado del post (p. ej. edición rápida).
	 *
	 * @param int $object_id Post.
	 */
	public function on_terms_changed( $object_id ) {
		if ( doing_action( 'wp_after_insert_post' ) || ! ASI_Index_Table::get_signature( $object_id ) ) {
			return;
		}
		$this->index_post( $object_id, null, true );
	}

	/**
	 * Post borrado o enviado a la papelera.
	 *
	 * @param int $post_id Post.
	 */
	public function on_post_removed( $post_id ) {
		ASI_Index_Table::delete( $post_id );
	}

	/**
	 * Siguientes IDs publicados de los tipos habilitados, en orden, desde el cursor.
	 *
	 * @param int $cursor Último ID procesado.
	 * @param int $limit  Cuántos.
	 * @return int[]
	 */
	private function next_ids( $cursor, $limit ) {
		global $wpdb;
		$types = $this->enabled_post_types();
		if ( empty( $types ) ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND ID > %d AND post_type IN ({$placeholders}) ORDER BY ID ASC LIMIT %d",
					array_merge( array( $cursor ), $types, array( $limit ) )
				)
			)
		);
	}

	/**
	 * Total de posts a recorrer.
	 *
	 * @return int
	 */
	private function count_candidates() {
		global $wpdb;
		$types = $this->enabled_post_types();
		if ( empty( $types ) ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$placeholders})", $types ) );
	}

	/**
	 * Tipos habilitados en el esquema.
	 *
	 * @return string[]
	 */
	private function enabled_post_types() {
		$schema = ASI_Schema_Discovery::get_schema();
		$types  = array();
		foreach ( (array) ( isset( $schema['post_types'] ) ? $schema['post_types'] : array() ) as $post_type => $record ) {
			if ( ! empty( $record['enabled'] ) ) {
				$types[] = $post_type;
			}
		}
		return $types;
	}
}
