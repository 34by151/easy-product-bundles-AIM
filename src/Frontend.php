<?php
/**
 * Frontend class — product page hooks.
 *
 * Feature 1: Bundle-level "Hide item quantity" — hides qty controls for all
 *   items via the asnp_wepb_localize_product_bundles_shared filter, or via
 *   inline CSS when Features 2, 3, or 4 also need the inputs in the DOM.
 *
 * Feature 2: "Quantity from items" — passes link config to aim-frontend.js.
 *
 * Feature 3: Per-item "Show qty override" — forces specific items' qty controls
 *   to remain visible even when Feature 1 is active. Handled via inline CSS
 *   (CSS hides all items) and aim-frontend.js (JS re-shows override items).
 *
 * Feature 4: "Max from items" — passes max-link config (with base_max) to
 *   aim-frontend.js for live max-attribute enforcement.
 *
 * All features are blocked on the site homepage.
 *
 * @package EasyProductBundlesAIM
 */

namespace EasyProductBundlesAIM;

defined( 'ABSPATH' ) || exit;

class Frontend {

	const BUNDLE_TYPE                  = 'easy_product_bundle';
	const META_HIDE_QTY                = '_aim_hide_item_qty';
	const META_QTY_LINKS               = '_aim_item_qty_links';
	const META_SHOW_ITEM_QTY_OVERRIDES = '_aim_show_item_qty_overrides';
	const META_MAX_FROM_ITEMS          = '_aim_max_from_items';
	const META_MAX_QTY_MESSAGE             = '_aim_max_qty_message';
	const META_MAX_QTY_MESSAGE_ENABLED     = '_aim_max_qty_message_enabled';
	const META_BUNDLE_QTY_MSG_ENABLED      = '_aim_bundle_qty_message_enabled';
	const META_BUNDLE_QTY_MSG_TEXT         = '_aim_bundle_qty_message_text';
	const META_NO_SELECTION_MSG_ENABLED    = '_aim_no_selection_message_enabled';
	const META_NO_SELECTION_MSG_TEXT       = '_aim_no_selection_message_text';
	const META_QTY_TOTAL_ENABLED           = '_aim_qty_total_enabled';
	const META_EACH_BUNDLE_MESSAGE         = '_aim_each_bundle_message';
	const META_BUNDLE_TOTAL_MESSAGE        = '_aim_bundle_total_message';
	const META_IMAGE_SWAP_RULES            = '_aim_image_swap_rules';
	const META_QTY_PLUSMINUS               = '_aim_qty_plusminus';
	const META_QTY_PM_SIZE                 = '_aim_qty_pm_size';
	const META_SWAP_POSITIONS              = '_aim_swap_positions';
	const META_AUTOWIDTH_ROW               = '_aim_autowidth_row';

	public function init(): void {
		// Priority 20 — run after the base plugin (default 10) and pro plugin hooks.
		add_filter( 'asnp_wepb_localize_product_bundles_shared', [ $this, 'modify_shared_data' ], 20 );
		add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue_frontend_js' ], 20 );
		add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue_hide_qty_css' ], 25 );
		// Priority 999 — remove checkout-block assets on pages that don't need them.
		add_action( 'wp_enqueue_scripts', [ $this, 'dequeue_checkout_assets_where_unneeded' ], 999 );
		// Priority 15 — inject AIM gallery slides before existing WC gallery thumbnails (priority 20).
		add_action( 'woocommerce_product_thumbnails', [ $this, 'inject_aim_gallery_slides' ], 15 );
	}

	// -------------------------------------------------------------------------
	// Shared JS data filter (all features)
	// -------------------------------------------------------------------------

