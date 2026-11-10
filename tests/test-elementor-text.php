<?php
$doc = json_encode(
	array(
		array(
			'elType'   => 'container',
			'settings' => array( 'background_color' => '#fff', 'css_classes' => 'x' ),
			'elements' => array(
				array( 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Montacargas eléctrico', 'title_color' => '#000', 'link' => array( 'url' => 'https://x' ) ) ),
				array( 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => array( 'editor' => '<p>Capacidad de <strong>2.5 t</strong></p><p>Batería de litio</p>', 'typography_font_size' => array( 'size' => 16 ) ) ),
				array( 'elType' => 'widget', 'widgetType' => 'tabs', 'settings' => array( 'tabs' => array( array( 'tab_title' => 'Especificaciones', 'tab_content' => 'Motor 48V' ), array( 'tab_title' => 'Garantía', 'tab_content' => '2 años' ) ) ) ),
				array( 'elType' => 'widget', 'widgetType' => 'button', 'settings' => array( 'text' => 'Cotizar', 'button_text_color' => '#f00', 'size' => 'sm' ) ),
				array( 'elType' => 'widget', 'widgetType' => 'spacer', 'settings' => array( 'space' => array( 'size' => 40 ) ) ),
			),
		),
	)
);
check(
	'Elementor: texto visible sin estilos',
	'Montacargas eléctrico . Capacidad de 2.5 t Batería de litio . Especificaciones . Motor 48V . Garantía . 2 años . Cotizar',
	ASI_Elementor_Text::extract( $doc )
);
check( 'Elementor: JSON inválido', '', ASI_Elementor_Text::extract( 'no-json' ) );
// Ajustes de diseño con nombres que parecen texto (caso real de La Garza).
$noise = json_encode( array( array( 'elType' => 'container', 'settings' => array( 'flex_justify_content' => 'space-between', 'content_width' => 'full' ), 'elements' => array(
	array( 'elType' => 'widget', 'settings' => array( 'title' => 'Quiénes somos', 'text' => 'separador', 'align' => 'center' ) ),
) ) ) );
check( 'Elementor: sin ajustes de diseño', 'Quiénes somos', ASI_Elementor_Text::extract( $noise ) );
