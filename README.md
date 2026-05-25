# Easy Product Bundles for WooCommerce — AIM

An extension plugin for **Easy Product Bundles for WooCommerce** (base) and its **Pro** add-on. Adds eleven features for fine-grained control over bundle item quantities, display, and images.
All controls are per Bundle and accessed from the bundled product (Product Bundles AIM tab) 
---

## Requirements

| Dependency | Minimum version |
|---|---|
| WordPress | 6.0 |
| WooCommerce | 7.0 |
| Easy Product Bundles for WooCommerce | Version 6.17.0 |
| Easy Product Bundles for WooCommerce Pro | Version 6.17.0 |
| PHP | 7.4 |

---

## Features

### Feature 1 — Hide Item Quantity

Hides the quantity input controls for all bundle items using an injected CSS rule (`.asnp-product-quantity-field { display: none !important }`).

When any JS feature (Feature 2, 3, or 4) is also active on the same product, the CSS is still injected but the JS layer keeps the interactive inputs accessible in the DOM so React state can be updated.

**Admin setting:** *Product Bundles AIM* tab → *Hide item quantity* checkbox.

---

### Feature 2 — Quantity from Items

Dynamically sets a **target** item's quantity to the sum of one or more **source** items' quantities, in real time as the user interacts with the bundle on the product page.

**How it works (frontend JS):**

- A `MutationObserver` watches the full page for `asnp-disable-product` class changes (item selection toggles) and bundle input DOM changes.
- `input` events on any bundle quantity field and the bundle plugin's `asnpWepbPriceChanged` custom event also trigger recalculation.
- A 500 ms polling fallback runs for the first 10 seconds to handle async React renders.
- A 50 ms enforcement interval keeps the displayed value in sync after React re-renders reset it.
- A synchronous pre-submit hook recalculates one final time before the form POST reaches the server.

**React state update strategy:**

1. **Strategy 1** — locate the `__reactProps$xxx` key on the input DOM node (using `Object.getOwnPropertyNames`) and call the `onChange` prop directly, bypassing the event system.
2. **Strategy 2** (fallback) — reset the `_valueTracker`, set the DOM value via the native prototype setter, then dispatch synthetic `input` and `change` events; temporarily reveals a CSS-hidden wrapper so React's delegated listener can process them.

**Admin prerequisite auto-enforcement (on save):**

When the product is saved, AIM automatically ensures the target item is configured correctly for the feature to work:

- **Edit quantity by user** is set to *checked* (required so React renders an interactive qty input).
- **Max quantity** is raised to at least the sum of all source items' `max_quantity` values (falling back to each source's default quantity if no max is set). The max is only ever *increased* — a value the admin has deliberately set higher is left alone. A target with no max (unlimited) is also left alone.

**Admin setting:** *Product Bundles AIM* tab → *Quantity from Items* section.

---

### Feature 3 — Per-item Show Quantity Override

When Feature 1 (hide) is active, this forces specific items' quantity controls to remain visible by applying an inline `display: flex !important` style that overrides the stylesheet rule. A 50 ms enforcement interval re-applies the override if React re-renders remove it.

**Admin setting:** *Product Bundles AIM* tab → *Show quantity for items* checkboxes.

---

### Feature 4 — Max from Items

Sets a **target** item's `max` attribute to `base_max − sum_of_source_quantities`, clamped to a minimum of 1. If the user's current quantity exceeds the new maximum it is automatically clamped down. A 50 ms enforcement interval re-applies the max attribute after React re-renders reset it.

An optional message (e.g. *"Maximum quantity for this bundle is 4"*) can be injected next to the target item and updated in real time.

**Admin setting:** *Product Bundles AIM* tab → *Max from Items* section + *Max Qty Message* fields.

---

### Feature 5 — Bundle Qty Message

Injects a text label immediately before the main WooCommerce quantity input on the product page (e.g. *"No of mounts"*), making it clear to the customer what they are quantifying.

**How it works (frontend JS):**