	public function modify_shared_data( array $data ): array {
		epb_aim_log( 'modify_shared_data: filter called. is_eligible=' . ( $this->is_eligible_page() ? 'yes' : 'no' ) );

		if ( ! $this->is_eligible_page() ) {
			return $data;
		}

		$product = $this->get_bundle_product();
		if ( ! $product ) {
			epb_aim_log( 'modify_shared_data: no bundle product found on this page' );
			return $data;
		}

		$product_id = $product->get_id();
		epb_aim_log( "modify_shared_data: bundle product {$product_id}" );

		// ── Feature 2 — qty links ─────────────────────────────────────────────
		$qty_links_raw         = get_post_meta( $product_id, self::META_QTY_LINKS, true );
		$qty_links             = ! empty( $qty_links_raw ) ? json_decode( $qty_links_raw, true ) : [];
		$data['aim_qty_links'] = is_array( $qty_links ) ? $qty_links : [];
		epb_aim_log( 'modify_shared_data: aim_qty_links = ' . wp_json_encode( $data['aim_qty_links'] ) );

		// ── Feature 3 — per-item show-qty overrides ───────────────────────────
		$overrides_raw                   = get_post_meta( $product_id, self::META_SHOW_ITEM_QTY_OVERRIDES, true );
		$overrides                       = ! empty( $overrides_raw ) ? json_decode( $overrides_raw, true ) : [];
		$data['aim_show_item_qty_overrides'] = is_array( $overrides ) ? $overrides : [];
		epb_aim_log( 'modify_shared_data: aim_show_item_qty_overrides = ' . wp_json_encode( $data['aim_show_item_qty_overrides'] ) );

		// ── Feature 4 — max from items ────────────────────────────────────────
		$max_links_raw = get_post_meta( $product_id, self::META_MAX_FROM_ITEMS, true );
		$max_links     = ! empty( $max_links_raw ) ? json_decode( $max_links_raw, true ) : [];

		if ( is_array( $max_links ) && ! empty( $max_links ) ) {
			// Enrich each link with the item's configured Max quantity so that
			// aim-frontend.js can compute the effective max without knowing the
			// bundle item structure.
			$items_data = method_exists( $product, 'get_items' ) ? $product->get_items() : [];
			if ( empty( $items_data ) ) {
				$raw        = get_post_meta( $product_id, '_items', true );
				$items_data = is_array( $raw ) ? $raw : ( ! empty( $raw ) ? json_decode( $raw, true ) : [] );
			}
			$items_list = is_array( $items_data ) ? array_values( $items_data ) : [];

			foreach ( $max_links as &$link ) {
				$target_idx    = (int) $link['target'];
				$item          = isset( $items_list[ $target_idx ] ) ? $items_list[ $target_idx ] : null;
				$link['base_max'] = ( $item && ! empty( $item['max_quantity'] ) )
					? (int) $item['max_quantity']
					: 0;
			}
			unset( $link );
		}

		$data['aim_max_from_items'] = is_array( $max_links ) ? $max_links : [];
		epb_aim_log( 'modify_shared_data: aim_max_from_items = ' . wp_json_encode( $data['aim_max_from_items'] ) );

		// Max quantity message — only pass the text when the admin has enabled it.
		$msg_enabled = get_post_meta( $product_id, self::META_MAX_QTY_MESSAGE_ENABLED, true );
		if ( 'yes' === $msg_enabled ) {
			$msg_text = get_post_meta( $product_id, self::META_MAX_QTY_MESSAGE, true );
			$data['aim_max_qty_message'] = $msg_text ?: __( 'Maximum quantity for this bundle is', 'epb-aim' );
		} else {
			$data['aim_max_qty_message'] = '';
		}

		// ── Feature 1 — hide item quantity (bundle-level) ─────────────────────
		// When Features 2, 3, or 4 are active, aim-frontend.js needs the qty
		// <input> elements present in the DOM. Using quantity_field_on_item:'false'
		// causes React to omit them entirely, so we fall back to CSS-based hiding
		// (see maybe_enqueue_hide_qty_css) in those cases.
		$hide = get_post_meta( $product_id, self::META_HIDE_QTY, true );
		if ( 'yes' === $hide ) {
			$needs_inputs_in_dom =
				! empty( $data['aim_qty_links'] ) ||
				! empty( $data['aim_show_item_qty_overrides'] ) ||
				! empty( $data['aim_max_from_items'] );

			if ( $needs_inputs_in_dom ) {
				// Defer hiding to CSS so inputs remain in the DOM.
				epb_aim_log( 'modify_shared_data: hide_item_qty deferred to CSS (JS features active)' );
			} else {
				// Safe to let React omit the inputs — no JS feature needs them.
				$data['quantity_field_on_item'] = 'false';
				epb_aim_log( 'modify_shared_data: quantity_field_on_item overridden to false' );
			}
		}

		// ── Feature 5 — bundle qty message label ─────────────────────────────
		$bundle_qty_enabled = get_post_meta( $product_id, self::META_BUNDLE_QTY_MSG_ENABLED, true );
		if ( 'yes' === $bundle_qty_enabled ) {
			$bundle_qty_text = get_post_meta( $product_id, self::META_BUNDLE_QTY_MSG_TEXT, true );
			$data['aim_bundle_qty_message'] = $bundle_qty_text ?: __( 'Qty to add:', 'epb-aim' );
		} else {
			$data['aim_bundle_qty_message'] = '';
		}
		epb_aim_log( 'modify_shared_data: aim_bundle_qty_message = ' . $data['aim_bundle_qty_message'] );

		// ── Feature 6 — no selection message ─────────────────────────────────
		$no_sel_enabled = get_post_meta( $product_id, self::META_NO_SELECTION_MSG_ENABLED, true );
		if ( 'yes' === $no_sel_enabled ) {
			$no_sel_text = get_post_meta( $product_id, self::META_NO_SELECTION_MSG_TEXT, true );
			$data['aim_no_selection_message'] = $no_sel_text ?: __( 'Please select a product for all items.', 'epb-aim' );
		} else {
			$data['aim_no_selection_message'] = '';
		}
		epb_aim_log( 'modify_shared_data: aim_no_selection_message = ' . $data['aim_no_selection_message'] );

		// ── Feature 7 — qty × unit price total ───────────────────────────────
		$data['aim_qty_total'] = ( 'yes' === get_post_meta( $product_id, self::META_QTY_TOTAL_ENABLED, true ) );
		epb_aim_log( 'modify_shared_data: aim_qty_total = ' . ( $data['aim_qty_total'] ? 'true' : 'false' ) );

		$each_msg = get_post_meta( $product_id, self::META_EACH_BUNDLE_MESSAGE, true );
		if ( '' === $each_msg || false === $each_msg ) {
			$each_msg = __( 'Each Item:', 'epb-aim' );
		}
		$data['aim_each_bundle_message'] = $each_msg;

		$total_msg = get_post_meta( $product_id, self::META_BUNDLE_TOTAL_MESSAGE, true );
		if ( '' === $total_msg || false === $total_msg ) {
			$total_msg = __( 'Total:', 'epb-aim' );
		}
		$data['aim_bundle_total_message'] = $total_msg;

		// Hardcoded "Quantity:" label — translatable so non-English sites can translate it.
		$data['aim_quantity_label'] = __( 'Quantity:', 'epb-aim' );

		// ── Feature 8 — Image Swap Rules ─────────────────────────────────────
		$swap_raw   = get_post_meta( $product_id, self::META_IMAGE_SWAP_RULES, true );
		$swap_rules = ! empty( $swap_raw ) ? json_decode( $swap_raw, true ) : [];
		if ( ! is_array( $swap_rules ) ) {
			$swap_rules = [];
		}
		foreach ( $swap_rules as &$rule ) {
			// Normalise legacy scope 'main' → 'gallery'.
			if ( isset( $rule['scope'] ) && 'main' === $rule['scope'] ) {
				$rule['scope'] = 'gallery';
			}
			$image_id              = ! empty( $rule['image_id'] ) ? (int) $rule['image_id'] : 0;
			$rule['image_url']       = $image_id ? ( wp_get_attachment_image_url( $image_id, 'full' ) ?: '' ) : '';
			$rule['image_thumb_url'] = $image_id ? ( wp_get_attachment_image_url( $image_id, 'woocommerce_gallery_thumbnail' ) ?: '' ) : '';
		}
		unset( $rule );
		$data['aim_image_swap_rules'] = $swap_rules;
		epb_aim_log( 'modify_shared_data: aim_image_swap_rules count = ' . count( $swap_rules ) );

		// Ordered list of non-optional item indices — used by JS to map each required
		// item to its corresponding gallery slide index.
		$items_raw    = get_post_meta( $product_id, '_items', true );
		$items_data   = is_array( $items_raw ) ? $items_raw : ( ! empty( $items_raw ) ? json_decode( $items_raw, true ) : [] );
		$items_list   = is_array( $items_data ) ? array_values( $items_data ) : [];
		$non_opt_order = [];
		foreach ( $items_list as $idx => $item ) {
			if ( ( $item['optional'] ?? 'false' ) !== 'true' ) {
				$non_opt_order[] = $idx;
			}
		}
		$data['aim_non_optional_order'] = $non_opt_order;
		epb_aim_log( 'modify_shared_data: aim_non_optional_order = ' . wp_json_encode( $non_opt_order ) );

		// ── Feature 9 — bundle qty +/- buttons ───────────────────────────────
		$data['aim_qty_plusminus'] = ( 'yes' === get_post_meta( $product_id, self::META_QTY_PLUSMINUS, true ) );
		epb_aim_log( 'modify_shared_data: aim_qty_plusminus = ' . ( $data['aim_qty_plusminus'] ? 'true' : 'false' ) );

		// ── Feature 9 sub: +/- button size ───────────────────────────────────
		$pm_size_raw             = get_post_meta( $product_id, self::META_QTY_PM_SIZE, true );
		$data['aim_qty_pm_size'] = ( $pm_size_raw !== '' && $pm_size_raw !== false )
			? max( 50, min( 100, (int) $pm_size_raw ) )
			: 100;
		epb_aim_log( 'modify_shared_data: aim_qty_pm_size = ' . $data['aim_qty_pm_size'] );

		// ── Feature 10 — swap price block and qty row positions ───────────────
		$data['aim_swap_positions'] = ( 'yes' === get_post_meta( $product_id, self::META_SWAP_POSITIONS, true ) );
		epb_aim_log( 'modify_shared_data: aim_swap_positions = ' . ( $data['aim_swap_positions'] ? 'true' : 'false' ) );

		// ── Feature 11 — auto-fit qty row to single line ──────────────────────
		$data['aim_autowidth_row'] = ( 'yes' === get_post_meta( $product_id, self::META_AUTOWIDTH_ROW, true ) );
		epb_aim_log( 'modify_shared_data: aim_autowidth_row = ' . ( $data['aim_autowidth_row'] ? 'true' : 'false' ) );

		// ── Logging flag ──────────────────────────────────────────────────────
		$data['aim_logging'] = 'yes' === get_option( 'epb_aim_detailed_logging', 'no' );
		epb_aim_log( 'modify_shared_data: aim_logging passed to JS = ' . ( $data['aim_logging'] ? 'true' : 'false' ) );

		return $data;
	}

