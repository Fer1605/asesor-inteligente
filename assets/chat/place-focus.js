/**
 * Marca en la página del mapa la ubicación que se abrió desde el chat (?asi_place={id}).
 *
 * Funciona con cualquier plugin de mapas: busca el elemento de la lista que lleva el ID de la
 * ubicación (data-marker-id="12", id="marker-12"…) y, si no, el que tiene su nombre. Lo lleva a la
 * vista, lo resalta y lo activa como lo haría el visitante (pasar el mouse y hacer clic), que es
 * lo que usan los plugins para centrar el mapa y abrir el globo. Nunca sigue un enlace.
 * Las listas de los mapas se dibujan con JavaScript, así que se reintenta unos segundos.
 */
( function () {
	const config = window.asiPlaceFocus;
	if ( ! config || ! config.id ) {
		return;
	}
	const id = String( config.id );
	const title = normalize( config.title );
	const idPattern = new RegExp( '(^|[^0-9])' + id + '$' );
	const skipTags = { SCRIPT: 1, STYLE: 1, NOSCRIPT: 1, TEMPLATE: 1, HTML: 1, BODY: 1, HEAD: 1 };

	function normalize( text ) {
		return String( text || '' )
			.normalize( 'NFD' )
			.replace( /[̀-ͯ]/g, '' )
			.toLowerCase()
			.replace( /\s+/g, ' ' )
			.trim();
	}

	function visible( node ) {
		return !! ( node.offsetWidth || node.offsetHeight || node.getClientRects().length );
	}

	function hasId( node ) {
		for ( const attr of node.attributes ) {
			if ( attr.name.indexOf( 'data-' ) === 0 && attr.value === id ) {
				return true;
			}
			if ( 'id' === attr.name && idPattern.test( attr.value ) ) {
				return true;
			}
		}
		return false;
	}

	// El elemento de la lista que representa la ubicación (no la página entera).
	function byId() {
		let best = null;
		for ( const node of document.body.querySelectorAll( '*' ) ) {
			if ( skipTags[ node.tagName ] || ! visible( node ) || ! hasId( node ) ) {
				continue;
			}
			const text = normalize( node.textContent );
			if ( text.length > 1500 ) {
				continue;
			}
			// Se prefiere el que además muestra el nombre.
			if ( ! title || text.indexOf( title ) !== -1 ) {
				return node;
			}
			best = best || node;
		}
		return best;
	}

	function byTitle() {
		if ( ! title ) {
			return null;
		}
		for ( const node of document.body.querySelectorAll( 'h1,h2,h3,h4,h5,h6,strong,b,a,span,p,div,li,td' ) ) {
			if ( node.children.length > 2 || ! visible( node ) || node.closest( '.asi-chat' ) ) {
				continue;
			}
			if ( normalize( node.textContent ) === title ) {
				return item( node );
			}
		}
		return null;
	}

	// Sube del nombre a la tarjeta de la ubicación: el ancestro que se repite entre hermanos iguales.
	function item( node ) {
		let current = node;
		for ( let depth = 0; depth < 5 && current.parentElement && ! skipTags[ current.parentElement.tagName ]; depth++ ) {
			const parent = current.parentElement;
			const twins = Array.prototype.filter.call( parent.children, function ( child ) {
				return child !== current && child.tagName === current.tagName && child.className === current.className;
			} );
			if ( twins.length && normalize( current.textContent ).length < 1500 ) {
				return current;
			}
			current = parent;
		}
		return node;
	}

	function isLink( node ) {
		const link = node.closest( 'a[href]' );
		if ( ! link ) {
			return false;
		}
		const href = link.getAttribute( 'href' ) || '';
		return '' !== href && '#' !== href.charAt( 0 ) && 0 !== href.indexOf( 'javascript:' );
	}

	function focus( node ) {
		const color = config.color || '#2563eb';
		node.style.outline = '3px solid ' + color;
		node.style.outlineOffset = '4px';
		node.style.transition = 'outline-color .6s ease';
		node.setAttribute( 'data-asi-place', 'active' );
		node.scrollIntoView( { block: 'center', behavior: 'smooth' } );

		[ 'mouseover', 'mouseenter' ].forEach( function ( type ) {
			node.dispatchEvent( new MouseEvent( type, { bubbles: 'mouseover' === type } ) );
		} );
		if ( ! isLink( node ) ) {
			node.click();
		}

		// El resalte se queda hasta que el visitante interactúa con la página.
		const clear = function () {
			node.style.outlineColor = 'transparent';
			document.removeEventListener( 'pointerdown', clear, true );
		};
		window.setTimeout( function () {
			document.addEventListener( 'pointerdown', clear, true );
		}, 500 );
	}

	let tries = 0;
	function attempt() {
		const node = byId() || byTitle();
		if ( node ) {
			focus( node );
			return;
		}
		if ( ++tries < 40 ) {
			window.setTimeout( attempt, 300 );
		}
	}

	if ( 'complete' === document.readyState ) {
		attempt();
	} else {
		window.addEventListener( 'load', attempt );
	}
} )();
