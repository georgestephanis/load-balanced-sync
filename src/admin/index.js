/**
 * Load Balanced Sync admin entry.
 *
 * This file is bundled by @wordpress/scripts into build/index.js.
 */

import './style.scss';

( function () {
	'use strict';

	function initLogViewer() {
		if ( ! window.lbsLogViewerConfig || ! window.wp ) {
			return;
		}

		const mountNode = document.getElementById( 'lbs-log-viewer-root' );
		if ( ! mountNode ) {
			return;
		}

		const wpObject = window.wp;
		if ( ! wpObject.element || ! wpObject.dataviews || ! wpObject.dataviews.DataViews ) {
			return;
		}

		const initialEntries = Array.isArray( window.lbsLogViewerConfig.entries ) ? window.lbsLogViewerConfig.entries : [];
		const fileRows = Array.isArray( window.lbsLogViewerConfig.files ) ? window.lbsLogViewerConfig.files : [];

		const fallback = document.getElementById( 'lbs-log-viewer-fallback' );
		if ( fallback ) {
			fallback.style.display = 'none';
		}

		const __ = wpObject.i18n && wpObject.i18n.__ ? wpObject.i18n.__ : ( text ) => text;
		const { createElement, useState, useMemo, useCallback } = wpObject.element;
		const { DataViews, filterSortAndPaginate } = wpObject.dataviews;
		const { SelectControl, Notice } = wpObject.components || {};

		const formatTimestamp = ( ts ) => {
			const numericTs = Number( ts || 0 );
			if ( ! numericTs ) {
				return '—';
			}

			const date = new Date( numericTs * 1000 );
			if ( Number.isNaN( date.getTime() ) ) {
				return '—';
			}

			return date.toLocaleString();
		};

		const LogViewerApp = () => {
			const [ selectedFile, setSelectedFile ] = useState( String( window.lbsLogViewerConfig.selectedFile || '' ) );
			const [ entries, setEntries ] = useState( initialEntries );
			const [ isLoading, setIsLoading ] = useState( false );
			const [ errorMessage, setErrorMessage ] = useState( '' );

			const [ view, setView ] = useState( {
				type: 'table',
				perPage: 25,
				fields: [ 'timestamp', 'level', 'message' ],
				sort: {
					field: 'timestamp',
					direction: 'desc',
				},
			} );

			const fileOptions = useMemo( () => {
				const options = [
					{ label: __( 'Current / Recent Entries', 'load-balanced-sync' ), value: '' },
				];

				fileRows.forEach( ( row ) => {
					const filename = String( row.filename || '' );
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

			const loadEntries = useCallback(
				async ( file ) => {
					if ( ! window.lbsLogViewerConfig.restBase ) {
						return;
					}

					setIsLoading( true );
					setErrorMessage( '' );

					try {
						const url = new URL( window.lbsLogViewerConfig.restBase + '/entries' );
						if ( file ) {
							url.searchParams.set( 'file', String( file ) );
						}
						url.searchParams.set( 'per_page', '1000' );

						const response = await window.fetch( url.toString(), {
							headers: {
								'X-WP-Nonce': String( window.lbsLogViewerConfig.restNonce || '' ),
							},
						} );

						if ( ! response.ok ) {
							throw new Error( 'fetch-failed' );
						}

						const payload = await response.json();
						setEntries( Array.isArray( payload.items ) ? payload.items : [] );
					} catch ( err ) {
						setErrorMessage( __( 'Unable to load log entries for the selected file.', 'load-balanced-sync' ) );
						setEntries( [] );
					}

					setIsLoading( false );
				},
				[]
			);

			const fields = useMemo(
				() => [
					{
						id: 'timestamp',
						label: __( 'Time', 'load-balanced-sync' ),
						enableGlobalSearch: false,
						render: ( { item } ) => formatTimestamp( item.ts ),
					},
					{
						id: 'level',
						label: __( 'Level', 'load-balanced-sync' ),
						enableGlobalSearch: true,
						render: ( { item } ) => String( item.level || 'info' ),
					},
					{
						id: 'message',
						label: __( 'Message', 'load-balanced-sync' ),
						enableGlobalSearch: true,
						render: ( { item } ) => String( item.message || '' ),
					},
				],
				[]
			);

			const processed = useMemo(
				() => filterSortAndPaginate( entries, view, fields ),
				[ entries, view, fields ]
			);

			return createElement(
				'div',
				{ className: 'lbs-log-viewer-root' },
				SelectControl
					? createElement( SelectControl, {
						label: __( 'Log File', 'load-balanced-sync' ),
						value: selectedFile,
						options: fileOptions,
						onChange: ( value ) => {
							setSelectedFile( value );
							setView( ( currentView ) => ( {
								...currentView,
								page: 1,
							} ) );
							void loadEntries( value );
						},
						__nextHasNoMarginBottom: true,
					} )
					: null,
				errorMessage && Notice
					? createElement( Notice, { status: 'error', isDismissible: false }, errorMessage )
					: null,
				isLoading
					? createElement( 'p', null, __( 'Loading entries…', 'load-balanced-sync' ) )
					: createElement( DataViews, {
						data: processed.data,
						fields,
						view,
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
		document.querySelectorAll( '[data-lbs-copy]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				const targetId = btn.getAttribute( 'data-lbs-copy' );
				const input = document.getElementById( targetId );
				if ( ! input ) {
					return;
				}

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
					// Intentionally no-op. Text remains selected for manual copy.
				}
			} );
		} );

		initLogViewer();
	} );
}() );