	// -------------------------------------------------------------------------
	// Feature 8 — Gallery slide injection
	// -------------------------------------------------------------------------

	/**
	 * Injects additional gallery slides for required bundle items #2, #3, etc.
	 * (i.e. every non-optional item after the first one that has a gallery-scope
	 * swap rule). Fires inside .woocommerce-product-gallery__wrapper at priority
	 * 15 — before existing WC gallery thumbnails at priority 20 — so injected
	 * slides appear immediately after slide 1.
	 *
	 * Each slide is initialised with the item's configured "Default image"
	 * (item > Display > image_url). Falls back to the item's product featured
	 * image when image_url is empty. Slides without a resolvable image are
	 * skipped. JS then swaps the slide image when a matching rule fires and
	 * restores the default when no rule matches.
	 */
	public function inject_aim_gallery_slides(): void {
		if ( ! $this->is_eligible_page() ) {
			return;
		}

		$product = $this->get_bundle_product();
		if ( ! $product ) {
			return;
		}

		$product_id = $product->get_id();

		// Only proceed when there are gallery / both-scope swap rules.
		$swap_raw   = get_post_meta( $product_id, self::META_IMAGE_SWAP_RULES, true );
		$swap_rules = ! empty( $swap_raw ) ? json_decode( $swap_raw, true ) : [];
		if ( ! is_array( $swap_rules ) || empty( $swap_rules ) ) {
			return;
		}

		$gallery_targets = [];
		foreach ( $swap_rules as $rule ) {
			$scope = $rule['scope'] ?? 'item';
			if ( 'gallery' === $scope || 'main' === $scope || 'both' === $scope ) {
				$gallery_targets[ (int) $rule['target'] ] = true;
			}
		}
		if ( empty( $gallery_targets ) ) {
			return;
		}

		// Read bundle items.
		$raw        = get_post_meta( $product_id, '_items', true );
		$items_data = is_array( $raw ) ? $raw : ( ! empty( $raw ) ? json_decode( $raw, true ) : [] );
		$items_list = is_array( $items_data ) ? array_values( $items_data ) : [];

		// Collect non-optional items in index order.
		$non_optional = [];
		foreach ( $items_list as $idx => $item ) {
			if ( ( $item['optional'] ?? 'false' ) !== 'true' ) {
				$non_optional[] = [ 'index' => $idx, 'item' => $item ];
			}
		}

		// Skip item #1 (index 0 in $non_optional) — it is already the main slide.
		for ( $i = 1; $i < count( $non_optional ); $i++ ) {
			$entry    = $non_optional[ $i ];
			$item_idx = $entry['index'];
			$item     = $entry['item'];

			// Only inject a slide when this item has a gallery-scope rule.
			if ( empty( $gallery_targets[ $item_idx ] ) ) {
				continue;
			}

			// Resolve the default image URL (item "Display > Default image" field).
			$image_url = ! empty( $item['image_url'] ) ? $item['image_url'] : '';
			if ( empty( $image_url ) && ! empty( $item['product'] ) ) {
				$image_url = get_the_post_thumbnail_url( (int) $item['product'], 'woocommerce_single' ) ?: '';
			}
			if ( empty( $image_url ) ) {
				epb_aim_log( "inject_aim_gallery_slides: skipping item {$item_idx} — no default image" );
				continue;
			}

			// Alt text from the product title.
			$alt = ! empty( $item['product'] ) ? ( get_the_title( (int) $item['product'] ) ?: '' ) : '';

			$esc_url = esc_url( $image_url );
			$esc_alt = esc_attr( $alt );

			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped above.
			echo '<div data-thumb="' . $esc_url . '" data-thumb-alt="' . $esc_alt . '"'
				. ' class="woocommerce-product-gallery__image aim-gallery-slide"'
				. ' data-aim-item-idx="' . (int) $item_idx . '">';
			echo '<a href="' . $esc_url . '">';
			echo '<img src="' . $esc_url . '" alt="' . $esc_alt . '"'
				. ' data-caption="" data-src="' . $esc_url . '"'
				. ' data-large_image="' . $esc_url . '"'
				. ' data-large_image_width="" data-large_image_height="" />';
			echo '</a>';
			echo '</div>';
			// phpcs:enable

			epb_aim_log( "inject_aim_gallery_slides: injected slide for item {$item_idx}" );
		}
	}