- On page load (with staggered retries to handle async React renders) `injectBundleQtyLabel()` locates `form.cart`, finds the `.quantity` div and the submit button, then builds a `.epb-aim-qty-wrapper` containing the label span and the qty div.
- Both the wrapper and the submit button are moved into a new `.epb-aim-form-row` div appended to the form. This container uses an explicit `display: flex; flex-direction: row` so the visual order (label → qty → button) is immune to whatever `flex-direction`, `order`, or float rules the active theme applies to `form.cart`.
- The injection is idempotent — re-running when the wrapper already exists is a no-op.

**Admin setting:** *Product Bundles AIM* tab → *Bundle Qty Message* enable toggle + message text field.

---

### Feature 6 — No Selection Message

Replaces the default *"Please select a product for all items."* text inside `.asnp-alert` elements with a custom admin-configured message. Any alert text that does not match the default string is left unchanged.

A 50 ms enforcement interval re-applies the replacement after React re-renders reset the alert text.

**Admin setting:** *Product Bundles AIM* tab → *No Selection Message* enable toggle + message text field.

---

### Feature 7 — Qty × Unit Price Total

When enabled, replaces the standard single-row price display with a three-row breakdown inside `.asnp-totalPrice-wrapper`, updated live as the user changes the bundle quantity or selects products:

```
Each Item:    £49.00      ← original row; title text replaced by "Each Bundle Message"
Quantity:          3      ← hardcoded label ("Quantity:") + bundle qty input value
Total:        £147.00     ← "Bundle Total Message" label + computed total (unit × qty)
```

All three rows share the same visual weight and style as the native price row. The Quantity and Total rows are hidden until a valid price is known (i.e. the bundle plugin fires `asnpWepbPriceChanged` with a non-zero price). The computed total is **display-only** — it does not affect cart data.

The unit price is sourced from `asnpWepbPriceChanged`'s `event.detail.price` (the discounted price for one bundle), so role-based discounts are reflected correctly. Currency formatting is built directly from `easyProductBundlesData` fields (`currency`, `number_of_decimals`, `decimal_separator`, `thousand_separator`, `price_format`), producing the same `<span class="woocommerce-Price-amount amount"><bdi>…</bdi></span>` structure that React's Price component renders — ensuring all bundle plugin CSS rules apply consistently to the Total row.

The 50 ms enforcement interval re-applies all three changes (title text, qty row, total row) after any React re-render.

**Admin settings:** *Product Bundles AIM* tab → *Item Display*:

| Setting | Default | Purpose |
|---|---|---|
| *Show qty × unit price total* checkbox | off | Enables the feature |
| *Each Bundle Message* | `Each Item:` | Replaces the price title ("Total:", "Buy all for:", etc.) |
| *Bundle Total Message* | `Total:` | Label on the left of the computed total row |

The two text fields are disabled (greyed out) when the checkbox is unchecked. The "Quantity:" row label is hardcoded but translatable via the WordPress translation system.

---

### Feature 8 — Image Swap Rules

Swaps a **target** item's displayed image based on which **optional** items are currently selected in the bundle. Any number of rules can be configured; each rule maps an exact set of selected optional items to a specific replacement image.

**Example with two optional items (no qty constraint):**

| Optional 1 selected | Optional 2 selected | Image shown |
|---|---|---|
| Yes | No | Image 1 |
| No | Yes | Image 2 |
| Yes | Yes | Image 3 |
| No | No | Target item's original image (restored) |

**Example with qty constraints:**

| Optional 1 selected | Optional 1 qty | Optional 2 selected | Image shown |
|---|---|---|---|
| Yes | 1 | No | Image A |
| Yes | 2 | No | Image B |
| Yes | any | Yes | Image C |
| No | — | No | Original image (restored) |

Rules are evaluated as an **exact set match** on which optional items are selected, followed by an **exact qty match** for any items whose rule specifies a qty. A rule's qty field left blank matches any qty of that item (only selection state matters). When no rule matches the original image is restored automatically.

**How it works (frontend JS):**

