/**
 * Eikvakt Backup Manager — admin behaviour.
 *
 * Plain ES5, no build step. Progressive enhancement only: every form works
 * without JavaScript; this adds live progress, chunked uploads and a few
 * conveniences.
 */
( function () {
	'use strict';

	var cfg = window.ekbmAdmin || {};
	var i18n = cfg.i18n || {};

	function format( text ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var n = 0;
		return String( text ).replace( /%(\d+\$)?s/g, function ( match, pos ) {
			var index = pos ? parseInt( pos, 10 ) - 1 : n++;
			return args[ index ] !== undefined ? args[ index ] : '';
		} ).replace( /%%/g, '%' );
	}

	function post( data, onDone, onError ) {
		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', cfg.ajaxUrl );
		xhr.onload = function () {
			var json = null;
			try {
				json = JSON.parse( xhr.responseText );
			} catch ( e ) {}
			if ( xhr.status === 200 && json && json.success ) {
				onDone( json.data );
			} else if ( onError ) {
				onError( json && json.data && json.data.message ? json.data.message : 'HTTP ' + xhr.status );
			}
		};
		xhr.onerror = function () {
			if ( onError ) {
				onError( 'network' );
			}
		};
		xhr.send( data );
	}

	// -------------------------------------------------------------------------
	// Live progress (dashboard). Each poll also advances the job, so it
	// finishes even where loopback requests and WP-Cron are unavailable.
	// -------------------------------------------------------------------------
	var box = document.getElementById( 'ekbm-progress' );

	if ( box && ! box.hasAttribute( 'hidden' ) ) {
		var bar = document.getElementById( 'ekbm-progress-bar' );
		var title = document.getElementById( 'ekbm-progress-title' );
		var message = document.getElementById( 'ekbm-progress-message' );

		var poll = function () {
			var data = new FormData();
			data.append( 'action', 'ekbm_status' );
			data.append( 'nonce', cfg.nonce );

			post(
				data,
				function ( status ) {
					if ( status.running && status.job ) {
						bar.style.width = status.job.progress + '%';
						message.textContent = status.job.message || '';
						title.textContent = i18n.running + ( status.queued ? ' (+' + status.queued + ' ' + i18n.queued + ')' : '' );
						window.setTimeout( poll, 2500 );
					} else if ( status.queued ) {
						title.textContent = i18n.queued;
						window.setTimeout( poll, 2500 );
					} else {
						bar.style.width = '100%';
						message.textContent = i18n.done;
						window.setTimeout( function () {
							window.location.reload();
						}, 1200 );
					}
				},
				function () {
					message.textContent = i18n.error;
					window.setTimeout( poll, 8000 );
				}
			);
		};

		window.setTimeout( poll, 1000 );
	}

	// -------------------------------------------------------------------------
	// Chunked upload of backup set files (Backups screen).
	// -------------------------------------------------------------------------
	var input = document.getElementById( 'ekbm-upload-files' );

	if ( input ) {
		var statusLine = document.getElementById( 'ekbm-upload-status' );

		input.addEventListener( 'change', function () {
			// Manifest last: a set only appears once all its files are here.
			var files = Array.prototype.slice.call( input.files ).sort( function ( a, b ) {
				return ( /\.manifest\.json$/.test( a.name ) ? 1 : 0 ) - ( /\.manifest\.json$/.test( b.name ) ? 1 : 0 );
			} );
			var fileIndex = 0;

			var nextFile = function () {
				if ( fileIndex >= files.length ) {
					statusLine.textContent = i18n.uploaded;
					window.setTimeout( function () {
						window.location.reload();
					}, 800 );
					return;
				}
				sendChunk( files[ fileIndex ], 0 );
			};

			var sendChunk = function ( file, offset ) {
				var data = new FormData();
				data.append( 'action', 'ekbm_upload' );
				data.append( 'nonce', cfg.nonce );
				data.append( 'name', file.name );
				data.append( 'offset', offset );
				data.append( 'total', file.size );
				data.append( 'chunk', file.slice( offset, offset + cfg.chunk ), file.name );

				post(
					data,
					function ( result ) {
						var done = result.size;
						statusLine.textContent = format( i18n.uploading, file.name, file.size ? Math.round( ( 100 * done ) / file.size ) : 100 );
						if ( done >= file.size ) {
							fileIndex++;
							nextFile();
						} else {
							sendChunk( file, done );
						}
					},
					function ( error ) {
						statusLine.textContent = format( i18n.uploadFailed, error );
					}
				);
			};

			nextFile();
		} );
	}

	// -------------------------------------------------------------------------
	// Folder tree (Settings → Files). Mirrors File_Rules: the most specific
	// folder rule covering a path decides; without one, the default patterns.
	// -------------------------------------------------------------------------
	var tree = document.getElementById( 'ekbm-tree' );

	if ( tree ) {
		var rules = {};
		try {
			rules = JSON.parse( tree.getAttribute( 'data-rules' ) ) || {};
		} catch ( e ) {}

		var hidden = document.getElementById( 'ekbm-file-rules' );
		var list = document.getElementById( 'ekbm-rule-list' );
		var nodes = {}; // path → { patternExcluded, li, box, rule, toggle, loaded }

		var parentOf = function ( path ) {
			var i = path.lastIndexOf( '/' );
			return i > 0 ? path.slice( 0, i ) : '';
		};

		var nearestRule = function ( path, includeSelf ) {
			var p = includeSelf ? path : parentOf( path );
			while ( p ) {
				if ( rules[ p ] ) {
					return rules[ p ];
				}
				p = parentOf( p );
			}
			return null;
		};

		var patternExcluded = function ( path ) {
			return !! ( nodes[ path ] && nodes[ path ].patternExcluded );
		};

		var included = function ( path ) {
			var rule = nearestRule( path, true );
			return rule ? 'include' === rule : ! patternExcluded( path );
		};

		var inherited = function ( path ) {
			var rule = nearestRule( path, false );
			return rule ? 'include' === rule : ! patternExcluded( path );
		};

		var sync = function () {
			hidden.value = JSON.stringify( rules );

			Object.keys( nodes ).forEach( function ( path ) {
				nodes[ path ].box.checked = included( path );
				nodes[ path ].rule.hidden = ! rules[ path ];
			} );

			var paths = Object.keys( rules ).sort();
			list.innerHTML = '';
			if ( ! paths.length ) {
				var empty = document.createElement( 'p' );
				empty.className = 'ekbm-muted';
				empty.textContent = i18n.noRules;
				list.appendChild( empty );
				return;
			}
			var ul = document.createElement( 'ul' );
			paths.forEach( function ( path ) {
				var li = document.createElement( 'li' );
				var code = document.createElement( 'code' );
				code.textContent = path;
				var verdict = document.createElement( 'strong' );
				verdict.textContent = ' ' + ( 'include' === rules[ path ] ? i18n.included : i18n.excluded ) + ' ';
				verdict.style.color = 'include' === rules[ path ] ? '#00a32a' : '#d63638';
				var remove = document.createElement( 'button' );
				remove.type = 'button';
				remove.className = 'button-link';
				remove.textContent = i18n.remove;
				remove.addEventListener( 'click', function () {
					delete rules[ path ];
					sync();
				} );
				li.appendChild( code );
				li.appendChild( verdict );
				li.appendChild( remove );
				ul.appendChild( li );
			} );
			list.appendChild( ul );
		};

		var badge = function ( text, color ) {
			var span = document.createElement( 'span' );
			span.className = 'ekbm-badge';
			span.style.background = color;
			span.textContent = text;
			return span;
		};

		var expand;

		var render = function ( container, children ) {
			var ul = document.createElement( 'ul' );

			children.forEach( function ( child ) {
				var li = document.createElement( 'li' );
				var row = document.createElement( 'div' );
				row.className = 'ekbm-tree-row';

				var toggle = document.createElement( 'button' );
				toggle.type = 'button';
				toggle.className = 'ekbm-tree-toggle';
				toggle.setAttribute( 'aria-expanded', 'false' );
				toggle.setAttribute( 'aria-label', i18n.expand );
				toggle.textContent = child.has_children ? '▸' : '';
				toggle.disabled = ! child.has_children;

				var label = document.createElement( 'label' );
				var box = document.createElement( 'input' );
				box.type = 'checkbox';
				box.setAttribute( 'data-path', child.path );
				label.appendChild( box );
				label.appendChild( document.createTextNode( ' ' + child.label ) );

				var rule = document.createElement( 'span' );
				rule.className = 'ekbm-tree-rule';
				rule.appendChild( badge( i18n.ruleBadge, '#2271b1' ) );
				var reset = document.createElement( 'button' );
				reset.type = 'button';
				reset.className = 'button-link';
				reset.textContent = '×';
				reset.title = i18n.reset;
				reset.setAttribute( 'aria-label', i18n.reset );
				rule.appendChild( reset );

				row.appendChild( toggle );
				row.appendChild( label );
				row.appendChild( rule );
				if ( child.pattern_excluded ) {
					row.appendChild( badge( i18n.defaultBadge, '#646970' ) );
				}
				li.appendChild( row );
				ul.appendChild( li );

				nodes[ child.path ] = { patternExcluded: child.pattern_excluded, li: li, box: box, rule: rule, toggle: toggle, loaded: false };

				box.addEventListener( 'change', function () {
					if ( box.checked === inherited( child.path ) ) {
						delete rules[ child.path ];
					} else {
						rules[ child.path ] = box.checked ? 'include' : 'exclude';
					}
					sync();
				} );

				reset.addEventListener( 'click', function () {
					delete rules[ child.path ];
					sync();
				} );

				toggle.addEventListener( 'click', function () {
					expand( child.path );
				} );
			} );

			container.appendChild( ul );
		};

		var load = function ( path, done ) {
			var data = new FormData();
			data.append( 'action', 'ekbm_tree' );
			data.append( 'nonce', cfg.nonce );
			data.append( 'path', path );
			post( data, done, function () {
				var error = document.createElement( 'p' );
				error.className = 'ekbm-muted';
				error.textContent = i18n.treeError;
				tree.appendChild( error );
			} );
		};

		expand = function ( path, then ) {
			var node = nodes[ path ];
			if ( ! node ) {
				return then && then();
			}

			if ( node.loaded ) {
				var open = 'true' === node.toggle.getAttribute( 'aria-expanded' );
				if ( ! then ) {
					node.li.querySelector( 'ul' ).hidden = open;
					node.toggle.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
					node.toggle.textContent = open ? '▸' : '▾';
				}
				return then && then();
			}

			load( path, function ( result ) {
				node.loaded = true;
				render( node.li, result.children );
				if ( result.truncated ) {
					var more = document.createElement( 'p' );
					more.className = 'ekbm-muted';
					more.textContent = i18n.treeMore;
					node.li.appendChild( more );
				}
				node.toggle.setAttribute( 'aria-expanded', 'true' );
				node.toggle.textContent = '▾';
				sync();
				if ( then ) {
					then();
				}
			} );
		};

		// Open the folders leading to every saved rule, one level at a time.
		var revealRules = function () {
			var targets = [];
			Object.keys( rules ).forEach( function ( path ) {
				var parts = path.split( '/' );
				for ( var i = 1; i < parts.length; i++ ) {
					var ancestor = parts.slice( 0, i ).join( '/' );
					if ( targets.indexOf( ancestor ) < 0 ) {
						targets.push( ancestor );
					}
				}
			} );
			targets.sort( function ( a, b ) {
				return a.split( '/' ).length - b.split( '/' ).length;
			} );
			var next = function () {
				if ( targets.length ) {
					expand( targets.shift(), next );
				}
			};
			next();
		};

		load( '', function ( result ) {
			tree.innerHTML = '';
			render( tree, result.children );
			sync();
			// wp-content is where folders are usually chosen: open it.
			expand( 'content', revealRules );
		} );
	}

	// -------------------------------------------------------------------------
	// Conveniences
	// -------------------------------------------------------------------------
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( form.classList.contains( 'ekbm-confirm-delete' ) && ! window.confirm( i18n.confirmDel ) ) {
			event.preventDefault();
		}
		if ( form.classList.contains( 'ekbm-confirm-remove' ) && ! window.confirm( i18n.confirmRemove ) ) {
			event.preventDefault();
		}
		if ( form.classList.contains( 'ekbm-restore-form' ) && ! window.confirm( i18n.confirmRes ) ) {
			event.preventDefault();
		}
	} );

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;

		if ( target.id === 'ekbm-add-row' ) {
			var body = document.querySelector( '#ekbm-replace tbody' );
			var row = body.querySelector( 'tr:last-child' ).cloneNode( true );
			Array.prototype.forEach.call( row.querySelectorAll( 'input' ), function ( field ) {
				field.value = '';
			} );
			body.appendChild( row );
		}

		if ( target.classList.contains( 'ekbm-remove-row' ) ) {
			var tr = target.closest( 'tr' );
			if ( tr.parentNode.children.length > 1 ) {
				tr.parentNode.removeChild( tr );
			} else {
				Array.prototype.forEach.call( tr.querySelectorAll( 'input' ), function ( field ) {
					field.value = '';
				} );
			}
		}

		if ( target.classList.contains( 'ekbm-toggle-all' ) ) {
			var boxes = document.querySelectorAll( target.getAttribute( 'data-target' ) + ' input[type=checkbox]' );
			var anyOff = Array.prototype.some.call( boxes, function ( b ) {
				return ! b.checked;
			} );
			Array.prototype.forEach.call( boxes, function ( b ) {
				b.checked = anyOff;
			} );
		}

		if ( target.classList.contains( 'ekbm-copy' ) ) {
			var source = document.querySelector( target.getAttribute( 'data-target' ) );
			source.select();
			if ( navigator.clipboard ) {
				navigator.clipboard.writeText( source.value );
			} else {
				document.execCommand( 'copy' );
			}
			target.textContent = i18n.copied;
		}
	} );

	// Dropbox: own app vs. built-in app — show the matching guide and fields.
	var syncOwnApp = function () {
		var toggle = document.querySelector( '.ekbm-own-app-toggle' );
		var table = document.querySelector( '.ekbm-dest-table' );
		if ( ! toggle || ! table ) {
			return;
		}
		table.setAttribute( 'data-own-app', toggle.checked ? '1' : '0' );
		Array.prototype.forEach.call( document.querySelectorAll( '.ekbm-guide.ekbm-own-only' ), function ( el ) {
			el.hidden = ! toggle.checked;
		} );
		Array.prototype.forEach.call( document.querySelectorAll( '.ekbm-guide.ekbm-plugin-only' ), function ( el ) {
			el.hidden = toggle.checked;
		} );
	};
	syncOwnApp();

	document.addEventListener( 'change', function ( event ) {
		if ( event.target.classList.contains( 'ekbm-own-app-toggle' ) ) {
			syncOwnApp();
		}
		if ( event.target.classList.contains( 'ekbm-frequency' ) ) {
			event.target.closest( '.ekbm-schedule' ).setAttribute( 'data-frequency', event.target.value );
		}
	} );
}() );