	// -------------------------------------------------------------------------
	// Frontend JS (Features 2, 3, 4)
	// -------------------------------------------------------------------------

	public function maybe_enqueue_frontend_js(): void {
		epb_aim_log( 'maybe_enqueue_frontend_js: called. is_eligible=' . ( $this->is_eligible_page() ? 'yes' : 'no' ) );

		if ( ! $this->is_eligible_page() ) {
			return;
		}

		$product = $this->get_bundle_product();
		if ( ! $product ) {
			epb_aim_log( 'maybe_enqueue_frontend_js: no bundle product — skipping' );
			return;
		}

		$product_id = $product->get_id();

		// Feature 2.
		$qty_links_raw = get_post_meta( $product_id, self::META_QTY_LINKS, true );
		$qty_links     = ! empty( $qty_links_raw ) ? json_decode( $qty_links_raw, true ) : [];

		// Feature 3 — only needs JS when Feature 1 (hide) is also active.
		$hide            = get_post_meta( $product_id, self::META_HIDE_QTY, true );
		$overrides_raw   = get_post_meta( $product_id, self::META_SHOW_ITEM_QTY_OVERRIDES, true );
		$overrides       = ! empty( $overrides_raw ) ? json_decode( $overrides_raw, true ) : [];
		$needs_overrides = ( 'yes' === $hide ) && ! empty( $overrides );

		// Feature 4.
		$max_links_raw = get_post_meta( $product_id, self::META_MAX_FROM_ITEMS, true );
		$max_links     = ! empty( $max_links_raw ) ? json_decode( $max_links_raw, true ) : [];

		// Feature 5.
		$bundle_qty_enabled = get_post_meta( $product_id, self::META_BUNDLE_QTY_MSG_ENABLED, true );
		$needs_bundle_qty_msg = ( 'yes' === $bundle_qty_enabled );

		// Feature 6.
		$no_sel_enabled = get_post_meta( $product_id, self::META_NO_SELECTION_MSG_ENABLED, true );
		$needs_no_sel_msg = ( 'yes' === $no_sel_enabled );

		// Feature 7.
		$needs_qty_total = ( 'yes' === get_post_meta( $product_id, self::META_QTY_TOTAL_ENABLED, true ) );

		// Feature 8.
		$swap_raw        = get_post_meta( $product_id, self::META_IMAGE_SWAP_RULES, true );
		$swap_rules      = ! empty( $swap_raw ) ? json_decode( $swap_raw, true ) : [];
		$has_swap_rules  = is_array( $swap_rules ) && ! empty( $swap_rules );

		// Feature 9.
		$needs_qty_plusminus = ( 'yes' === get_post_meta( $product_id, self::META_QTY_PLUSMINUS, true ) );

		// Feature 10.
		$needs_swap_positions = ( 'yes' === get_post_meta( $product_id, self::META_SWAP_POSITIONS, true ) );

		// Feature 11.
		$needs_autowidth_row = ( 'yes' === get_post_meta( $product_id, self::META_AUTOWIDTH_ROW, true ) );

		epb_aim_log( 'maybe_enqueue_frontend_js: qty_links=' . wp_json_encode( $qty_links ) .
			' needs_overrides=' . ( $needs_overrides ? 'yes' : 'no' ) .
			' max_links=' . wp_json_encode( $max_links ) .
			' needs_bundle_qty_msg=' . ( $needs_bundle_qty_msg ? 'yes' : 'no' ) .
			' needs_no_sel_msg=' . ( $needs_no_sel_msg ? 'yes' : 'no' ) .
			' needs_qty_total=' . ( $needs_qty_total ? 'yes' : 'no' ) .
			' has_swap_rules=' . ( $has_swap_rules ? 'yes' : 'no' ) .
			' needs_qty_plusminus=' . ( $needs_qty_plusminus ? 'yes' : 'no' ) .
			' needs_swap_positions=' . ( $needs_swap_positions ? 'yes' : 'no' ) .
			' needs_autowidth_row=' . ( $needs_autowidth_row ? 'yes' : 'no' ) );

		if ( empty( $qty_links ) && ! $needs_overrides && empty( $max_links ) && ! $needs_bundle_qty_msg && ! $needs_no_sel_msg && ! $needs_qty_total && ! $has_swap_rules && ! $needs_qty_plusminus && ! $needs_swap_positions && ! $needs_autowidth_row ) {
			epb_aim_log( 'maybe_enqueue_frontend_js: nothing to do — skipping enqueue' );
			return;
		}

		wp_enqueue_style(
			'epb-aim-frontend',
			EPB_AIM_PLUGIN_URL . 'assets/aim-frontend.css',
			[],
			EPB_AIM_VERSION
		);

		wp_enqueue_script(
			'epb-aim-frontend',
			EPB_AIM_PLUGIN_URL . 'assets/aim-frontend.js',
			[ 'asnp-easy-product-bundles-shared' ],
			EPB_AIM_VERSION,
			true
		);

		epb_aim_log( 'maybe_enqueue_frontend_js: enqueued epb-aim-frontend' );
	}