- On every `recalculate()` call and every 50 ms enforcement interval tick, `enforceImageSwap()` groups rules by target item index.
- For each target, it determines the set of "monitored" optional item indices (the union of all `selected` arrays for that target's rules) and reads their current selection state using `document.getElementById('asnp-bundle-item-' + idx)` — which is present in both grid and list view layouts.
- Selection state is determined by checking whether the item's `.asnp-bundleListItem-imageBox` or `.asnp-BundleGridItem-imageBox` element carries the `asnp-disable-product` class, with a fallback to the qty-input / `isItemSelected` approach.
- When a rule matches, the `scope` field controls what is swapped: `item` updates the bundle item image only, `gallery` updates the corresponding WooCommerce gallery slide only, `both` updates both. Each destination is updated or restored independently — switching from a `gallery` rule to an `item` rule restores the gallery slide and swaps the item image.
- The original `src` (and for gallery: `srcset`, `data-src`, `data-large_image`, parent `<a href>`) is saved before the first swap and restored when no rule matches.
- **Per-required-item gallery slides:** each non-optional bundle item after the first one (i.e. required items #2, #3, …) that has a `gallery` or `both` scope rule gets its own dedicated WooCommerce gallery slide, injected immediately after slide 1 via the `woocommerce_product_thumbnails` PHP hook at priority 15. Injected slides show a configurable default image on page load (from the bundle item's *Display → Default image* field; falls back to the item's product featured image). When a rule fires for that item its slide's image is swapped; when no rule matches the default is restored.
- **Thumbnail strip fix:** when any gallery slide image changes, the matching `<li><img>` in the FlexSlider `.flex-control-thumbs` strip is updated to match. Thumbnail URL comes from `image_thumb_url` (WooCommerce gallery-thumbnail size) with a full-URL fallback.
- **Zoom fix:** the WooCommerce zoom plugin caches its image URL at initialisation time and does not react to `data-large_image` attribute changes. When a gallery slide image changes, `img.zoomImg` inside the same slide div is updated directly.
- **PhotoSwipe:** the parent `<a href>` is always updated alongside `src`, so opening the lightbox for a swapped slide shows the correct full-size image.
- Staggered initial runs at 300 ms, 800 ms, and 1 500 ms after page load handle async React renders.

**Data storage and delivery:**

- Rules are stored per-product in `_aim_image_swap_rules` post meta as a JSON array.
- Image IDs (not URLs) are stored; `wp_get_attachment_image_url()` resolves both a full URL (`image_url`) and a gallery-thumbnail URL (`image_thumb_url`) at render time so they survive media replacements.
- The resolved rules array and `aim_non_optional_order` (ordered array of non-optional item indices) are delivered to JS via `wp_localize_script` in `easyProductBundlesData`.

**Admin setting:** *Product Bundles AIM* tab → *Image Swap Rules* section. Each row has:

- **Change image of** — dropdown restricted to non-optional bundle items.
- **When these optional items are selected** — checkbox list (one row per optional item). Each optional item has a checkbox (include it in the trigger set) and an optional **qty** number input. Leaving qty blank means "any qty"; entering a number means the item must be selected with exactly that qty.
- **Swap** — dropdown: *Item image* / *Gallery image* / *Both*. Default: *Item image*.
- **Image** — thumbnail preview + *Choose Image* button (WP Media Library) + *Remove* link.

---

### Feature 9 — Bundle Qty +/- Buttons

Replaces the browser's native up/down spin arrows on the main bundle quantity field with **−** and **+** buttons, matching the style of the individual bundle item quantity controls (`.asnp-product-quantity-button` with Dashicons).

```
[ No of mounts ]  [−]  1  [+]  [Add to cart]
```

Clicking − decrements the quantity by the input's `step` value, floored at its `min` (default 1). Clicking + increments by `step`, capped at `max` if one is set. Each click fires native `input` and `change` events so WooCommerce's cart logic receives the updated value.

**How it works (frontend JS):**

- `injectQtyPlusMinus()` targets `form.cart input[name="quantity"]`, adds `epb-aim-qty-pm-active` to the parent `.quantity` wrapper (idempotency guard), then prepends a minus button and appends a plus button.
- Runs immediately on load, with staggered retries at 300 ms and 800 ms, and on every 50 ms enforcement interval tick (the idempotency check makes subsequent calls free).
- Button clicks use the native `HTMLInputElement.prototype.value` setter to update React-controlled inputs correctly before dispatching `input` and `change` events.

**CSS:**

- The `.quantity.epb-aim-qty-pm-active` wrapper is set to `display: flex` so the three elements (−, input, +) sit in a row.
- Native browser spin arrows are hidden via `-webkit-appearance: textfield` and `::-webkit-inner/outer-spin-button { display: none }`.

**Admin setting:** *Product Bundles AIM* tab → *Item Display* → *Use +/- buttons for bundle quantity* checkbox. Default: unchecked.

#### Feature 9 sub-setting — +/- Button Size

When Feature 9 is enabled, an indented **+/- Button Size** number input (50–100) appears beneath the checkbox. Set to 100 (default) for no change, or lower to scale the buttons down proportionally.

**How it works:** After buttons are injected, a `<style id="epb-aim-pm-size-style">` tag is appended to `<head>` containing:

```css
.epb-aim-qty-pm-btn { zoom: 0.80; }   /* example for 80% */
```

`zoom` scales the entire button — box, padding, border, and Dashicons icon — proportionally and affects layout (unlike `transform: scale()` which only scales visually). The style tag is inserted once and is class-based, so it survives React re-renders without needing to be re-applied per button.

**Admin setting:** Number input (50–100) in the *+/- Button Size* sub-field beneath the Feature 9 checkbox. Only active when Feature 9 is checked. Default: 100.

---

### Feature 10 — Swap Price Block and Qty Row Positions

By default the base plugin renders `.asnp-totalPrice-wrapper` (the per-mount price, discount badge, and totals) above `form.cart` (which contains the qty label, +/− buttons, and add-to-cart button). Enabling this feature reverses that visual order:

**Default (unchecked):**
```
[ asnp-totalPrice-wrapper ]   ← price/totals
[ epb-aim-form-row ]          ← qty + add-to-cart
```

**Swapped (checked):**
```
[ epb-aim-form-row ]          ← qty + add-to-cart
[ asnp-totalPrice-wrapper ]   ← price/totals
```

**How it works (frontend JS):**

- `enforcePositionSwap()` finds `.asnp-totalPrice-wrapper` and `form.cart`, then calls `form.parentNode.insertBefore( priceEl, form.nextSibling )` to physically move the price block after the form in the DOM.
- Sets `data-aim-pos-swapped="1"` on the moved element as an idempotency guard — the 50 ms enforcement interval checks this attribute and skips if already set.
- If React re-renders and inserts a fresh `.asnp-totalPrice-wrapper` (without the attribute), the next interval tick re-applies the move automatically.
- Runs immediately on load, with staggered retries at 300 ms and 800 ms.

**Admin setting:** *Product Bundles AIM* tab → *Item Display* → *Swap price block and qty row positions* checkbox. Default: unchecked.

---

### Feature 11 — Auto-fit Qty Row to Single Line

When enabled, automatically reduces the *Add to Cart* button width so the quantity label, input controls, and button all sit on one line inside `.epb-aim-form-row`.

**Constraints:**
- The button is never narrowed below its minimum text width (i.e. "Add to cart" always renders on a single line — no text wrapping is induced).
- If the qty wrapper alone is already too wide for even the minimum button width to fit, the adjustment is skipped entirely (no overflow is introduced).

**How it works (frontend JS):**

`adjustFormRowWidth()`:
1. Resets any previously applied inline `width` on the button.
2. Measures the minimum button width using an off-screen clone (`position: absolute; visibility: hidden; white-space: nowrap; width: auto`) — no visual flash.
3. Measures `rowWidth` and `qtyWrapperWidth` via `getBoundingClientRect()`.
4. Calculates `available = rowWidth − qtyWrapperWidth − 8px (gap)`.
5. If `available ≥ minButtonWidth`: sets `button.style.width = available + 'px'`.
6. Else: skips (requirement 2.1.8 — does not apply an insufficient reduction).

Runs immediately on load, at 300 ms and 800 ms after load, and is debounced at 100 ms on every `window resize` event.

**Admin setting:** *Product Bundles AIM* tab → *Item Display* → *Auto-fit qty row to single line* checkbox. Default: unchecked. Independent of all other features.

---

## Admin Panel

The **Product Bundles AIM** tab appears in the WooCommerce product editor for every `easy_product_bundle` product type. It contains:

| Section | Controls |
|---|---|
| Item Display | *Hide item quantity* checkbox; *Use +/- buttons for bundle quantity* checkbox; *+/- Button Size* number input (sub-field); *Swap price block and qty row positions* checkbox; *Auto-fit qty row to single line* checkbox; *Show qty × unit price total* checkbox; *Each Bundle Message* text field; *Bundle Total Message* text field |
| Per-Item Quantity Display | Per-item checkboxes (override bundle-level hide) |
| Quantity from Items | Add/remove links: choose one target item and one or more source items |
| Max from Items | Add/remove links: choose target, sources, and a base maximum value |
| Max Qty Message | Enable toggle + message text field |
| Bundle Qty Message | Enable toggle + message text field |
| No Selection Message | Enable toggle + message text field |
| Image Swap Rules | Add/remove rows: choose target item, optional items multi-select, scope (Item image / Main product image / Both), and image via WP Media Library |
| Detailed Logging | Checkbox to enable PHP debug.log and JS console output |

---

## Detailed Logging

When *Detailed Logging* is enabled (global plugin setting, stored in `epb_aim_detailed_logging` WP option):

- **PHP** — entries are appended to `wp-content/debug.log` with an `[EPB-AIM]` prefix and a UTC timestamp.
- **JS** — `[EPB-AIM]` prefixed messages appear in the browser console covering initialisation, every recalculation cycle (source selection state, qty contribution, target update), enforcement interval actions, and React update strategy used.

---

## Technical Notes

### Why `document.body` as the MutationObserver scope

The bundle widget is rendered by React *after* this script executes, and is typically inserted *before* `form.cart` in the DOM (not inside it). Using a CSS-class querySelector at script load time therefore always falls back to `form.cart`, which does not contain the bundle items. `document.body` is used unconditionally so that both the MutationObserver and the click listener cover the entire page.

### Why non-disabled inputs are preferred in `getQtyInput`

The base plugin renders two quantity inputs per item:
- `asnp_wepb_bundle[ N ][productList_quantity]` — interactive, not disabled, React-controlled.
- `asnp_wepb_bundle[ N ][simple_productList_quantity]` — display-only, `disabled=true`, always inside an `asnp-disable-product` container even for selected items.

Both names match the selector `input[name*="asnp_wepb_bundle"][name*="quantity"]`. Without preference logic, `querySelectorAll` returns the disabled copy first in many DOM orderings, causing `isItemSelected()` to always return `false` and every source to contribute 0. AIM now returns the first non-disabled match and falls back to the first match only if all candidates are disabled.

### Why `total === 0` is skipped

When all sources are deselected their sum is 0. The bundle plugin enforces a minimum qty of 1 per item; trying to set the target to 0 is silently rejected by React's `onChange` validation, which immediately re-renders with qty = 1 and triggers another recalculation — an infinite loop. AIM skips the update (and clears the enforcement map entry) when the total is 0, leaving the target at its current value until at least one source is active.

### wp_localize_script serialises PHP `true` as `1`

`wp_localize_script` passes PHP booleans through `html_entity_decode((string)$value)` for scalar values, converting PHP `true` to the string `"1"`. The JS logging flag therefore uses a truthy coercion (`!! data.aim_logging`) rather than a strict `=== true` check.

---

## File Structure

```
easy-product-bundles-for-woocommerce-aim/
├── easy-product-bundles-for-woocommerce-aim.php   # Plugin bootstrap & constants
├── src/
│   ├── Plugin.php          # Singleton, wires Admin + Frontend
│   ├── Admin.php           # Product tab, panel, save hooks, auto-enforcement
│   └── Frontend.php        # Filter hooks, script/style enqueue, CSS injection
└── assets/
    ├── aim-admin.css        # Admin panel styles
    ├── aim-admin.js         # Admin panel UI (item pickers, link management)
    ├── aim-frontend.css     # Frontend styles (qty wrapper, max message, qty-total line)
    └── aim-frontend.js      # Runtime qty linking, max enforcement, show-overrides, qty-total, image swap, qty +/-, position swap, auto-width row
```

---

## Changelog

### 1.1.8

- **Performance — suppress checkout-block assets on non-checkout pages:** the base plugin unconditionally enqueues `wepb-checkout-integration` CSS and JS on every page, including the site homepage. This caused a render-blocking stylesheet request (~260 ms) and a chained script dependency delay (~3,929 ms) on pages that contain no WooCommerce checkout block. `Frontend.php` now hooks into `wp_enqueue_scripts` at priority 999 and dequeues + deregisters both assets on every page except `is_checkout()` and `is_cart()`. The `wp_deregister_*` calls prevent re-enqueue if another asset lists them as a dependency.

### 1.1.7

- **Feature 8 — per-required-item gallery slides:** each non-optional bundle item after the first that has a *Gallery image* or *Both* scope rule now gets its own WooCommerce gallery slide, injected on page load (via `woocommerce_product_thumbnails` hook at priority 15) immediately after slide 1. Each injected slide displays the bundle item's configured *Display → Default image* (falls back to the item's product featured image). When a matching rule fires the slide image swaps; when no rule matches it restores automatically.
- **Feature 8 — FlexSlider thumbnail strip fix:** swapping a gallery slide image now also updates the corresponding `<li><img>` in `.flex-control-thumbs` (the thumbnail row beneath the main image). Uses the WooCommerce gallery-thumbnail-size URL when available.
- **Feature 8 — WooCommerce zoom fix:** the zoom plugin caches its source URL at init time; subsequent `data-large_image` changes were silently ignored. Swapping a gallery slide now also sets `img.zoomImg` src directly, so the hover magnifier tracks the swapped image immediately.
- **Feature 8 — scope renamed:** *Main product image* → *Gallery image* (saved value `'main'` → `'gallery'`). All existing rules with `scope:'main'` are normalised to `'gallery'` automatically on save and on frontend delivery — no manual migration needed.
- **Feature 8 — thumbnail URL delivered to JS:** `image_thumb_url` (WooCommerce gallery-thumbnail size) is now included in each rule's JS data alongside `image_url`.
- **Feature 8 — `aim_non_optional_order`:** PHP now passes the ordered list of non-optional item indices to JS so it can map each required item to its correct gallery slide.

### 1.1.6

- Extended Feature 8: added per-item qty matching to Image Swap Rules. Each optional item in a rule now has an optional qty field (blank = any qty, number = exact match required). The trigger condition is: selected set matches exactly AND, for each item with a specified qty, the customer's current qty for that item matches exactly.
- Admin UI: replaced the optional-items multi-select with a checkbox list (one row per optional item) so a qty number input can appear beside each item. Qty inputs are disabled and visually faded when their checkbox is unchecked.
- Data format: `selected` in each rule is now an array of `{index, qty}` objects (`qty` is `null` when not specified). Existing rules with the old integer-array format are automatically normalised on load (treated as any qty) — no manual migration needed.

### 1.1.5

- Added Feature 9 sub-setting: +/- Button Size — number input (50–100%) beneath the Feature 9 checkbox, only shown when Feature 9 is enabled. Injects a `<style>` tag with `zoom: X` on `.epb-aim-qty-pm-btn` to scale the button box, padding, and Dashicons icon proportionally. Default: 100 (no change).
- Added Feature 11: Auto-fit qty row to single line — when enabled, measures the available width in `.epb-aim-form-row` and sets the Add to Cart button width to fill that space. Uses an off-screen clone to determine the minimum button width (text on one line); skips if available width is less than the minimum. Runs on load, at 300 ms / 800 ms, and debounced on `window resize` at 100 ms. Default: unchecked.
- Admin CSS: added `.aim-qty-pm-sub-field` indented style block matching the existing Feature 7 sub-field pattern.
- Admin JS: Feature 9 sub-field show/hide toggled by the Feature 9 checkbox on the admin panel.

#### 1.1.5 — Bug fixes (post-release)

- **Feature 9 button size not scaling:** `wp_localize_script` serialises all PHP values as strings, so `aim_qty_pm_size` arrived in JS as `"50"` rather than `50`. The old guard `typeof === 'number'` was always false, defaulting `qtyPmSize` to `100` and never injecting the `<style>` zoom tag. Fixed with `parseInt()` + clamp (50–100).
- **Feature 11 auto-width target width corrected:** Reverted an incorrect change that set the button to its minimum text width. The correct target is `floor(rowWidth − qtyWidth − gap)` — the button fills all remaining row space, with `minBtnWidth` used only as a floor guard (skip if available < min). Added `row.style.flexWrap = 'nowrap'` when the reduction is applied to prevent sub-pixel rounding in `flex-wrap: wrap` from re-wrapping the button to a second line. Both inline styles are reset at the top of each measurement cycle.

### 1.1.4

- Added Feature 10: Swap price block and qty row positions — when enabled, moves `.asnp-totalPrice-wrapper` to appear after `form.cart` (i.e. after the qty label + add-to-cart row) rather than before it. Uses JS DOM insertion on the 50 ms enforcement interval; idempotent via `data-aim-pos-swapped` attribute so React re-renders are handled automatically.
- Admin setting: *Item Display* → *Swap price block and qty row positions* checkbox (default unchecked).

### 1.1.3

- Added Feature 9: Bundle Qty +/- Buttons — replaces the browser spin arrows on the main bundle quantity field with − and + buttons matching the existing bundle item qty control style. Floored at `min` (default 1), capped at `max` when set.
- Admin setting: *Item Display* → *Use +/- buttons for bundle quantity* checkbox (default unchecked).
- Works independently of all other features; enqueues the frontend script on its own when enabled.

### 1.1.2

- Extended Feature 8: added per-rule *Swap* scope — *Item image*, *Main product image*, or *Both*. Each destination (bundle item image and/or WooCommerce main product gallery image) is updated or restored independently based on the matched rule's scope.
- Main product image swap updates `src`, `srcset`, `data-src`, `data-large_image`, and the parent `<a href>` for WooCommerce zoom plugin compatibility.
- Existing rules without a saved scope default to *Item image* (no behaviour change).

### 1.1.1

- Added Feature 8: Image Swap Rules — swap a non-optional bundle item's image based on which optional items are currently selected. Supports any number of rules and any combination of optional items.
- Rules stored in `_aim_image_swap_rules` post meta (JSON); image URLs resolved at render time via `wp_get_attachment_image_url()`.
- Admin UI: *Image Swap Rules* section with per-row target dropdown, optional items multi-select, and WP Media Library image picker.
- Frontend: `enforceImageSwap()` uses `#asnp-bundle-item-{N}` element IDs for reliable selector in both grid and list view layouts.

### 1.0.3

- Redesigned Feature 7: replaced single "N × unit = total" span with a three-row layout (Each Item / Quantity / Total) inside `.asnp-totalPrice-wrapper`.
- Added *Each Bundle Message* admin setting to replace the price title ("Total:", "Buy all for:", etc.) per bundle.
- Added *Bundle Total Message* admin setting for the label of the computed total row.
- Both text fields are pre-filled with defaults and disabled when the feature checkbox is unchecked.
- "Quantity:" row label is hardcoded and translatable.
- Fixed: Total row currency symbol now always displays correctly. Price is formatted directly from `easyProductBundlesData` fields rather than delegating to `window.asnpWepb.utils.formatPrice`, whose internal `sprintf` call was silently dropping the symbol.

### 1.0.2

- Added Feature 7: Qty × Unit Price Total — live `N × unit = total` line inside the price wrapper, driven by `asnpWepbPriceChanged` and the bundle quantity input.
- Flattened asset layout: `assets/css/` and `assets/js/` subdirectories merged into a single `assets/` directory.

### 1.1.0

- Added Feature 5: Bundle Qty Message — label injected before the main qty input using a theme-safe `.epb-aim-form-row` flex container.
- Added Feature 6: No Selection Message — replaces the base plugin's default alert text with a custom message.

### 1.0.0

- Initial release with Features 1–4.
- Auto-enforcement of `edit_quantity` and `max_quantity` on save for Quantity from Items targets.
- Diagnostic detailed logging (PHP + JS).
