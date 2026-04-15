/* Load Balanced Sync — Admin JS */
/* global document, window */

( function () {
	'use strict';

	function initLogViewer() {
		if ( ! window.lbsLogViewerConfig || ! window.wp ) {
			return;
		}

		var mountNode = document.getElementById( 'lbs-log-viewer-root' );
		if ( ! mountNode ) {
			return;
		}

		var wpObject = window.wp;
		if ( ! wpObject.element || ! wpObject.dataviews || ! wpObject.dataviews.DataViews ) {
			return;
		}

		var initialEntries = Array.isArray( window.lbsLogViewerConfig.entries ) ? window.lbsLogViewerConfig.entries : [];
		var fileRows = Array.isArray( window.lbsLogViewerConfig.files ) ? window.lbsLogViewerConfig.files : [];

		var fallback = document.getElementById( 'lbs-log-viewer-fallback' );
		if ( fallback ) {
			fallback.style.display = 'none';
		}

		var __ = wpObject.i18n && wpObject.i18n.__ ? wpObject.i18n.__ : function ( text ) { return text; };
		var createElement = wpObject.element.createElement;
		var useState = wpObject.element.useState;
		var useMemo = wpObject.element.useMemo;
		var useCallback = wpObject.element.useCallback;
		var DataViews = wpObject.dataviews.DataViews;
		var filterSortAndPaginate = wpObject.dataviews.filterSortAndPaginate;
		var SelectControl = wpObject.components && wpObject.components.SelectControl ? wpObject.components.SelectControl : null;
		var Notice = wpObject.components && wpObject.components.Notice ? wpObject.components.Notice : null;

		var formatTimestamp = function ( ts ) {
			var numericTs = Number( ts || 0 );
			if ( ! numericTs ) {
				return '—';
			}

			var date = new Date( numericTs * 1000 );
			if ( Number.isNaN( date.getTime() ) ) {
				return '—';
			}

			return date.toLocaleString();
		};

		var LogViewerApp = function () {
			var _useStateSelected = useState( String( window.lbsLogViewerConfig.selectedFile || '' ) );
			var selectedFile = _useStateSelected[0];
			var setSelectedFile = _useStateSelected[1];

			var _useStateEntries = useState( initialEntries );
			var entries = _useStateEntries[0];
			var setEntries = _useStateEntries[1];

			var _useStateLoading = useState( false );
			var isLoading = _useStateLoading[0];
			var setIsLoading = _useStateLoading[1];

			var _useStateError = useState( '' );
			var errorMessage = _useStateError[0];
			var setErrorMessage = _useStateError[1];

			var _useState = useState( {
				type: 'table',
				perPage: 25,
				fields: [ 'timestamp', 'level', 'message' ],
				sort: {
					field: 'timestamp',
					direction: 'desc',
				},
			} );
			var view = _useState[0];
			var setView = _useState[1];

			var fileOptions = useMemo( function () {
				var options = [
					{ label: __( 'Current / Recent Entries', 'load-balanced-sync' ), value: '' },
				];

				fileRows.forEach( function ( row ) {
					var filename = String( row.filename || '' );
					if ( ! filename ) {
						return;
					}

					options.push( {
						label: filename,
						value: filename,
					} );
				} );

				return options;
			}, [] );

			var loadEntries = useCallback( function ( file ) {
				if ( ! window.lbsLogViewerConfig.restBase ) {
					return Promise.resolve();
				}

				setIsLoading( true );
				setErrorMessage( '' );

				var url = new URL( window.lbsLogViewerConfig.restBase + '/entries' );
				if ( file ) {
					url.searchParams.set( 'file', String( file ) );
				}
				url.searchParams.set( 'per_page', '1000' );

				return window.fetch( url.toString(), {
					headers: {
						'X-WP-Nonce': String( window.lbsLogViewerConfig.restNonce || '' ),
					},
				} )
					.then( function ( response ) {
						if ( ! response.ok ) {
							throw new Error( 'fetch-failed' );
						}

						return response.json();
					} )
					.then( function ( payload ) {
						setEntries( Array.isArray( payload.items ) ? payload.items : [] );
						setIsLoading( false );
					} )
					.catch( function () {
						setErrorMessage( __( 'Unable to load log entries for the selected file.', 'load-balanced-sync' ) );
						setEntries( [] );
						setIsLoading( false );
					} );
			}, [] );

			var fields = useMemo( function () {
				return [
					{
						id: 'timestamp',
						label: __( 'Time', 'load-balanced-sync' ),
						enableGlobalSearch: false,
						render: function ( args ) {
							return formatTimestamp( args.item.ts );
						},
					},
					{
						id: 'level',
						label: __( 'Level', 'load-balanced-sync' ),
						enableGlobalSearch: true,
						render: function ( args ) {
							return String( args.item.level || 'info' );
						},
					},
					{
						id: 'message',
						label: __( 'Message', 'load-balanced-sync' ),
						enableGlobalSearch: true,
						render: function ( args ) {
							return String( args.item.message || '' );
						},
					},
				];
			}, [] );

			var processed = useMemo( function () {
				return filterSortAndPaginate( entries, view, fields );
			}, [ entries, view, fields ] );

			return createElement(
				'div',
				{ className: 'lbs-log-viewer-root' },
				SelectControl
					? createElement( SelectControl, {
						label: __( 'Log File', 'load-balanced-sync' ),
						value: selectedFile,
						options: fileOptions,
						onChange: function ( value ) {
							setSelectedFile( value );
							setView( function ( currentView ) {
								return Object.assign( {}, currentView, { page: 1 } );
							} );
							void loadEntries( value );
						},
					} )
					: null,
				errorMessage && Notice
					? createElement( Notice, { status: 'error', isDismissible: false }, errorMessage )
					: null,
				isLoading
					? createElement( 'p', null, __( 'Loading entries…', 'load-balanced-sync' ) )
					: createElement( DataViews, {
						data: processed.data,
						fields: fields,
						view: view,
						onChangeView: setView,
						defaultLayouts: { table: { layout: {} } },
						paginationInfo: processed.paginationInfo,
						search: true,
					} )
			);
		};

		if ( typeof wpObject.element.createRoot === 'function' ) {
			wpObject.element.createRoot( mountNode ).render( createElement( LogViewerApp ) );
			return;
		}

		if ( typeof wpObject.element.render === 'function' ) {
			wpObject.element.render( createElement( LogViewerApp ), mountNode );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		// Copy-to-clipboard buttons.
		document.querySelectorAll( '[data-lbs-copy]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var targetId = btn.getAttribute( 'data-lbs-copy' );
				var input    = document.getElementById( targetId );
				if ( ! input ) return;

				input.select();
				input.setSelectionRange( 0, 99999 );

				try {
					if ( navigator.clipboard && navigator.clipboard.writeText ) {
						navigator.clipboard.writeText( input.value ).then( function () {
							btn.textContent = 'Copied!';
							setTimeout( function () {
								btn.textContent = 'Copy';
							}, 2000 );
						} );
					} else {
						document.execCommand( 'copy' );
						btn.textContent = 'Copied!';
						setTimeout( function () {
							btn.textContent = 'Copy';
						}, 2000 );
					}
				} catch ( err ) {
					/* silently fail — text is still selected */
				}
			} );
		} );

		initLogViewer();
	} );
}() );
