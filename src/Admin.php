<?php
/**
 * Admin class — product editor integration.
 *
 * Adds a "Product Bundles AIM" tab to the WooCommerce product data area for
 * easy_product_bundle products. Contains:
 *   - Feature 1: "Hide item quantity" checkbox (per bundle).
 *   - Feature 2: "Quantity from items" link table (per bundle).
 *   - Feature 3: Per-item "Show qty override" table.
 *   - Feature 4: "Max from items" link table (per bundle).
 *   - Global: "Detailed logging" checkbox (plugin-wide option).
 *
 * @package EasyProductBundlesAIM
 */

namespace EasyProductBundlesAIM;

defined( 'ABSPATH' ) || exit;

class Admin {

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
	const OPT_LOGGING                      = 'epb_aim_detailed_logging';

	public function init(): void {
		add_filter( 'woocommerce_product_data_tabs', [ $this, 'add_product_tab' ] );
		add_action( 'woocommerce_product_data_panels', [ $this, 'render_product_panel' ] );
		// Priority 50 — run after the bundle plugin's own save hooks.
		add_action( 'woocommerce_process_product_meta', [ $this, 'save_product_data' ], 50 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	// -------------------------------------------------------------------------
	// Tab registration
	// -------------------------------------------------------------------------

	/**
	 * Add "Product Bundles AIM" tab alongside the existing product data tabs.
	 * The 'show_if_easy_product_bundle' class ensures WooCommerce JS hides it
	 * for all other product types.
	 */
	public function add_product_tab( array $tabs ): array {
		$tabs['epb_aim'] = [
			'label'    => __( 'Product Bundles AIM', 'epb-aim' ),
			'target'   => 'epb_aim_product_data',
			'class'    => [ 'show_if_easy_product_bundle' ],
			'priority' => 80,
		];
		return $tabs;
	}

	// -------------------------------------------------------------------------
	// Panel rendering
	// -------------------------------------------------------------------------

	public function render_product_panel(): void {
		global $post;
		$product_id = $post ? $post->ID : 0;

		$hide_item_qty       = get_post_meta( $product_id, self::META_HIDE_QTY, true ) ?: 'no';
		$qty_plusminus       = get_post_meta( $product_id, self::META_QTY_PLUSMINUS, true ) ?: 'no';
		$qty_pm_size         = (int) ( get_post_meta( $product_id, self::META_QTY_PM_SIZE, true ) ?: 100 );
		$swap_positions      = get_post_meta( $product_id, self::META_SWAP_POSITIONS, true ) ?: 'no';
		$autowidth_row       = get_post_meta( $product_id, self::META_AUTOWIDTH_ROW, true ) ?: 'no';
		$qty_total_enabled   = get_post_meta( $product_id, self::META_QTY_TOTAL_ENABLED, true ) ?: 'no';
		$qty_links_json      = get_post_meta( $product_id, self::META_QTY_LINKS, true ) ?: '[]';
		$max_from_items_json = get_post_meta( $product_id, self::META_MAX_FROM_ITEMS, true ) ?: '[]';
		// Default text pre-filled when not yet saved.
		$max_qty_message         = get_post_meta( $product_id, self::META_MAX_QTY_MESSAGE, true );
		if ( '' === $max_qty_message || false === $max_qty_message ) {
			$max_qty_message = __( 'Maximum quantity for this bundle is', 'epb-aim' );
		}
		$max_qty_message_enabled = get_post_meta( $product_id, self::META_MAX_QTY_MESSAGE_ENABLED, true ) ?: 'no';
		$show_overrides_raw = get_post_meta( $product_id, self::META_SHOW_ITEM_QTY_OVERRIDES, true ) ?: '[]';
		$show_overrides     = json_decode( $show_overrides_raw, true );
		if ( ! is_array( $show_overrides ) ) {
			$show_overrides = [];
		}
		$bundle_qty_msg_enabled  = get_post_meta( $product_id, self::META_BUNDLE_QTY_MSG_ENABLED, true ) ?: 'no';
		$bundle_qty_msg_text     = get_post_meta( $product_id, self::META_BUNDLE_QTY_MSG_TEXT, true );
		if ( '' === $bundle_qty_msg_text || false === $bundle_qty_msg_text ) {
			$bundle_qty_msg_text = '';
		}

		$no_selection_msg_enabled = get_post_meta( $product_id, self::META_NO_SELECTION_MSG_ENABLED, true ) ?: 'no';
		$no_selection_msg_text    = get_post_meta( $product_id, self::META_NO_SELECTION_MSG_TEXT, true );
		if ( '' === $no_selection_msg_text || false === $no_selection_msg_text ) {
			$no_selection_msg_text = '';
		}

		// Feature 7 — pre-fill defaults when not yet saved.
		$each_bundle_message = get_post_meta( $product_id, self::META_EACH_BUNDLE_MESSAGE, true );
		if ( '' === $each_bundle_message || false === $each_bundle_message ) {
			$each_bundle_message = __( 'Each Item:', 'epb-aim' );
		}
		$bundle_total_message = get_post_meta( $product_id, self::META_BUNDLE_TOTAL_MESSAGE, true );
		if ( '' === $bundle_total_message || false === $bundle_total_message ) {
			$bundle_total_message = __( 'Total:', 'epb-aim' );
		}

		$image_swap_raw = get_post_meta( $product_id, self::META_IMAGE_SWAP_RULES, true ) ?: '[]';

		$items   = $this->get_bundle_items_for_js( $product_id );
		$logging = get_option( self::OPT_LOGGING, 'no' );

		wp_nonce_field( 'epb_aim_save_product_data', 'epb_aim_nonce' );
		?>
		<div id="epb_aim_product_data" class="panel woocommerce_options_panel show_if_easy_product_bundle">

			<?php /* ── Feature 1: Hide item quantity (bundle-level) ─────────── */ ?>
			<div class="options_group">
				<h4 class="epb-aim-section-title"><?php esc_html_e( 'Item Display', 'epb-aim' ); ?></h4>
				<p class="form-field">
					<label for="aim_hide_item_qty">
						<input type="checkbox"
							id="aim_hide_item_qty"
							name="aim_hide_item_qty"
							value="yes"
							<?php checked( $hide_item_qty, 'yes' ); ?>>
						<?php esc_html_e( 'Hide item quantity', 'epb-aim' ); ?>
					</label>
					<span class="description">
						<?php esc_html_e( 'Hides the quantity input/stepper for all items within this bundle on the product page. The main bundle quantity field remains visible.', 'epb-aim' ); ?>
					</span>
				</p>
				<p class="form-field">
					<label for="aim_qty_plusminus">
						<input type="checkbox"
							id="aim_qty_plusminus"
							name="aim_qty_plusminus"
							value="yes"
							<?php checked( $qty_plusminus, 'yes' ); ?>>
						<?php esc_html_e( 'Use +/- buttons for bundle quantity', 'epb-aim' ); ?>
					</label>
					<span class="description">
						<?php esc_html_e( 'Replaces the browser up/down arrows on the main bundle quantity field with − and + buttons matching the style of the bundle item quantity controls.', 'epb-aim' ); ?>
					</span>
				</p>
				<p class="form-field aim-qty-pm-sub-field">
					<label for="aim_qty_pm_size"><?php esc_html_e( '+/- Button Size', 'epb-aim' ); ?></label>
					<input type="number"
						id="aim_qty_pm_size"
						name="aim_qty_pm_size"
						value="<?php echo esc_attr( $qty_pm_size ); ?>"
						min="50"
						max="100"
						step="1"
						style="width:70px;">
					<span>%</span>
					<span class="description">
						<?php esc_html_e( 'Scale the +/- buttons as a percentage of their natural size (50–100). Default: 100 (no change). Only applies when +/- buttons are enabled above.', 'epb-aim' ); ?>
					</span>
				</p>
				<p class="form-field">
					<label for="aim_swap_positions">
						<input type="checkbox"
							id="aim_swap_positions"
							name="aim_swap_positions"
							value="yes"
							<?php checked( $swap_positions, 'yes' ); ?>>
						<?php esc_html_e( 'Swap price block and qty row positions', 'epb-aim' ); ?>
					</label>
					<span class="description">
						<?php esc_html_e( 'Moves the price/total block (asnp-totalPrice-wrapper) to appear after the quantity and add-to-cart row instead of before it.', 'epb-aim' ); ?>
					</span>
				</p>
				<p class="form-field">
					<label for="aim_autowidth_row">
						<input type="checkbox"
							id="aim_autowidth_row"
							name="aim_autowidth_row"
							value="yes"
							<?php checked( $autowidth_row, 'yes' ); ?>>
						<?php esc_html_e( 'Auto-fit qty row to single line', 'epb-aim' ); ?>
					</label>
					<span class="description">
						<?php esc_html_e( 'Automatically reduces the Add to Cart button width so the quantity label, input, and button all fit on one line. The button is never narrowed below its text width. Has no effect if the minimum button width still does not fit.', 'epb-aim' ); ?>
					</span>
				</p>
				<p class="form-field">
					<label for="aim_qty_total_enabled">
						<input type="checkbox"
							id="aim_qty_total_enabled"
							name="aim_qty_total_enabled"
							value="yes"
							<?php checked( $qty_total_enabled, 'yes' ); ?>>
						<?php esc_html_e( 'Show qty × unit price total', 'epb-aim' ); ?>
					</label>
					<span class="description">
						<?php esc_html_e( 'Shows a live price breakdown in the bundle price wrapper: unit price row (title replaced), quantity row, and computed total row.', 'epb-aim' ); ?>
					</span>
				</p>
				<p class="form-field aim-qty-total-sub-field">
					<label for="aim_each_bundle_message"><?php esc_html_e( 'Each Bundle Message', 'epb-aim' ); ?></label>
					<input type="text"
						id="aim_each_bundle_message"
						name="aim_each_bundle_message"
						value="<?php echo esc_attr( $each_bundle_message ); ?>"
						<?php if ( 'yes' !== $qty_total_enabled ) : ?>disabled<?php endif; ?>>
					<span class="description">
						<?php esc_html_e( 'Replaces the price title (e.g. "Total:", "Buy all for:") on the unit price row. Default: "Each Item:"', 'epb-aim' ); ?>
					</span>
				</p>
				<p class="form-field aim-qty-total-sub-field">
					<label for="aim_bundle_total_message"><?php esc_html_e( 'Bundle Total Message', 'epb-aim' ); ?></label>
					<input type="text"
						id="aim_bundle_total_message"
						name="aim_bundle_total_message"
						value="<?php echo esc_attr( $bundle_total_message ); ?>"
						<?php if ( 'yes' !== $qty_total_enabled ) : ?>disabled<?php endif; ?>>
					<span class="description">
						<?php esc_html_e( 'Label shown on the left of the computed total row. Default: "Total:"', 'epb-aim' ); ?>
					</span>
				</p>
			</div>

			<?php /* ── Feature 3: Per-item show-qty overrides ───────────────── */ ?>
			<div class="options_group">
				<h4 class="epb-aim-section-title"><?php esc_html_e( 'Per-Item Quantity Display', 'epb-aim' ); ?></h4>
				<p class="epb-aim-section-desc">
					<?php esc_html_e( 'Override the bundle-level "Hide item quantity" setting for specific items. When checked, that item\'s quantity will always be visible even if the bundle-level option is enabled.', 'epb-aim' ); ?>
				</p>

				<?php if ( empty( $items ) ) : ?>
					<p class="epb-aim-no-items">
						<?php esc_html_e( 'No bundle items found. Add items to this bundle and save, then return here.', 'epb-aim' ); ?>
					</p>
				<?php else : ?>
					<div class="aim-item-settings-wrap">
						<table class="aim-item-settings-table widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Item', 'epb-aim' ); ?></th>
									<th><?php esc_html_e( 'Show item quantity', 'epb-aim' ); ?><br>
										<span class="aim-col-hint"><?php esc_html_e( '(overrides bundle-level hide)', 'epb-aim' ); ?></span>
									</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $items as $item ) : ?>
								<tr>
									<td><?php echo esc_html( $item['label'] ); ?></td>
									<td class="aim-center-cell">
										<input type="checkbox"
											name="aim_show_item_qty_override[]"
											value="<?php echo esc_attr( $item['index'] ); ?>"
											<?php checked( in_array( $item['index'], $show_overrides, true ) ); ?>>
									</td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>

			<?php /* ── Feature 2: Quantity from items ───────────────────────── */ ?>
			<div class="options_group">
				<h4 class="epb-aim-section-title"><?php esc_html_e( 'Quantity from Items', 'epb-aim' ); ?></h4>
				<p class="epb-aim-section-desc">
					<?php esc_html_e( 'Set a bundle item\'s quantity to equal the sum of other items\' quantities in real time on the product page.', 'epb-aim' ); ?>
					<br>
					<em><?php esc_html_e( 'Save the bundle first to ensure item names are up to date here.', 'epb-aim' ); ?></em>
				</p>

				<div id="aim-qty-links-wrap">
					<?php if ( empty( $items ) ) : ?>
						<p class="epb-aim-no-items">
							<?php esc_html_e( 'No bundle items found. Add items to this bundle and save, then return here to configure quantity links.', 'epb-aim' ); ?>
						</p>
					<?php else : ?>
						<table class="aim-links-table widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'This item\'s quantity…', 'epb-aim' ); ?></th>
									<th><?php esc_html_e( '…equals the sum of these items:', 'epb-aim' ); ?></th>
									<th class="aim-col-remove"></th>
								</tr>
							</thead>
							<tbody id="aim-qty-links-rows">
								<?php /* Rows are populated by aim-admin.js */ ?>
							</tbody>
						</table>
						<button type="button" id="aim-add-qty-link" class="button">
							<?php esc_html_e( '+ Add Quantity Link', 'epb-aim' ); ?>
						</button>
					<?php endif; ?>
				</div>

				<?php /* Hidden field — JS serialises the link table into this before submit. */ ?>
				<input type="hidden"
					id="aim_item_qty_links"
					name="aim_item_qty_links"
					value="<?php echo esc_attr( $qty_links_json ); ?>">
			</div>

			<?php /* ── Feature 4: Max from items ──────────────────────────────── */ ?>
			<div class="options_group">
				<h4 class="epb-aim-section-title"><?php esc_html_e( 'Max from Items', 'epb-aim' ); ?></h4>
				<p class="epb-aim-section-desc">
					<?php esc_html_e( 'Reduce an item\'s configured Max quantity by the sum of other items\' quantities in real time. The effective max will never drop below 1.', 'epb-aim' ); ?>
					<br>
					<em><?php esc_html_e( 'The item must have a Max quantity set in the bundle editor. Save the bundle first to ensure item names are up to date here.', 'epb-aim' ); ?></em>
				</p>

				<div id="aim-max-links-wrap">
					<?php if ( empty( $items ) ) : ?>
						<p class="epb-aim-no-items">
							<?php esc_html_e( 'No bundle items found. Add items to this bundle and save, then return here to configure max links.', 'epb-aim' ); ?>
						</p>
					<?php else : ?>
						<table class="aim-links-table widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'This item\'s max quantity…', 'epb-aim' ); ?></th>
									<th><?php esc_html_e( '…is reduced by the sum of:', 'epb-aim' ); ?></th>
									<th class="aim-col-remove"></th>
								</tr>
							</thead>
							<tbody id="aim-max-links-rows">
								<?php /* Rows are populated by aim-admin.js */ ?>
							</tbody>
						</table>
						<button type="button" id="aim-add-max-link" class="button">
							<?php esc_html_e( '+ Add Max Link', 'epb-aim' ); ?>
						</button>
					<?php endif; ?>
				</div>

				<input type="hidden"
					id="aim_max_from_items"
					name="aim_max_from_items"
					value="<?php echo esc_attr( $max_from_items_json ); ?>">

				<p class="form-field aim-max-msg-field">
					<label class="aim-max-msg-enable-label">
						<input type="checkbox"
							id="aim_max_qty_message_enabled"
							name="aim_max_qty_message_enabled"
							value="yes"
							<?php checked( $max_qty_message_enabled, 'yes' ); ?>>
						<?php esc_html_e( 'Show max quantity message', 'epb-aim' ); ?>
					</label>
					<input type="text"
						id="aim_max_qty_message"
						name="aim_max_qty_message"
						value="<?php echo esc_attr( $max_qty_message ); ?>"
						<?php if ( 'yes' !== $max_qty_message_enabled ) : ?>disabled<?php endif ?>>

					<span class="description">
						<?php esc_html_e( 'Shown on the product page below each "Max from Items" target item. The current effective max is appended automatically, e.g. "Maximum quantity for this bundle is 4".', 'epb-aim' ); ?>
					</span>
				</p>
			</div>

			<?php /* ── Feature 5: Bundle Qty Message ────────────────────────── */ ?>
			<div class="options_group">
				<h4 class="epb-aim-section-title"><?php esc_html_e( 'Bundle Qty Message', 'epb-aim' ); ?></h4>
				<p class="form-field aim-bundle-qty-msg-field">
					<label class="aim-max-msg-enable-label">
						<input type="checkbox"
							id="aim_bundle_qty_message_enabled"
							name="aim_bundle_qty_message_enabled"
							value="yes"
							<?php checked( $bundle_qty_msg_enabled, 'yes' ); ?>>
						<?php esc_html_e( 'Bundle Qty Message', 'epb-aim' ); ?>
					</label>
					<input type="text"
						id="aim_bundle_qty_message_text"
						name="aim_bundle_qty_message_text"
						value="<?php echo esc_attr( $bundle_qty_msg_text ); ?>"
						placeholder="<?php esc_attr_e( 'Qty to add:', 'epb-aim' ); ?>"
						<?php if ( 'yes' !== $bundle_qty_msg_enabled ) : ?>disabled<?php endif; ?>>
					<span class="description">
						<?php esc_html_e( 'When enabled, displays a text label immediately before the main bundle quantity input on the product page. If no text is entered the default "Qty to add:" is used.', 'epb-aim' ); ?>
					</span>
				</p>
			</div>

			<?php /* ── Feature 6: No Selection Message ──────────────────────── */ ?>
			<div class="options_group">
				<h4 class="epb-aim-section-title"><?php esc_html_e( 'No Selection Message', 'epb-aim' ); ?></h4>
				<p class="form-field aim-no-selection-msg-field">
					<label class="aim-max-msg-enable-label">
						<input type="checkbox"
							id="aim_no_selection_message_enabled"
							name="aim_no_selection_message_enabled"
							value="yes"
							<?php checked( $no_selection_msg_enabled, 'yes' ); ?>>
						<?php esc_html_e( 'No Selection Message', 'epb-aim' ); ?>
					</label>
					<input type="text"
						id="aim_no_selection_message_text"
						name="aim_no_selection_message_text"
						value="<?php echo esc_attr( $no_selection_msg_text ); ?>"
						placeholder="<?php esc_attr_e( 'Please select a product for all items.', 'epb-aim' ); ?>"
						<?php if ( 'yes' !== $no_selection_msg_enabled ) : ?>disabled<?php endif; ?>>
					<span class="description">
						<?php esc_html_e( 'When enabled, replaces the default "Please select a product for all items." alert text on the product page. If no text is entered the default message is used. Only replaces the default text — any other alert messages are left unchanged.', 'epb-aim' ); ?>
					</span>
				</p>
			</div>

			<?php /* ── Feature 8: Image Swap Rules ───────────────────────────── */ ?>
			<div class="options_group">
				<h4 class="epb-aim-section-title"><?php esc_html_e( 'Image Swap Rules', 'epb-aim' ); ?></h4>
				<p class="epb-aim-section-desc">
					<?php esc_html_e( 'Change a non-optional item\'s image based on which optional items are selected. Each rule specifies a target item, which optional items must be selected, and the image to display.', 'epb-aim' ); ?>
				</p>

				<div id="aim-image-swap-wrap">
					<?php if ( empty( $items ) ) : ?>
						<p class="epb-aim-no-items">
							<?php esc_html_e( 'No bundle items found. Add items to this bundle and save, then return here.', 'epb-aim' ); ?>
						</p>
					<?php else : ?>
						<?php
						$non_optional_count = count( array_filter( $items, function ( $it ) { return empty( $it['is_optional'] ); } ) );
						$optional_count     = count( array_filter( $items, function ( $it ) { return ! empty( $it['is_optional'] ); } ) );
						?>
						<?php if ( 0 === $non_optional_count || 0 === $optional_count ) : ?>
							<p class="epb-aim-no-items">
								<?php esc_html_e( 'Image swap requires at least one non-optional item and at least one optional item in this bundle.', 'epb-aim' ); ?>
							</p>
						<?php else : ?>
							<table class="aim-links-table aim-swap-table widefat striped">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Change image of…', 'epb-aim' ); ?></th>
										<th><?php esc_html_e( 'When these optional items are selected:', 'epb-aim' ); ?></th>
										<th class="aim-col-scope"><?php esc_html_e( 'Swap:', 'epb-aim' ); ?></th>
										<th><?php esc_html_e( 'Show image:', 'epb-aim' ); ?></th>
										<th class="aim-col-remove"></th>
									</tr>
								</thead>
								<tbody id="aim-image-swap-rows">
									<?php /* Rows are populated by aim-admin.js */ ?>
								</tbody>
							</table>
							<button type="button" id="aim-add-image-swap-rule" class="button">
								<?php esc_html_e( '+ Add Image Swap Rule', 'epb-aim' ); ?>
							</button>
						<?php endif; ?>
					<?php endif; ?>
				</div>

				<input type="hidden"
					id="aim_image_swap_rules"
					name="aim_image_swap_rules"
					value="<?php echo esc_attr( $image_swap_raw ); ?>">
			</div>

		<?php /* ── Global: Detailed logging ─────────────────────────────── */ ?>
			<div class="options_group">
				<h4 class="epb-aim-section-title"><?php esc_html_e( 'Plugin Settings', 'epb-aim' ); ?></h4>
				<p class="form-field">
					<label for="aim_detailed_logging">
						<input type="checkbox"
							id="aim_detailed_logging"
							name="aim_detailed_logging"
							value="yes"
							<?php checked( $logging, 'yes' ); ?>>
						<?php esc_html_e( 'Detailed logging', 'epb-aim' ); ?>
					</label>
					<span class="description">
						<?php esc_html_e( 'When enabled, writes debug output to the WordPress debug.log and to the browser console on product pages. This is a global setting — it applies to all bundles.', 'epb-aim' ); ?>
					</span>
				</p>
			</div>

		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Save
	// -------------------------------------------------------------------------

	public function save_product_data( int $product_id ): void {
		// Only act on bundle products.
		$product = wc_get_product( $product_id );
		if ( ! $product || $product->get_type() !== self::BUNDLE_TYPE ) {
			return;
		}

		// Verify nonce.
		if (
			! isset( $_POST['epb_aim_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['epb_aim_nonce'] ), 'epb_aim_save_product_data' )
		) {
			return;
		}

		$this->save_hide_item_qty( $product_id );
		$this->save_qty_plusminus( $product_id );
		$this->save_qty_pm_size( $product_id );
		$this->save_swap_positions( $product_id );
		$this->save_autowidth_row( $product_id );
		$this->save_qty_total_enabled( $product_id );
		$this->save_each_bundle_message( $product_id );
		$this->save_bundle_total_message( $product_id );
		$this->save_show_item_qty_overrides( $product_id );
		$this->save_qty_links( $product_id );
		$this->save_max_from_items( $product_id );
		$this->save_max_qty_message( $product_id );
		$this->save_bundle_qty_message( $product_id );
		$this->save_no_selection_message( $product_id );
		$this->save_image_swap_rules( $product_id );
		$this->save_logging_option();

		// Must run after save_qty_links() so the updated links are in the DB,
		// and after the base plugin has already written _items (priority 10).
		$this->enforce_qty_link_item_settings( $product_id );
	}

	private function save_swap_positions( int $product_id ): void {
		$value = ( isset( $_POST['aim_swap_positions'] ) && 'yes' === $_POST['aim_swap_positions'] )
			? 'yes'
			: 'no';
		update_post_meta( $product_id, self::META_SWAP_POSITIONS, $value );
		epb_aim_log( "save: _aim_swap_positions = {$value} for product {$product_id}" );
	}

	private function save_qty_pm_size( int $product_id ): void {
		$raw   = isset( $_POST['aim_qty_pm_size'] ) ? (int) $_POST['aim_qty_pm_size'] : 100;
		$value = max( 50, min( 100, $raw ) );
		update_post_meta( $product_id, self::META_QTY_PM_SIZE, $value );
		epb_aim_log( "save: _aim_qty_pm_size = {$value} for product {$product_id}" );
	}

	private function save_autowidth_row( int $product_id ): void {
		$value = ( isset( $_POST['aim_autowidth_row'] ) && 'yes' === $_POST['aim_autowidth_row'] )
			? 'yes'
			: 'no';
		update_post_meta( $product_id, self::META_AUTOWIDTH_ROW, $value );
		epb_aim_log( "save: _aim_autowidth_row = {$value} for product {$product_id}" );
	}

	private function save_qty_plusminus( int $product_id ): void {
		$value = ( isset( $_POST['aim_qty_plusminus'] ) && 'yes' === $_POST['aim_qty_plusminus'] )
			? 'yes'
			: 'no';
		update_post_meta( $product_id, self::META_QTY_PLUSMINUS, $value );
		epb_aim_log( "save: _aim_qty_plusminus = {$value} for product {$product_id}" );
	}

	private function save_hide_item_qty( int $product_id ): void {
		$value = ( isset( $_POST['aim_hide_item_qty'] ) && 'yes' === $_POST['aim_hide_item_qty'] )
			? 'yes'
			: 'no';
		update_post_meta( $product_id, self::META_HIDE_QTY, $value );
		epb_aim_log( "save: _aim_hide_item_qty = {$value} for product {$product_id}" );
	}

	private function save_qty_total_enabled( int $product_id ): void {
		$value = ( isset( $_POST['aim_qty_total_enabled'] ) && 'yes' === $_POST['aim_qty_total_enabled'] )
			? 'yes'
			: 'no';
		update_post_meta( $product_id, self::META_QTY_TOTAL_ENABLED, $value );
		epb_aim_log( "save: _aim_qty_total_enabled = {$value} for product {$product_id}" );
	}

	private function save_each_bundle_message( int $product_id ): void {
		$text = isset( $_POST['aim_each_bundle_message'] )
			? sanitize_text_field( wp_unslash( $_POST['aim_each_bundle_message'] ) )
			: '';
		if ( '' === $text ) {
			$text = __( 'Each Item:', 'epb-aim' );
		}
		update_post_meta( $product_id, self::META_EACH_BUNDLE_MESSAGE, $text );
		epb_aim_log( "save: _aim_each_bundle_message = '{$text}' for product {$product_id}" );
	}

	private function save_bundle_total_message( int $product_id ): void {
		$text = isset( $_POST['aim_bundle_total_message'] )
			? sanitize_text_field( wp_unslash( $_POST['aim_bundle_total_message'] ) )
			: '';
		if ( '' === $text ) {
			$text = __( 'Total:', 'epb-aim' );
		}
		update_post_meta( $product_id, self::META_BUNDLE_TOTAL_MESSAGE, $text );
		epb_aim_log( "save: _aim_bundle_total_message = '{$text}' for product {$product_id}" );
	}

	private function save_show_item_qty_overrides( int $product_id ): void {
		// Checkboxes with name="aim_show_item_qty_override[]" — PHP gives us an
		// array of the checked values, or nothing if all are unchecked.
		$submitted = isset( $_POST['aim_show_item_qty_override'] )
			? array_map( 'intval', (array) $_POST['aim_show_item_qty_override'] )
			: [];

		$current_items = $this->get_bundle_items_for_js( $product_id );
		$valid_indices = array_column( $current_items, 'index' );

		$clean = array_values(
			array_filter(
				$submitted,
				function ( $idx ) use ( $valid_indices ) {
					return in_array( $idx, $valid_indices, true );
				}
			)
		);

		$json = wp_json_encode( $clean );
		update_post_meta( $product_id, self::META_SHOW_ITEM_QTY_OVERRIDES, $json );
		epb_aim_log( "save: _aim_show_item_qty_overrides = {$json} for product {$product_id}" );
	}

	private function save_qty_links( int $product_id ): void {
		$raw       = isset( $_POST['aim_item_qty_links'] ) ? wp_unslash( $_POST['aim_item_qty_links'] ) : '[]';
		$submitted = json_decode( $raw, true );

		epb_aim_log( "save: raw aim_item_qty_links = {$raw}" );

		if ( ! is_array( $submitted ) ) {
			update_post_meta( $product_id, self::META_QTY_LINKS, '[]' );
			return;
		}

		$current_items = $this->get_bundle_items_for_js( $product_id );
		$valid_indices = array_column( $current_items, 'index' );
		$cleaned       = [];
		$had_orphans   = false;

		foreach ( $submitted as $link ) {
			if ( ! isset( $link['target'], $link['sources'] ) ) {
				continue;
			}

			$target  = (int) $link['target'];
			$sources = array_map( 'intval', (array) $link['sources'] );

			if ( ! in_array( $target, $valid_indices, true ) ) {
				$had_orphans = true;
				epb_aim_log( "save: orphaned qty-link target index {$target} removed" );
				continue;
			}

			$valid_sources = array_values(
				array_filter(
					$sources,
					function ( $src ) use ( $target, $valid_indices ) {
						return $src !== $target && in_array( $src, $valid_indices, true );
					}
				)
			);

			if ( count( $valid_sources ) !== count( $sources ) ) {
				$had_orphans = true;
				epb_aim_log( 'save: some qty-link source indices were orphaned or self-referential and removed' );
			}

			if ( ! empty( $valid_sources ) ) {
				$cleaned[] = [
					'target'  => $target,
					'sources' => $valid_sources,
				];
			}
		}

		$json = wp_json_encode( $cleaned );
		update_post_meta( $product_id, self::META_QTY_LINKS, $json );
		epb_aim_log( "save: _aim_item_qty_links = {$json} for product {$product_id}" );

		if ( $had_orphans ) {
			set_transient( 'epb_aim_orphan_notice_' . $product_id, true, 60 );
		}
	}

	private function save_max_from_items( int $product_id ): void {
		$raw       = isset( $_POST['aim_max_from_items'] ) ? wp_unslash( $_POST['aim_max_from_items'] ) : '[]';
		$submitted = json_decode( $raw, true );

		epb_aim_log( "save: raw aim_max_from_items = {$raw}" );

		if ( ! is_array( $submitted ) ) {
			update_post_meta( $product_id, self::META_MAX_FROM_ITEMS, '[]' );
			return;
		}

		$current_items = $this->get_bundle_items_for_js( $product_id );
		$valid_indices = array_column( $current_items, 'index' );
		$cleaned       = [];
		$had_orphans   = false;

		foreach ( $submitted as $link ) {
			if ( ! isset( $link['target'], $link['sources'] ) ) {
				continue;
			}

			$target  = (int) $link['target'];
			$sources = array_map( 'intval', (array) $link['sources'] );

			if ( ! in_array( $target, $valid_indices, true ) ) {
				$had_orphans = true;
				epb_aim_log( "save: orphaned max-link target index {$target} removed" );
				continue;
			}

			$valid_sources = array_values(
				array_filter(
					$sources,
					function ( $src ) use ( $target, $valid_indices ) {
						return $src !== $target && in_array( $src, $valid_indices, true );
					}
				)
			);

			if ( count( $valid_sources ) !== count( $sources ) ) {
				$had_orphans = true;
				epb_aim_log( 'save: some max-link source indices were orphaned or self-referential and removed' );
			}

			if ( ! empty( $valid_sources ) ) {
				$cleaned[] = [
					'target'  => $target,
					'sources' => $valid_sources,
				];
			}
		}

		$json = wp_json_encode( $cleaned );
		update_post_meta( $product_id, self::META_MAX_FROM_ITEMS, $json );
		epb_aim_log( "save: _aim_max_from_items = {$json} for product {$product_id}" );

		if ( $had_orphans ) {
			set_transient( 'epb_aim_orphan_notice_' . $product_id, true, 60 );
		}
	}

	private function save_max_qty_message( int $product_id ): void {
		// Save enabled flag.
		$enabled = ( isset( $_POST['aim_max_qty_message_enabled'] ) && 'yes' === $_POST['aim_max_qty_message_enabled'] )
			? 'yes'
			: 'no';
		update_post_meta( $product_id, self::META_MAX_QTY_MESSAGE_ENABLED, $enabled );

		// Always save the text (even when disabled) so it persists when re-enabled.
		// Fall back to the default string if somehow empty.
		$text = isset( $_POST['aim_max_qty_message'] )
			? sanitize_text_field( wp_unslash( $_POST['aim_max_qty_message'] ) )
			: '';
		if ( '' === $text ) {
			$text = __( 'Maximum quantity for this bundle is', 'epb-aim' );
		}
		update_post_meta( $product_id, self::META_MAX_QTY_MESSAGE, $text );

		epb_aim_log( "save: _aim_max_qty_message_enabled = {$enabled}, text = '{$text}' for product {$product_id}" );
	}

	private function save_bundle_qty_message( int $product_id ): void {
		$enabled = ( isset( $_POST['aim_bundle_qty_message_enabled'] ) && 'yes' === $_POST['aim_bundle_qty_message_enabled'] )
			? 'yes'
			: 'no';
		update_post_meta( $product_id, self::META_BUNDLE_QTY_MSG_ENABLED, $enabled );

		// Always save text so it persists when re-enabled.
		$text = isset( $_POST['aim_bundle_qty_message_text'] )
			? sanitize_text_field( wp_unslash( $_POST['aim_bundle_qty_message_text'] ) )
			: '';
		update_post_meta( $product_id, self::META_BUNDLE_QTY_MSG_TEXT, $text );

		epb_aim_log( "save: _aim_bundle_qty_message_enabled = {$enabled}, text = '{$text}' for product {$product_id}" );
	}

	private function save_no_selection_message( int $product_id ): void {
		$enabled = ( isset( $_POST['aim_no_selection_message_enabled'] ) && 'yes' === $_POST['aim_no_selection_message_enabled'] )
			? 'yes'
			: 'no';
		update_post_meta( $product_id, self::META_NO_SELECTION_MSG_ENABLED, $enabled );

		// Always save text so it persists when re-enabled.
		$text = isset( $_POST['aim_no_selection_message_text'] )
			? sanitize_text_field( wp_unslash( $_POST['aim_no_selection_message_text'] ) )
			: '';
		update_post_meta( $product_id, self::META_NO_SELECTION_MSG_TEXT, $text );

		epb_aim_log( "save: _aim_no_selection_message_enabled = {$enabled}, text = '{$text}' for product {$product_id}" );
	}

	private function save_image_swap_rules( int $product_id ): void {
		$raw       = isset( $_POST['aim_image_swap_rules'] ) ? wp_unslash( $_POST['aim_image_swap_rules'] ) : '[]';
		$submitted = json_decode( $raw, true );

		if ( ! is_array( $submitted ) ) {
			update_post_meta( $product_id, self::META_IMAGE_SWAP_RULES, '[]' );
			return;
		}

		$current_items  = $this->get_bundle_items_for_js( $product_id );
		$valid_non_opt  = array_column(
			array_filter( $current_items, function ( $it ) { return empty( $it['is_optional'] ); } ),
			'index'
		);
		$valid_optional = array_column(
			array_filter( $current_items, function ( $it ) { return ! empty( $it['is_optional'] ); } ),
			'index'
		);

		// Accept both 'gallery' (new) and legacy 'main' — normalised to 'gallery' on save.
		$valid_scopes = [ 'item', 'gallery', 'main', 'both' ];

		$cleaned = [];
		foreach ( $submitted as $rule ) {
			if ( ! isset( $rule['target'] ) ) {
				continue;
			}

			$target = (int) $rule['target'];
			if ( ! in_array( $target, $valid_non_opt, true ) ) {
				epb_aim_log( "save_image_swap_rules: skipping rule — target {$target} is not a valid non-optional item" );
				continue;
			}

			// Support both old format (array of ints) and new format (array of {index, qty} objects).
			$selected_raw   = (array) ( $rule['selected'] ?? [] );
			$valid_selected = [];
			foreach ( $selected_raw as $sel_item ) {
				if ( is_array( $sel_item ) ) {
					// New format: { index, qty }.
					$sel_idx = (int) ( $sel_item['index'] ?? -1 );
					$sel_qty = ( isset( $sel_item['qty'] ) && $sel_item['qty'] !== null && $sel_item['qty'] !== '' )
						? max( 1, (int) $sel_item['qty'] )
						: null;
				} else {
					// Old format: plain integer index — treat as any qty.
					$sel_idx = (int) $sel_item;
					$sel_qty = null;
				}
				if ( ! in_array( $sel_idx, $valid_optional, true ) ) {
					continue;
				}
				$valid_selected[] = [ 'index' => $sel_idx, 'qty' => $sel_qty ];
			}

			$image_id = (int) ( $rule['image_id'] ?? 0 );
			if ( $image_id <= 0 || ! wp_attachment_is_image( $image_id ) ) {
				epb_aim_log( "save_image_swap_rules: skipping rule — invalid image_id {$image_id}" );
				continue;
			}

			$scope_raw = isset( $rule['scope'] ) && in_array( $rule['scope'], $valid_scopes, true )
				? $rule['scope']
				: 'item';
			// Normalise legacy 'main' → 'gallery' on save.
			$scope = ( 'main' === $scope_raw ) ? 'gallery' : $scope_raw;

			$cleaned[] = [
				'target'   => $target,
				'selected' => $valid_selected,
				'image_id' => $image_id,
				'scope'    => $scope,
			];
		}

		$json = wp_json_encode( $cleaned );
		update_post_meta( $product_id, self::META_IMAGE_SWAP_RULES, $json );
		epb_aim_log( "save: _aim_image_swap_rules = {$json} for product {$product_id}" );
	}

	private function save_logging_option(): void {
		$value = ( isset( $_POST['aim_detailed_logging'] ) && 'yes' === $_POST['aim_detailed_logging'] )
			? 'yes'
			: 'no';
		update_option( self::OPT_LOGGING, $value );
		// Log after updating so this message appears if logging was just turned on.
		epb_aim_log( "save: detailed logging = {$value}" );
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	public function enqueue_assets( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		global $post;
		if ( ! $post || 'product' !== $post->post_type ) {
			return;
		}

		// Show orphan notice if a previous save cleaned up links.
		if ( get_transient( 'epb_aim_orphan_notice_' . $post->ID ) ) {
			delete_transient( 'epb_aim_orphan_notice_' . $post->ID );
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-warning is-dismissible"><p>';
					esc_html_e(
						'Product Bundles AIM: Some quantity links referenced items that no longer exist and were automatically removed. Please review your settings.',
						'epb-aim'
					);
					echo '</p></div>';
				}
			);
		}

		wp_enqueue_media();

		wp_enqueue_style(
			'epb-aim-admin',
			EPB_AIM_PLUGIN_URL . 'assets/aim-admin.css',
			[],
			EPB_AIM_VERSION
		);

		wp_enqueue_script(
			'epb-aim-admin',
			EPB_AIM_PLUGIN_URL . 'assets/aim-admin.js',
			[ 'jquery' ],
			EPB_AIM_VERSION,
			true
		);

		$items            = $this->get_bundle_items_for_js( $post->ID );
		$qty_links_raw    = get_post_meta( $post->ID, self::META_QTY_LINKS, true );
		$qty_links        = ! empty( $qty_links_raw ) ? json_decode( $qty_links_raw, true ) : [];
		$max_links_raw    = get_post_meta( $post->ID, self::META_MAX_FROM_ITEMS, true );
		$max_links        = ! empty( $max_links_raw ) ? json_decode( $max_links_raw, true ) : [];

		// Image swap rules — resolve thumbnail URLs for admin preview.
		$swap_raw   = get_post_meta( $post->ID, self::META_IMAGE_SWAP_RULES, true );
		$swap_rules = ! empty( $swap_raw ) ? json_decode( $swap_raw, true ) : [];
		if ( is_array( $swap_rules ) ) {
			foreach ( $swap_rules as &$rule ) {
				$rule['image_url'] = ! empty( $rule['image_id'] )
					? ( wp_get_attachment_image_url( (int) $rule['image_id'], 'thumbnail' ) ?: '' )
					: '';
			}
			unset( $rule );
		}

		wp_localize_script(
			'epb-aim-admin',
			'aimBundleData',
			[
				'items'           => $items,
				'qtyLinks'        => is_array( $qty_links ) ? $qty_links : [],
				'maxFromItems'    => is_array( $max_links ) ? $max_links : [],
				'imageSwapRules'  => is_array( $swap_rules ) ? $swap_rules : [],
				'i18n'            => [
					'selectItem'   => __( '— Select item —', 'epb-aim' ),
					'removeLink'   => __( 'Remove', 'epb-aim' ),
					'circularWarn' => __( 'Circular reference detected: an item cannot be both a source and a target within conflicting links.', 'epb-aim' ),
					'noSources'    => __( 'Please select at least one source item.', 'epb-aim' ),
					'noMaxQty'     => __( 'Note: this item has no Max quantity set in the bundle editor — Max from Items will have no effect.', 'epb-aim' ),
					'chooseImage'  => __( 'Choose Image', 'epb-aim' ),
					'useImage'     => __( 'Use Image', 'epb-aim' ),
					'removeImage'  => __( 'Remove', 'epb-aim' ),
					'scopeItem'    => __( 'Item image', 'epb-aim' ),
					'scopeGallery' => __( 'Gallery image', 'epb-aim' ),
					'scopeBoth'    => __( 'Both', 'epb-aim' ),
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Return a flat array of item descriptors for use in the admin JS dropdowns.
	 * Each entry: [ 'index' => int, 'label' => string, 'max_qty' => int ]
	 * max_qty is 0 when no Max quantity is configured for the item.
	 */
	// -------------------------------------------------------------------------
	// Auto-enforce "Quantity from Items" prerequisites on save
	// -------------------------------------------------------------------------

	/**
	 * For every configured qty link, ensure the TARGET bundle item has:
	 *   • edit_quantity = 'true'  (so React renders an editable qty input)
	 *   • max_quantity  >= sum of each source item's max_quantity
	 *     (falling back to the source's default quantity when no max is set)
	 *
	 * Only ever raises max_quantity — never lowers a value the admin set higher.
	 * If the target already has max_quantity = '' (unlimited) the max is left
	 * alone (unlimited already covers any possible sum), but edit_quantity is
	 * still enforced.
	 *
	 * Runs silently; logs to debug.log when detailed logging is enabled.
	 */
	private function enforce_qty_link_item_settings( int $product_id ): void {
		// Read the qty links that were just saved.
		$qty_links_json = get_post_meta( $product_id, self::META_QTY_LINKS, true );
		$qty_links      = ! empty( $qty_links_json ) ? json_decode( $qty_links_json, true ) : [];

		if ( ! is_array( $qty_links ) || empty( $qty_links ) ) {
			return; // No qty links configured — nothing to enforce.
		}

		// Load the full bundle items array (all fields, not just the JS subset).
		$product = wc_get_product( $product_id );
		if ( $product && method_exists( $product, 'get_items' ) ) {
			$items = $product->get_items();
		} else {
			$raw   = get_post_meta( $product_id, '_items', true );
			$items = is_array( $raw )
				? $raw
				: ( ! empty( $raw ) ? json_decode( $raw, true ) : [] );
		}

		if ( empty( $items ) || ! is_array( $items ) ) {
			epb_aim_log( "enforce_qty_link_item_settings: no bundle items found for product {$product_id}" );
			return;
		}

		// Ensure the array is 0-based (matching the stored indices).
		$items   = array_values( $items );
		$changed = false;

		foreach ( $qty_links as $link ) {
			if ( ! isset( $link['target'], $link['sources'] ) ) {
				continue;
			}

			$target_idx  = (int) $link['target'];
			$source_idxs = array_map( 'intval', (array) $link['sources'] );

			if ( ! isset( $items[ $target_idx ] ) ) {
				epb_aim_log( "enforce_qty_link_item_settings: target index {$target_idx} not found — skipping" );
				continue;
			}

			// ── Sum of source max quantities ─────────────────────────────────
			// Use each source's max_quantity; fall back to its default quantity
			// when max is not set (empty string), since that is the minimum
			// user-visible value and gives a conservative bound.
			$sum = 0;
			foreach ( $source_idxs as $src_idx ) {
				if ( ! isset( $items[ $src_idx ] ) ) {
					epb_aim_log( "enforce_qty_link_item_settings: source index {$src_idx} not found — skipping source" );
					continue;
				}
				$src  = $items[ $src_idx ];
				$sum += ( isset( $src['max_quantity'] ) && '' !== $src['max_quantity'] )
					? (int) $src['max_quantity']
					: (int) ( $src['quantity'] ?? 1 );
			}

			if ( $sum <= 0 ) {
				continue;
			}

			// ── Edit quantity ─────────────────────────────────────────────────
			$current_edit = $items[ $target_idx ]['edit_quantity'] ?? 'false';
			if ( 'true' !== $current_edit ) {
				$items[ $target_idx ]['edit_quantity'] = 'true';
				$changed = true;
				epb_aim_log( "enforce_qty_link_item_settings: product {$product_id} item[{$target_idx}]: set edit_quantity=true" );
			}

			// ── Max quantity ──────────────────────────────────────────────────
			// '' means no max (unlimited) — already sufficient, leave it alone.
			$raw_max = $items[ $target_idx ]['max_quantity'] ?? '';
			if ( '' !== $raw_max ) {
				$current_max = (int) $raw_max;
				if ( $current_max < $sum ) {
					$items[ $target_idx ]['max_quantity'] = $sum;
					$changed = true;
					epb_aim_log( "enforce_qty_link_item_settings: product {$product_id} item[{$target_idx}]: raised max_quantity {$current_max} → {$sum}" );
				}
			} else {
				epb_aim_log( "enforce_qty_link_item_settings: product {$product_id} item[{$target_idx}]: max_quantity is unlimited — skipping max update" );
			}
		}

		if ( $changed ) {
			update_post_meta( $product_id, '_items', $items );
			epb_aim_log( "enforce_qty_link_item_settings: saved updated _items for product {$product_id}" );
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	public function get_bundle_items_for_js( int $product_id ): array {
		if ( ! $product_id ) {
			return [];
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return [];
		}

		// ProductBundle::get_items() returns a decoded PHP array.
		$items = method_exists( $product, 'get_items' ) ? $product->get_items() : [];

		// Fallback: read raw meta.
		if ( empty( $items ) ) {
			$raw   = get_post_meta( $product_id, '_items', true );
			$items = is_array( $raw ) ? $raw : ( ! empty( $raw ) ? json_decode( $raw, true ) : [] );
		}

		if ( empty( $items ) || ! is_array( $items ) ) {
			return [];
		}

		$result = [];
		foreach ( array_values( $items ) as $index => $item ) {
			$label    = ! empty( $item['title'] ) ? $item['title'] : sprintf(
				/* translators: %d: item number */
				__( 'Item %d', 'epb-aim' ),
				$index + 1
			);
			$result[] = [
				'index'       => $index,
				'label'       => $label,
				'max_qty'     => ! empty( $item['max_quantity'] ) ? (int) $item['max_quantity'] : 0,
				'is_optional' => isset( $item['optional'] ) && 'true' === (string) $item['optional'],
			];
		}

		return $result;
	}
}
