<?php
/**
 * Índice simulado en memoria para probar el motor y el diálogo sin WordPress.
 */

class ASI_Test_Repository implements ASI_Repository {

	public $rows = array();

	/** @var array<int,array> post_id => facts, text */
	public $details = array();

	/** @var array<string,array<int,int>> clave de búsqueda => post_id => clics */
	public $clicks = array();

	public function add( $id, $lang, $role, $title, array $facets, $body = '', array $codes = array(), array $card = array() ) {
		$terms = array();
		$lines = array();
		foreach ( $facets as $kind => $names ) {
			foreach ( (array) $names as $name ) {
				$terms[ ASI_Lexicon::normal_form( $name ) ] = true;
				$lines[]                                   = $kind . ':' . $name;
			}
		}
		foreach ( $codes as $code ) {
			$terms[ ASI_Lexicon::normal_form( $code ) ] = true;
		}
		$categories = isset( $facets['category'] ) ? (array) $facets['category'] : array();
		$brands     = isset( $facets['brand'] ) ? (array) $facets['brand'] : array();

		$this->rows[ $id ] = array(
			'post_id'    => $id,
			'post_type'  => 'catalog' === $role ? 'producto' : 'page',
			'role'       => $role,
			'lang'       => $lang,
			'title'      => $title,
			'title_norm' => ASI_Lexicon::normal_form( $title ),
			'terms_norm' => implode( ' | ', array_keys( $terms ) ),
			'facets'     => implode( "\n", $lines ),
			'codes'      => implode( ' ', array_map( array( 'ASI_Normalizer', 'compact_code' ), $codes ) ),
			'body_norm'  => ASI_Lexicon::normal_form( $body ),
			'card'       => array_merge(
				array(
					'title'    => $title,
					'url'      => 'https://example.test/' . $lang . '/' . $id,
					'summary'  => $body,
					'category' => $categories ? $categories[0] : '',
					'categories' => $categories,
					'brand'    => $brands ? $brands[0] : '',
					'code'     => $codes ? $codes[0] : '',
					'price'    => '',
					'specs'    => array(),
					'docs'     => array(),
					'flags'    => array(),
				),
				$card
			),
		);
	}

	public function candidates( $lang, array $words, array $codes, array $roles, $limit ) {
		$out = array();
		foreach ( $this->rows as $row ) {
			if ( ( $row['lang'] !== $lang && '' !== $row['lang'] ) || ! in_array( $row['role'], $roles, true ) ) {
				continue;
			}
			$haystack = ' ' . $row['title_norm'] . ' ' . str_replace( '|', ' ', $row['terms_norm'] ) . ' ' . $row['body_norm'] . ' ';
			$hit      = false;
			foreach ( $words as $word ) {
				// Como FULLTEXT en modo booleano con prefijo: "palabra*".
				$hit = $hit || false !== strpos( $haystack, ' ' . $word );
			}
			foreach ( $codes as $code ) {
				$hit = $hit || false !== strpos( ' ' . $row['codes'], ' ' . $code );
			}
			if ( $hit ) {
				$out[] = $row;
			}
		}
		return array_slice( $out, 0, $limit );
	}

	public function rows( $lang, array $ids ) {
		$out = array();
		foreach ( $ids as $id ) {
			if ( isset( $this->rows[ $id ] ) && $this->rows[ $id ]['lang'] === $lang ) {
				$out[] = $this->rows[ $id ];
			}
		}
		return $out;
	}

	public function catalog( $lang, $limit ) {
		return array_slice(
			array_values(
				array_filter(
					$this->rows,
					function ( $row ) use ( $lang ) {
						return $row['lang'] === $lang && 'catalog' === $row['role'];
					}
				)
			),
			0,
			$limit
		);
	}

	public function by_post_types( $lang, array $types, $limit ) {
		$out = array();
		foreach ( $this->rows as $row ) {
			if ( $row['lang'] === $lang && in_array( $row['post_type'], $types, true ) ) {
				$out[] = $row;
			}
		}
		usort(
			$out,
			function ( $a, $b ) {
				return strcmp( $a['title'], $b['title'] );
			}
		);
		return array_slice( $out, 0, $limit );
	}

	/**
	 * Datos de la ficha de un post de prueba.
	 */
	public function set_details( $id, array $facts, $text = null ) {
		$this->details[ $id ] = array(
			'facts' => $facts,
			'text'  => null === $text ? ( isset( $this->rows[ $id ] ) ? $this->rows[ $id ]['card']['summary'] : '' ) : $text,
		);
		$lines = array();
		foreach ( $facts as $fact ) {
			$lines[] = 'fact:' . $fact[0];
		}
		$this->rows[ $id ]['facets'] = trim( $this->rows[ $id ]['facets'] . "\n" . implode( "\n", $lines ) );
	}

	public function details( $lang, array $ids ) {
		$out = array();
		foreach ( $ids as $id ) {
			if ( isset( $this->rows[ $id ] ) ) {
				$out[ $id ] = isset( $this->details[ $id ] ) ? $this->details[ $id ] : array( 'facts' => array(), 'text' => $this->rows[ $id ]['card']['summary'] );
			}
		}
		return $out;
	}