	// -------------------------------------------------------------------------
	// Feature 1 CSS hiding (used when JS features also need DOM inputs)
	// -------------------------------------------------------------------------

	/**
	 * When "Hide item quantity" (Feature 1) is active alongside any JS feature
	 * (Features 2, 3, or 4) that needs the qty <input> elements in the DOM,
	 * we cannot use quantity_field_on_item:'false' (React would omit the inputs).
	 *
	 * Instead we inject a tiny inline stylesheet that hides .asnp-product-quantity-field
	 * visually while leaving the <input> elements present.
	 *
	 * For Feature 3 (per-item show overrides), aim-frontend.js then re-shows
	 * specific items by overriding the !important rule via inline style.
	 */
	public function maybe_enqueue_hide_qty_css(): void {
		if ( ! $this->is_eligible_page() ) {
			return;
		}

		$product = $this->get_bundle_product();
		if ( ! $product ) {
			return;
		}

		$product_id = $product->get_id();

		if ( 'yes' !== get_post_meta( $product_id, self::META_HIDE_QTY, true ) ) {
			return;
		}

		// CSS hiding is only needed when a JS feature also needs DOM inputs.
		// Otherwise quantity_field_on_item:'false' handles it via React.
		$qty_links_raw = get_post_meta( $product_id, self::META_QTY_LINKS, true );
		$qty_links     = ! empty( $qty_links_raw ) ? json_decode( $qty_links_raw, true ) : [];

		$overrides_raw = get_post_meta( $product_id, self::META_SHOW_ITEM_QTY_OVERRIDES, true );
		$overrides     = ! empty( $overrides_raw ) ? json_decode( $overrides_raw, true ) : [];

		$max_links_raw = get_post_meta( $product_id, self::META_MAX_FROM_ITEMS, true );
		$max_links     = ! empty( $max_links_raw ) ? json_decode( $max_links_raw, true ) : [];

		if ( empty( $qty_links ) && empty( $overrides ) && empty( $max_links ) ) {
			return;
		}

		wp_add_inline_style(
			'asnp-easy-product-bundles-product-bundle',
			'.asnp-product-quantity-field { display: none !important; }'
		);

		epb_aim_log( 'maybe_enqueue_hide_qty_css: injected CSS to hide .asnp-product-quantity-field' );
	}

