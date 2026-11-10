<?php
ASI_Extensions::reset();
check( 'pack autos instalado', true, isset( ASI_Extensions::packs()['autos'] ) );
check( 'pack autos no aplica sin WordPress', array(), ASI_Extensions::active_packs() );
check( 'conector del tema instalado', true, isset( ASI_Extensions::connectors()['hello-child-pdf'] ) );
check( 'conector inactivo si el tema no tiene sus funciones', array(), ASI_Extensions::active_connectors() );
check( 'sin conector para cotizar → el chat manda al formulario del sitio', null, ASI_Extensions::connector_for( 'quote' ) );