	public function boosts( $lang, $key ) {
		return isset( $this->clicks[ $key ] ) ? $this->clicks[ $key ] : array();
	}

	public function vocabulary( $lang ) {
		return ASI_Vocabulary::build(
			array_filter(
				$this->rows,
				function ( $row ) use ( $lang ) {
					return $row['lang'] === $lang;
				}
			)
		);
	}
}

/**
 * Sitio de prueba parecido a Grupo Delta: El Salvador en español, más filas de otros contextos
 * que nunca deben aparecer.
 */
function asi_test_delta_repository() {
	$r = new ASI_Test_Repository();

	$r->add( 1, 'es-sv', 'catalog', 'Montacargas UN Forklifts FGL25T', array( 'category' => array( 'Montacargas', 'Manejo de carga', 'Venta' ), 'brand' => array( 'UN Forklifts' ) ), 'Montacargas a combustión diésel con capacidad de 2.5 toneladas.', array( 'FGL25T' ) );
	$r->add( 2, 'es-sv', 'catalog', 'Montacargas eléctrico UN Forklifts FB20', array( 'category' => array( 'Montacargas', 'Manejo de carga', 'Venta' ), 'brand' => array( 'UN Forklifts' ) ), 'Montacargas eléctrico con batería de litio de 48V y capacidad de 2 toneladas.', array( 'FB20' ) );
	$r->add( 3, 'es-sv', 'catalog', 'Tractor John Deere 5075E', array( 'category' => array( 'Tractores', 'Maquinaria agrícola', 'Venta' ), 'brand' => array( 'John Deere' ) ), 'Tractor utilitario de 75 HP para trabajo agrícola.', array( '5075E' ), array( 'specs' => array( array( 'label' => 'Potencia', 'value' => '75 HP' ) ) ) );
	$r->add( 4, 'es-sv', 'catalog', 'Tractor John Deere 6110J', array( 'category' => array( 'Tractores', 'Maquinaria agrícola', 'Venta' ), 'brand' => array( 'John Deere' ) ), 'Tractor de 110 HP con cabina.', array( '6110J' ) );
	$r->add( 5, 'es-sv', 'catalog', 'Excavadora Hyundai HX220', array( 'category' => array( 'Excavadoras', 'Construcción', 'Venta' ), 'brand' => array( 'Hyundai' ) ), 'Excavadora hidráulica de 22 toneladas.', array( 'HX220' ) );
	$r->add( 6, 'es-sv', 'catalog', 'Renta de montacargas', array( 'category' => array( 'Montacargas', 'Manejo de carga', 'Renta' ) ), 'Renta de montacargas por día, semana o mes.' );
	$r->add( 7, 'es-sv', 'catalog', 'Filtro de aceite John Deere', array( 'category' => array( 'Repuestos' ), 'brand' => array( 'John Deere' ) ), 'Filtro de aceite original para tractores John Deere.', array( 'RE504836' ) );

	$r->add( 10, 'es-sv', 'info', 'Contáctenos', array(), 'Escríbenos o llámanos por teléfono o WhatsApp.' );
	$r->add( 11, 'es-sv', 'info', 'Sucursales', array(), 'Nuestras sucursales en San Salvador y Santa Ana.' );
	$r->add( 12, 'es-sv', 'info', 'Nosotros', array(), 'Más de 100 años de historia.' );
	$r->add( 13, 'es-sv', 'info', 'Garantía', array(), 'Todos nuestros equipos tienen garantía de fábrica y servicio técnico.' );

	// Otros contextos: nunca deben aparecer en es-sv.
	$r->add( 20, 'en-sv', 'catalog', 'UN Forklifts FB20 Forklift', array( 'category' => array( 'Forklifts' ) ), 'Electric forklift.', array( 'FB20' ) );
	$r->add( 30, 'es-gt', 'catalog', 'Montacargas eléctrico Guatemala', array( 'category' => array( 'Montacargas' ) ), 'Solo en Guatemala.' );

	return $r;
}

function asi_test_dialogue( ASI_Repository $repository, $lang = 'es-sv', $language = 'es' ) {
	$seed = 0;
	return new ASI_Dialogue(
		$repository,
		array(
			'lang'          => $lang,
			'language'      => $language,
			'site_name'     => 'Grupo Prueba',
			'country_name'  => 'El Salvador',
			'multi_country' => true,
			'hour'          => 10,
			'home_url'      => 'https://example.test/' . $lang . '/',
			'siblings'      => array( 'en' => 'https://example.test/en-sv/' ),
		),
		// Aleatorio determinista para que las pruebas sean repetibles.
		function ( $max ) use ( &$seed ) {
			return $seed++ % ( $max + 1 );
		}
	);
}

function asi_card_ids( array $response ) {
	return array_column( $response['cards'], 'id' );
}

function asi_link_urls( array $response ) {
	return array_column( $response['links'], 'url' );
}
