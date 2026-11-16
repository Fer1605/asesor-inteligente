/**
 * Panel del Asesor: acomodo en rejilla (estilo de tablero) y recorrido guiado.
 *
 * El contenido de cada pantalla lo arma PHP como siempre; aquí solo se decide cuánto ancho ocupa
 * cada tarjeta y se juntan filtros y descargas de Reportería en una barra.
 *
 * El recorrido oscurece la pantalla y enciende una parte a la vez ("1 de 11 · Siguiente"). Pasa
 * por varias pantallas: al cambiar de pantalla guarda el paso y sigue al cargar la siguiente.
 * Se muestra solo la primera vez (por navegador) y se repite con "Ver el recorrido".
 */
( function () {
	const cfg = window.asiPanel || { tab: 'inicio', urls: {} };
	const caja = document.querySelector( '.asi-admin__tab' );
	if ( ! caja ) {
		return;
	}

	// ---------- Acomodo ----------
	const ANCHO = {
		inicio: { 'Visibilidad': 'c6 alto', 'Índice de búsqueda': 'c6 alto', 'Idiomas y países': 'c12' },
		reportes: { '¿El chat da resultados?': 'c5 alto', 'Día a día': 'c7 alto', 'Lo más buscado': 'c12', 'Lo más visto': 'c4', 'Lo más cotizado': 'c4', 'Lo más comparado': 'c4' },
		aprendizaje: { 'Aprendido de la IA': 'c8', 'Sinónimos propios': 'c4', 'Respuestas enseñadas': 'c6', 'Lo que más abren': 'c6' },
		contenido: { 'Qué significa cada opción': 'c4 alto', 'Precios y moneda': 'c8 alto', 'Sellos en las tarjetas': 'c12' },
		ia: { 'Usar IA': 'c4 alto', 'Uso de hoy': 'c4 alto', 'Lo que aprendió la IA': 'c4 alto', 'Conectar la IA': 'c12' },
		conexion: { 'Cotizar y comparar': 'c6 alto', 'Buscador del sitio': 'c6 alto', 'Páginas del chat': 'c12' },
		guia: { 'Cómo empezar': 'c6', 'Cómo funciona': 'c6', 'Qué le puede preguntar': 'c6', 'Cómo enseñarle': 'c6', 'Trucos y ajustes': 'c12' },
		probar: {},
	};
	const mapa = ANCHO[ cfg.tab ];
	if ( mapa ) {
		const rejilla = document.createElement( 'div' );
		rejilla.className = 'asi-rejilla';
		let barra = null;
		Array.prototype.slice.call( caja.children ).forEach( function ( el ) {
			if ( 'SCRIPT' === el.tagName ) {
				return;
			}
			if ( el.classList.contains( 'asi-grid' ) ) {
				Array.prototype.slice.call( el.children ).forEach( function ( hijo ) {
					rejilla.appendChild( hijo );
				} );
				el.remove();
				return;
			}
			// Reportería: filtros y descargas en una sola barra.
			if ( el.classList.contains( 'asi-report-filters' ) || el.classList.contains( 'asi-report-export' ) ) {
				if ( ! barra ) {
					barra = document.createElement( 'div' );
					barra.className = 'asi-herramientas';
					barra.innerHTML = '<div class="asi-herramientas__filtros"></div><div class="asi-herramientas__descargas"></div>';
					rejilla.appendChild( barra );
				}
				barra.querySelector( el.classList.contains( 'asi-report-filters' ) ? '.asi-herramientas__filtros' : '.asi-herramientas__descargas' ).appendChild( el );
				return;
			}
			rejilla.appendChild( el );
		} );
		Array.prototype.slice.call( rejilla.children ).forEach( function ( el ) {
			const titulo = el.querySelector( '.asi-panel__title' );
			const texto = titulo ? titulo.textContent : '';
			const clave = Object.keys( mapa ).find( function ( nombre ) {
				return texto.indexOf( nombre ) !== -1;
			} );
			if ( clave && el.classList.contains( 'asi-panel' ) ) {
				mapa[ clave ].split( ' ' ).forEach( function ( c ) {
					el.classList.add( 'asi-' + c );
				} );
			}
		} );
		caja.appendChild( rejilla );
	}
	document.querySelector( '.asi-admin' ).classList.add( 'asi-admin--listo' );

	// ---------- Recorrido ----------
	function panel( texto ) {
		const lista = Array.prototype.slice.call( document.querySelectorAll( '.asi-admin .asi-panel' ) );
		return lista.find( function ( p ) {
			const t = p.querySelector( '.asi-panel__title' );
			return t && t.textContent.indexOf( texto ) !== -1;
		} );
	}
	const PASOS = [
		{ tab: 'inicio', el: function () { return document.getElementById( 'toplevel_page_asesor-inteligente' ); }, t: 'Todo el Asesor está aquí', x: 'En el menú de WordPress, “Asesor inteligente” abre todas sus pantallas. Vamos una por una.' },
		{ tab: 'inicio', el: function () { return panel( 'Visibilidad' ); }, t: 'Quién ve el chat', x: 'Apagado, solo administradores para probar, o público para todos los visitantes.' },
		{ tab: 'reportes', el: function () { return document.querySelector( '.asi-herramientas' ); }, t: 'Reportería', x: 'Elige el periodo y si quieres ver todo, solo el chat o solo el sitio. Aquí también se descargan los reportes en PDF y los datos en Excel.' },
		{ tab: 'reportes', el: function () { return panel( '¿El chat da resultados?' ); }, t: '¿El chat da resultados?', x: 'La comparación entre quienes usan el chat y quienes buscan solos en el sitio: cuántos terminan cotizando o comparando.' },
		{ tab: 'reportes', el: function () { return panel( 'Lo más buscado' ); }, t: 'Lo más buscado', x: 'Por marca, tipo de vehículo, presupuesto, combustible y más. Debajo: lo más visto, cotizado y comparado.' },
		{ tab: 'probar', el: function () { return panel( 'Probar' ); }, t: 'Probar', x: 'Escribe como un cliente y mira cómo responde, sin que cuente en los reportes.' },
		{ tab: 'aprendizaje', el: function () { return panel( 'Aprendido de la IA' ); }, t: 'Aprendizaje', x: 'Cada búsqueda que la IA interpretó queda aquí, para revisarla o corregirla.' },
		{ tab: 'ia', el: function () { return panel( 'Usar IA' ); }, t: 'IA', x: 'Encenderla o apagarla, conectar la cuenta, el tope de gasto diario y los avisos.' },
		{ tab: 'apariencia', el: function () { return panel( 'Vista previa' ); }, t: 'Apariencia', x: 'Colores, botones, sugerencias, el asistente y la forma de hablar. Cada cambio se ve aquí al momento.' },
		{ tab: 'contacto', el: function () { return panel( 'Datos de contacto' ); }, t: 'Contacto', x: 'Los datos que el chat deja cuando el cliente se despide. Puedes poner varios separados por coma.' },
		{ tab: 'inicio', el: function () { return document.querySelector( '[data-asi-tour]' ); }, t: '¿Lo quieres ver otra vez?', x: 'El recorrido se repite desde este botón, en cualquier pantalla.' },
	];
	const CLAVE = 'asiTourPaso';
	const VISTO = 'asiTourVisto';
	const guardar = function ( k, v ) {
		try {
			null === v ? window.sessionStorage.removeItem( k ) : window.sessionStorage.setItem( k, v );
		} catch ( e ) {}
	};
	const leer = function ( k ) {
		try {
			return window.sessionStorage.getItem( k );
		} catch ( e ) {
			return null;
		}
	};
	const yaVisto = function () {
		try {
			return !! window.localStorage.getItem( VISTO );
		} catch ( e ) {
			return true;
		}
	};
	const marcarVisto = function () {
		try {
			window.localStorage.setItem( VISTO, '1' );
		} catch ( e ) {}
	};

	const foco = document.createElement( 'div' );
	foco.className = 'asi-tour-foco';
	const globo = document.createElement( 'div' );
	globo.className = 'asi-tour-globo';
	globo.setAttribute( 'role', 'dialog' );
	globo.innerHTML = '<div class="asi-tour-globo__paso"></div><h4></h4><p></p><div class="asi-tour-globo__puntos"></div>'
		+ '<div class="asi-tour-globo__botones"><button type="button" class="asi-tour-globo__saltar">Saltar recorrido</button>'
		+ '<span><button type="button" class="button asi-tour-atras">Anterior</button> <button type="button" class="button button-primary asi-tour-sig">Siguiente</button></span></div>';
	let i = 0;

	function cerrar() {
		foco.remove();
		globo.remove();
		guardar( CLAVE, null );
		marcarVisto();
	}

	function mostrar() {
		const paso = PASOS[ i ];
		if ( paso.tab !== cfg.tab && cfg.urls[ paso.tab ] ) {
			guardar( CLAVE, String( i ) );
			window.location.href = cfg.urls[ paso.tab ];
			return;
		}
		guardar( CLAVE, String( i ) );
		const el = paso.el();
		if ( ! el ) {
			return siguiente();
		}
		if ( ! foco.isConnected ) {
			document.body.append( foco, globo );
		}
		el.scrollIntoView( { block: 'center' } );
		window.setTimeout( function () {
			const r = el.getBoundingClientRect();
			const pad = 6;
			const alto = Math.min( r.height, window.innerHeight - 40 );
			const arriba = Math.max( 8, r.top );
			Object.assign( foco.style, { top: arriba - pad + 'px', left: r.left - pad + 'px', width: r.width + pad * 2 + 'px', height: alto + pad * 2 + 'px' } );
			globo.querySelector( '.asi-tour-globo__paso' ).textContent = ( i + 1 ) + ' de ' + PASOS.length;
			globo.querySelector( 'h4' ).textContent = paso.t;
			globo.querySelector( 'p' ).textContent = paso.x;
			globo.querySelector( '.asi-tour-globo__puntos' ).innerHTML = PASOS.map( function ( _, k ) {
				return '<i' + ( k === i ? ' class="on"' : '' ) + '></i>';
			} ).join( '' );
			globo.querySelector( '.asi-tour-atras' ).style.visibility = i ? 'visible' : 'hidden';
			globo.querySelector( '.asi-tour-sig' ).textContent = i === PASOS.length - 1 ? 'Terminar' : 'Siguiente';
			const g = globo.getBoundingClientRect();
			let top = arriba + alto + 16;
			let left = Math.min( Math.max( 16, r.left ), window.innerWidth - g.width - 16 );
			if ( top + g.height > window.innerHeight - 16 ) {
				top = Math.max( 16, r.top - g.height - 16 );
			}
			if ( top < 16 || r.width < 220 || alto > window.innerHeight * 0.6 ) {
				const medio = Math.min( Math.max( 16, r.top ), window.innerHeight - g.height - 16 );
				if ( r.right + g.width + 24 < window.innerWidth ) {
					left = r.right + 18;
					top = medio;
				} else if ( r.left - g.width - 24 > 0 ) {
					left = r.left - g.width - 18;
					top = medio;
				} else {
					left = Math.max( 16, r.left + 16 );
					top = Math.min( Math.max( 16, r.top + 16 ), window.innerHeight - g.height - 16 );
				}
			}
			globo.style.top = top + 'px';
			globo.style.left = left + 'px';
		}, 60 );
	}

	function siguiente() {
		if ( i >= PASOS.length - 1 ) {
			cerrar();
			if ( 'inicio' !== cfg.tab && cfg.urls.inicio ) {
				window.location.href = cfg.urls.inicio;
			}
			return;
		}
		i++;
		mostrar();
	}

	globo.querySelector( '.asi-tour-sig' ).addEventListener( 'click', siguiente );
	globo.querySelector( '.asi-tour-atras' ).addEventListener( 'click', function () {
		if ( i ) {
			i--;
			mostrar();
		}
	} );
	globo.querySelector( '.asi-tour-globo__saltar' ).addEventListener( 'click', cerrar );
	document.addEventListener( 'keydown', function ( e ) {
		if ( ! globo.isConnected ) {
			return;
		}
		if ( 'Escape' === e.key ) {
			cerrar();
		} else if ( 'ArrowRight' === e.key ) {
			siguiente();
		} else if ( 'ArrowLeft' === e.key && i ) {
			i--;
			mostrar();
		}
	} );
	window.addEventListener( 'resize', function () {
		if ( globo.isConnected ) {
			mostrar();
		}
	} );
	document.querySelectorAll( '[data-asi-tour]' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			i = 0;
			mostrar();
		} );
	} );

	const pendiente = leer( CLAVE );
	if ( null !== pendiente ) {
		i = Math.min( PASOS.length - 1, parseInt( pendiente, 10 ) || 0 );
		window.setTimeout( mostrar, 200 );
	} else if ( ! yaVisto() && 'inicio' === cfg.tab ) {
		window.setTimeout( mostrar, 500 );
	}
}() );
