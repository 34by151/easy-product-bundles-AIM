/**
 * AIM Bundle — Admin JS
 *
 * Renders the "Quantity from Items" and "Max from Items" link tables inside
 * the Bundle AIM product data panel. Also handles the per-item "Show qty
 * override" checkboxes (those are pure PHP-rendered — no JS needed for them).
 *
 * Runs only when aimBundleData is present (bundle product edit pages).
 *
 * Data flow:
 *   1. PHP outputs stored links as JSON in hidden inputs.
 *   2. This script reads that data + the item list from aimBundleData.
 *   3. User adds / removes link rows via the UI.
 *   4. On any change, serialize*() updates the hidden inputs so the standard
 *      WP form POST carries the current state to PHP.
 */

/* global aimBundleData, jQuery */
( function ( $ ) {
	'use strict';

	if ( typeof aimBundleData === 'undefined' ) {
		return;
	}

	var items        = aimBundleData.items        || []; // [{ index, label, max_qty }]
	var qtyLinks     = aimBundleData.qtyLinks     || []; // [{ target, sources }]
	var maxFromItems = aimBundleData.maxFromItems  || []; // [{ target, sources }]
	var i18n         = aimBundleData.i18n         || {};

	// ── Shared option builders ────────────────────────────────────────────────

	/**
	 * Build <option> HTML for a target dropdown.
	 * excludeIndex: omit this index (self-reference guard).
	 * selectedIndex: pre-selected value.
	 * showMaxQty: when true, append "(Max: N)" to the label for items that have
	 *             a configured Max quantity.
	 */
	function buildTargetOptions( excludeIndex, selectedIndex, showMaxQty ) {
		var html = '<option value="">' + escHtml( i18n.selectItem || '— Select item —' ) + '</option>';
		items.forEach( function ( item ) {
			if ( item.index === excludeIndex ) {
				return;
			}
			var label = item.label;
			if ( showMaxQty && item.max_qty ) {
				label += ' (Max: ' + item.max_qty + ')';
			}
			var sel = ( item.index === selectedIndex ) ? ' selected' : '';
			html += '<option value="' + item.index + '"' + sel + '>' + escHtml( label ) + '</option>';
		} );
		return html;
	}

	/**
	 * Build <option> HTML for a source multi-select.
	 * excludeIndex: omit this index (it is the target).
	 * selectedIndices: array of pre-selected source indices.
	 */
	function buildSourceOptions( excludeIndex, selectedIndices ) {
		var html = '';
		items.forEach( function ( item ) {
			if ( item.index === excludeIndex ) {
				return;
			}
			var sel = ( selectedIndices && selectedIndices.indexOf( item.index ) !== -1 ) ? ' selected' : '';
			html += '<option value="' + item.index + '"' + sel + '>' + escHtml( item.label ) + '</option>';
		} );
		return html;
	}

	// ── Shared row factory ────────────────────────────────────────────────────

	/**
	 * Create and return a jQuery <tr> for one link row.
	 *
	 * @param {string}        rowClass      CSS class on the <tr>.
	 * @param {number|null}   targetIndex   Pre-selected target (null for blank row).
	 * @param {number[]}      sourceIndices Pre-selected sources.
	 * @param {Function}      onChangeCallback Called whenever target or sources change.
	 * @param {boolean}       targetShowMaxQty Show "(Max: N)" hint in target dropdown.
	 */
	function makeRow( rowClass, targetIndex, sourceIndices, onChangeCallback, targetShowMaxQty ) {
		var hasTarget = ( targetIndex !== undefined && targetIndex !== null );
		var $row      = $( '<tr class="' + rowClass + '"></tr>' );

		// ── Target cell ──
		var $targetSel = $( '<select class="aim-target-sel"></select>' );
		$targetSel.html( buildTargetOptions(
			null,
			hasTarget ? targetIndex : undefined,
			!! targetShowMaxQty
		) );

		$row.append( $( '<td></td>' ).append( $targetSel ) );

		// ── Sources cell ──
		var $sourceSel = $( '<select class="aim-source-sel" multiple></select>' );
		$sourceSel.html( buildSourceOptions( hasTarget ? targetIndex : null, sourceIndices || [] ) );

		$row.append( $( '<td></td>' ).append( $sourceSel ) );

		// ── Remove cell ──
		var $removeBtn = $( '<button type="button" class="button aim-remove-btn">'
			+ escHtml( i18n.removeLink || 'Remove' ) + '</button>' );
		$row.append( $( '<td class="aim-col-remove"></td>' ).append( $removeBtn ) );

		// ── Events ──

		$targetSel.on( 'change', function () {
			var newTarget       = parseIntOrNull( $( this ).val() );
			var currentSources  = getMultiSelectValues( $sourceSel );
			var filteredSources = currentSources.filter( function ( s ) { return s !== newTarget; } );
			$sourceSel.html( buildSourceOptions( newTarget, filteredSources ) );
			if ( targetShowMaxQty ) {
				// Rebuild target options with updated Max hint after selection.
				var selected = parseIntOrNull( $targetSel.val() );
				$targetSel.html( buildTargetOptions( null, selected, true ) );
			}
			onChangeCallback();
		} );

		$sourceSel.on( 'change', onChangeCallback );

		$removeBtn.on( 'click', function () {
			$row.remove();
			onChangeCallback();
		} );

		return $row;
	}

	// =========================================================================
	// Feature 2 — Quantity from Items
	// =========================================================================

	var $qtyTbody       = $( '#aim-qty-links-rows' );
	var $qtyHiddenInput = $( '#aim_item_qty_links' );
	var $qtyAddButton   = $( '#aim-add-qty-link' );

	if ( $qtyTbody.length ) {

		function serializeQtyLinks() {
			var links = [];

			$qtyTbody.find( '.aim-qty-link-row' ).each( function () {
				var $row    = $( this );
				var target  = parseIntOrNull( $row.find( '.aim-target-sel' ).val() );
				var sources = getMultiSelectValues( $row.find( '.aim-source-sel' ) );

				if ( target === null || sources.length === 0 ) {
					return;
				}

				sources = sources.filter( function ( s ) { return s !== target; } );
				if ( sources.length === 0 ) {
					return;
				}

				links.push( { target: target, sources: sources } );
			} );

			if ( links.length > 0 && hasCircularRef( links ) ) {
				alert( i18n.circularWarn || 'Circular reference detected.' );
			}

			$qtyHiddenInput.val( JSON.stringify( links ) );
		}

		// Initialise rows from stored data.
		qtyLinks.forEach( function ( link ) {
			$qtyTbody.append(
				makeRow( 'aim-qty-link-row', link.target, link.sources, serializeQtyLinks, false )
			);
		} );

		$qtyAddButton.on( 'click', function () {
			$qtyTbody.append(
				makeRow( 'aim-qty-link-row', null, [], serializeQtyLinks, false )
			);
		} );
	}

	// =========================================================================
	// Feature 4 — Max from Items
	// =========================================================================

	var $maxTbody       = $( '#aim-max-links-rows' );
	var $maxHiddenInput = $( '#aim_max_from_items' );
	var $maxAddButton   = $( '#aim-add-max-link' );

	if ( $maxTbody.length ) {

		function serializeMaxLinks() {
			var links = [];

			$maxTbody.find( '.aim-max-link-row' ).each( function () {
				var $row    = $( this );
				var target  = parseIntOrNull( $row.find( '.aim-target-sel' ).val() );
				var sources = getMultiSelectValues( $row.find( '.aim-source-sel' ) );

				if ( target === null || sources.length === 0 ) {
					return;
				}

				sources = sources.filter( function ( s ) { return s !== target; } );
				if ( sources.length === 0 ) {
					return;
				}

				// Warn if the target item has no Max quantity configured.
				var targetItem = items.find( function ( it ) { return it.index === target; } );
				if ( targetItem && ! targetItem.max_qty ) {
					// Non-blocking advisory — show once per save.
					console.warn( '[EPB-AIM] ' + ( i18n.noMaxQty || 'Item has no Max quantity set.' ) ); // eslint-disable-line no-console
				}

				links.push( { target: target, sources: sources } );
			} );

			$maxHiddenInput.val( JSON.stringify( links ) );
		}

		// Initialise rows from stored data.
		maxFromItems.forEach( function ( link ) {
			$maxTbody.append(
				// Pass true for targetShowMaxQty so the "(Max: N)" hint is visible.
				makeRow( 'aim-max-link-row', link.target, link.sources, serializeMaxLinks, true )
			);
		} );

		$maxAddButton.on( 'click', function () {
			$maxTbody.append(
				makeRow( 'aim-max-link-row', null, [], serializeMaxLinks, true )
			);
		} );
	}

	// =========================================================================
	// Max qty message — checkbox enables / disables the text field
	// =========================================================================

	( function () {
		var $cb    = $( '#aim_max_qty_message_enabled' );
		var $field = $( '#aim_max_qty_message' );
		if ( ! $cb.length || ! $field.length ) {
			return;
		}
		$cb.on( 'change', function () {
			$field.prop( 'disabled', ! this.checked );
		} );
	}() );

	// =========================================================================
	// Bundle qty message — checkbox enables / disables the text field
	// =========================================================================

	( function () {
		var $cb    = $( '#aim_bundle_qty_message_enabled' );
		var $field = $( '#aim_bundle_qty_message_text' );
		if ( ! $cb.length || ! $field.length ) {
			return;
		}
		$cb.on( 'change', function () {
			$field.prop( 'disabled', ! this.checked );
		} );
	}() );

	// =========================================================================
	// No selection message — checkbox enables / disables the text field
	// =========================================================================

	( function () {
		var $cb    = $( '#aim_no_selection_message_enabled' );
		var $field = $( '#aim_no_selection_message_text' );
		if ( ! $cb.length || ! $field.length ) {
			return;
		}
		$cb.on( 'change', function () {
			$field.prop( 'disabled', ! this.checked );
		} );
	}() );

	// =========================================================================
	// Qty × unit price total — checkbox enables / disables both text fields
	// =========================================================================

	( function () {
		var $cb     = $( '#aim_qty_total_enabled' );
		var $field1 = $( '#aim_each_bundle_message' );
		var $field2 = $( '#aim_bundle_total_message' );
		if ( ! $cb.length ) {
			return;
		}
		$cb.on( 'change', function () {
			var checked = this.checked;
			$field1.prop( 'disabled', ! checked );
			$field2.prop( 'disabled', ! checked );
		} );
	}() );

	// =========================================================================
	// Feature 9 sub-setting — +/- Button Size show/hide
	// =========================================================================

	( function () {
		var $cb       = $( '#aim_qty_plusminus' );
		var $subField = $( '.aim-qty-pm-sub-field' );
		if ( ! $cb.length || ! $subField.length ) {
			return;
		}
		function toggle() {
			$subField.toggle( $cb.is( ':checked' ) );
		}
		$cb.on( 'change', toggle );
		toggle(); // apply on page load
	}() );

	// =========================================================================
	// Feature 8 — Image Swap Rules
	// =========================================================================

	( function () {
		var $swapTbody     = $( '#aim-image-swap-rows' );
		var $swapInput     = $( '#aim_image_swap_rules' );
		var $swapAddButton = $( '#aim-add-image-swap-rule' );

		if ( ! $swapTbody.length ) {
			return;
		}

		var imageSwapRules = aimBundleData.imageSwapRules || [];
		var nonOptItems    = items.filter( function ( it ) { return ! it.is_optional; } );
		var optItems       = items.filter( function ( it ) { return !! it.is_optional; } );

		/**
		 * Normalise a rule's selected array to the new {index, qty} object format.
		 * Old rules store plain integers; new rules store {index, qty} objects.
		 */
		function normalizeSelected( selected ) {
			return ( selected || [] ).map( function ( s ) {
				if ( typeof s === 'number' ) {
					return { index: s, qty: null };
				}
				return {
					index: s.index,
					qty: ( s.qty !== undefined && s.qty !== null && s.qty !== '' ) ? s.qty : null,
				};
			} );
		}

		function serializeSwapRules() {
			var rules = [];
			$swapTbody.find( '.aim-swap-row' ).each( function () {
				var $row     = $( this );
				var target   = parseIntOrNull( $row.find( '.aim-swap-target-sel' ).val() );
				var imageId  = parseIntOrNull( $row.find( '.aim-swap-image-id' ).val() );
				var selected = [];
				$row.find( '.aim-swap-opt-check:checked' ).each( function () {
					var idx     = parseInt( this.value, 10 );
					var $qtyInp = $row.find( '.aim-swap-opt-qty[data-idx="' + idx + '"]' );
					var rawQty  = parseInt( $qtyInp.val(), 10 );
					selected.push( { index: idx, qty: ( isNaN( rawQty ) || rawQty <= 0 ) ? null : rawQty } );
				} );
				var scope    = $row.find( '.aim-swap-scope-sel' ).val() || 'item';

				if ( target === null || ! imageId ) {
					return;
				}
				rules.push( { target: target, selected: selected, image_id: imageId, scope: scope } );
			} );
			$swapInput.val( JSON.stringify( rules ) );
		}

		function makeSwapRow( rule ) {
			rule = rule || {};
			var $row = $( '<tr class="aim-swap-row"></tr>' );

			// ── Target cell ──────────────────────────────────────────────────
			var $targetSel = $( '<select class="aim-swap-target-sel"></select>' );
			var targetHtml = '<option value="">' + escHtml( i18n.selectItem || '— Select item —' ) + '</option>';
			nonOptItems.forEach( function ( it ) {
				var sel = ( it.index === rule.target ) ? ' selected' : '';
				targetHtml += '<option value="' + it.index + '"' + sel + '>' + escHtml( it.label ) + '</option>';
			} );
			$targetSel.html( targetHtml );
			$row.append( $( '<td></td>' ).append( $targetSel ) );

			// ── Optional items checkbox + qty list cell ─────────────────────
			var $optCell = $( '<td class="aim-swap-optional-cell"></td>' );
			if ( optItems.length === 0 ) {
				$optCell.text( '—' );
			} else {
				var normalizedSel = normalizeSelected( rule.selected || [] );
				var $optList = $( '<div class="aim-swap-optional-list"></div>' );
				optItems.forEach( function ( it ) {
					var selItem = null;
					for ( var qi = 0; qi < normalizedSel.length; qi++ ) {
						if ( normalizedSel[ qi ].index === it.index ) {
							selItem = normalizedSel[ qi ];
							break;
						}
					}
					var isChecked = ( selItem !== null );
					var qtyVal    = ( selItem && selItem.qty !== null ) ? selItem.qty : '';

					var $item     = $( '<div class="aim-swap-opt-item"></div>' );
					var $check    = $( '<input type="checkbox" class="aim-swap-opt-check">' )
						.val( it.index )
						.prop( 'checked', isChecked );
					var $label    = $( '<span class="aim-swap-opt-label"></span>' ).text( escHtml( it.label ) );
					var $qtyInput = $( '<input type="number" class="aim-swap-opt-qty" min="1" step="1">' )
						.attr( 'data-idx', it.index )
						.attr( 'placeholder', 'any' )
						.val( qtyVal );

					if ( ! isChecked ) {
						$qtyInput.prop( 'disabled', true ).addClass( 'aim-swap-opt-qty-disabled' );
					}

					( function ( $c, $q ) {
						$c.on( 'change', function () {
							var checked = $c.prop( 'checked' );
							$q.prop( 'disabled', ! checked );
							if ( checked ) {
								$q.removeClass( 'aim-swap-opt-qty-disabled' );
							} else {
								$q.addClass( 'aim-swap-opt-qty-disabled' ).val( '' );
							}
							serializeSwapRules();
						} );
						$q.on( 'input change', serializeSwapRules );
					}( $check, $qtyInput ) );

					$item.append( $check ).append( $label ).append( $qtyInput );
					$optList.append( $item );
				} );
				$optCell.append( $optList );
			}
			$row.append( $optCell );

			// ── Scope cell ───────────────────────────────────────────────────
			// Normalise legacy 'main' → 'gallery' so old saved rules display correctly.
			var rawScope    = rule.scope || 'item';
			var currentScope = ( rawScope === 'main' ) ? 'gallery' : rawScope;
			var $scopeSel = $( '<select class="aim-swap-scope-sel"></select>' );
			$scopeSel.html(
				'<option value="item"'    + ( currentScope === 'item'    ? ' selected' : '' ) + '>' + escHtml( i18n.scopeItem    || 'Item image'    ) + '</option>' +
				'<option value="gallery"' + ( currentScope === 'gallery' ? ' selected' : '' ) + '>' + escHtml( i18n.scopeGallery || 'Gallery image' ) + '</option>' +
				'<option value="both"'    + ( currentScope === 'both'    ? ' selected' : '' ) + '>' + escHtml( i18n.scopeBoth    || 'Both'          ) + '</option>'
			);
			$scopeSel.on( 'change', serializeSwapRules );
			$row.append( $( '<td class="aim-swap-scope-cell"></td>' ).append( $scopeSel ) );

			// ── Image cell ───────────────────────────────────────────────────
			var $imageCell = $( '<td class="aim-swap-image-cell"></td>' );
			var $preview   = $( '<img class="aim-swap-preview" alt="">' );
			var $hiddenId  = $( '<input type="hidden" class="aim-swap-image-id">' ).val( rule.image_id || '' );
			var $chooseBtn = $( '<button type="button" class="button aim-swap-choose-btn">' )
				.text( i18n.chooseImage || 'Choose Image' );
			var $removeImg = $( '<a href="#" class="aim-swap-remove-img">' )
				.text( i18n.removeImage || 'Remove' );

			if ( rule.image_id && rule.image_url ) {
				$preview.attr( 'src', rule.image_url ).show();
				$removeImg.show();
			} else {
				$preview.hide();
				$removeImg.hide();
			}

			$chooseBtn.on( 'click', function () {
				if ( typeof wp === 'undefined' || typeof wp.media === 'undefined' ) {
					return;
				}
				var frame = wp.media( {
					title    : i18n.chooseImage || 'Choose Image',
					button   : { text: i18n.useImage || 'Use Image' },
					multiple : false,
					library  : { type: 'image' },
				} );
				frame.on( 'select', function () {
					var att = frame.state().get( 'selection' ).first().toJSON();
					$hiddenId.val( att.id );
					$preview.attr( 'src', att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url ).show();
					$removeImg.show();
					serializeSwapRules();
				} );
				frame.open();
			} );

			$removeImg.on( 'click', function ( e ) {
				e.preventDefault();
				$hiddenId.val( '' );
				$preview.attr( 'src', '' ).hide();
				$removeImg.hide();
				serializeSwapRules();
			} );

			$imageCell.append( $preview ).append( $hiddenId ).append( $chooseBtn ).append( $removeImg );
			$row.append( $imageCell );

			// ── Remove row button ────────────────────────────────────────────
			var $removeRow = $( '<button type="button" class="button aim-remove-btn">' )
				.text( i18n.removeLink || 'Remove' );
			$removeRow.on( 'click', function () {
				$row.remove();
				serializeSwapRules();
			} );
			$row.append( $( '<td class="aim-col-remove"></td>' ).append( $removeRow ) );

			// ── Events ───────────────────────────────────────────────────────
			$targetSel.on( 'change', serializeSwapRules );

			return $row;
		}

		// Populate rows from saved data.
		imageSwapRules.forEach( function ( rule ) {
			$swapTbody.append( makeSwapRow( rule ) );
		} );

		$swapAddButton.on( 'click', function () {
			$swapTbody.append( makeSwapRow( {} ) );
		} );
	}() );

	// =========================================================================
	// Shared utilities
	// =========================================================================

	/**
	 * Simple cycle detector for a link graph { target → sources }.
	 * Returns true if ANY circular dependency exists.
	 */
	function hasCircularRef( links ) {
		var map = {};
		links.forEach( function ( l ) {
			map[ l.target ] = l.sources;
		} );

		function canReach( from, goal, visited ) {
			if ( ! map[ from ] ) {
				return false;
			}
			for ( var i = 0; i < map[ from ].length; i++ ) {
				var s = map[ from ][ i ];
				if ( s === goal ) {
					return true;
				}
				if ( visited[ s ] ) {
					continue;
				}
				visited[ s ] = true;
				if ( canReach( s, goal, visited ) ) {
					return true;
				}
			}
			return false;
		}

		for ( var t in map ) {
			if ( Object.prototype.hasOwnProperty.call( map, t ) ) {
				var tNum    = parseInt( t, 10 );
				var visited = {};
				visited[ tNum ] = true;
				if ( canReach( tNum, tNum, visited ) ) {
					return true;
				}
			}
		}
		return false;
	}

	function escHtml( str ) {
		return $( '<div>' ).text( String( str ) ).html();
	}

	function parseIntOrNull( val ) {
		if ( val === '' || val === null || val === undefined ) {
			return null;
		}
		var n = parseInt( val, 10 );
		return isNaN( n ) ? null : n;
	}

	function getMultiSelectValues( $sel ) {
		var raw = $sel.val();
		if ( ! raw ) {
			return [];
		}
		return raw.map( function ( v ) { return parseInt( v, 10 ); } );
	}

} )( jQuery );