	// -------------------------------------------------------------------------
	// Checkout block asset suppression
	// -------------------------------------------------------------------------

	/**
	 * Removes the base plugin's checkout-block CSS and JS on every page that
	 * doesn't actually render a WooCommerce checkout or cart — e.g. the homepage.
	 * The base plugin enqueues these unconditionally, causing render-blocking
	 * requests and a chained dependency delay on non-checkout pages.
	 */
	public function dequeue_checkout_assets_where_unneeded(): void {
		if ( is_checkout() || is_cart() ) {
			return;
		}
		wp_dequeue_style( 'wepb-checkout-integration' );
		wp_deregister_style( 'wepb-checkout-integration' );
		wp_dequeue_script( 'wepb-checkout-integration' );
		wp_deregister_script( 'wepb-checkout-integration' );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns true only on single product pages that are NOT the site homepage.
	 */
	private function is_eligible_page(): bool {
		if ( is_front_page() || is_home() ) {
			return false;
		}
		return is_product();
	}

	/**
	 * Returns the current page's WC product if it is an easy_product_bundle,
	 * otherwise null.
	 */
	private function get_bundle_product(): ?\WC_Product {
		$product_id = get_the_ID();
		if ( ! $product_id ) {
			return null;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || $product->get_type() !== self::BUNDLE_TYPE ) {
			return null;
		}

		return $product;
	}
}
