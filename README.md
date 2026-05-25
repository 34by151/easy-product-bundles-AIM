[![Buy Me A Coffee](https://cdn.buymeacoffee.com/buttons/default-orange.png)](https://buymeacoffee.com/34by151)

# Easy Product Bundles for WooCommerce — AIM

An extension for **Easy Product Bundles for WooCommerce** (base) and its **Pro** add-on. Adds eleven features that give you precise control over how your product bundles look and behave on the product page — from hiding or linking item quantities, to customising messages, live price totals, image swaps, and layout adjustments. All settings are configured per bundle, directly inside the product editor under the **Product Bundles AIM** tab.

Built by [ArtInMetal.com.au](https://artinmetal.com.au) — [View on GitHub](https://github.com/34by151/easy-product-bundles-AIM)

---

## Requirements

| Dependency | Minimum version |
|---|---|
| WordPress | 6.0 |
| WooCommerce | 7.0 |
| Easy Product Bundles for WooCommerce | 6.17.0 |
| Easy Product Bundles for WooCommerce Pro | 6.17.0 |
| PHP | 7.4 |

---

## Installation

1. Go to the [Releases page](https://github.com/34by151/easy-product-bundles-AIM/releases) and download the `.zip` file for the latest version.
2. In your WordPress admin go to **Plugins → Add New → Upload Plugin**.
3. Choose the downloaded `.zip` file and click **Install Now**, then **Activate Plugin**.
4. Ensure both **Easy Product Bundles for WooCommerce** and **Easy Product Bundles for WooCommerce Pro** are installed and active. If either is missing, an error notice will appear in the admin and the plugin will not run.

Once activated, open any Easy Product Bundle product in the product editor. The **Product Bundles AIM** tab appears in the product data panel — this is where all features are configured.

**Updates** are delivered automatically through the standard WordPress **Dashboard → Updates** screen. WordPress checks for new releases approximately every 12 hours. To force an immediate check, click **Check again** on the Updates screen.

---

## Features

Features are grouped below by purpose. All settings are found in the **Product Bundles AIM** tab inside the product editor.

---

### Quantity Controls

Features 1–4 control whether customers can see and adjust item quantities, and how quantities relate to each other within the bundle.

---

#### Feature 1 — Hide Item Quantity

Hides the quantity input next to every bundled item on the product page. Customers can see and select items in the bundle but cannot change individual quantities — the bundle behaves as a fixed set. This is the simplest way to present a bundle where per-item quantities are not customer-adjustable.

When Features 2, 3, or 4 are also active on the same bundle, the quantity inputs remain functional in the background even though they are not visible — the quantity logic continues to work correctly.

**Admin panel:** *Item Display* → **Hide item quantity** checkbox. Applies the hide to all items in the bundle. Use Feature 3 to selectively show specific items.

---

#### Feature 2 — Quantity from Items

Automatically calculates and sets one item's quantity as the live running total of one or more other items' quantities. Customers see the result update instantly as they interact with the bundle — without needing to enter anything for the target item themselves.

For example: a customer selects 2 of Item A and 1 of Item B. A third item labelled "Total mounts required" automatically shows a quantity of 3.

Works well alongside Feature 1 — the target item's quantity can be hidden since it is set automatically. When you save the product, the plugin automatically adjusts the target item's maximum quantity setting to support the feature, so you do not need to configure that manually.

**Admin panel:** *Quantity from Items* section. Click **Add link** to create a rule. Choose one **target** item (the item whose quantity is set automatically) and one or more **source** items (the items being added together). Multiple rules can be added for different combinations.

---

#### Feature 3 — Per-item Show Quantity Override

When Feature 1 is hiding all quantity inputs, this lets you selectively keep specific items' quantity fields visible. Useful when most items have fixed quantities but a few should remain customer-adjustable.

This feature has no effect unless Feature 1 is active.

**Admin panel:** *Per-Item Quantity Display* section. A checkbox appears for each item in the bundle. Tick any item whose quantity input should remain visible even when Feature 1 is enabled.

---

#### Feature 4 — Max from Items

Dynamically limits how many of a target item a customer can select, based on how many of other items they have already chosen. The maximum updates live as the customer interacts with the bundle. If they have already selected most of the available capacity through other items, the remaining allowance for the target item is automatically reduced.

For example: the bundle has a total capacity of 4. A customer selects 3 of Item A. Item B's quantity input immediately caps at 1.

An optional on-page message can be shown next to the target item to explain the limit to customers.

**Admin panel:** *Max from Items* section. Click **Add link** to define a rule: choose the **target** item, the **source** items whose quantities reduce the available max, and a **base maximum** value. Enable **Max Qty Message** and enter a message (e.g. *"Maximum quantity for this bundle is 4"*) if you want the limit explained on the product page.

---

### Display & Messaging

Features 5–7 and 9–11 control what customers see and how the bundle layout is presented. They are independent of each other unless noted.

---

#### Feature 5 — Bundle Qty Message

Adds a text label directly before the main bundle quantity input on the product page. Without this, the quantity field has no label and customers may not know what they are setting a quantity for. Adding a label like *"Number of mounts"* makes it immediately clear.

**Admin panel:** *Bundle Qty Message* section. Enable the toggle and enter your label text.

---

#### Feature 6 — No Selection Message

Replaces the default *"Please select a product for all items."* alert that appears when a customer tries to add a bundle to the cart without completing all required selections. Lets you show a friendlier or more specific message that matches your store's tone.

Only the default alert text is replaced — any other messages the bundle plugin generates are left unchanged.

**Admin panel:** *No Selection Message* section. Enable the toggle and enter your custom message text.

---

#### Feature 7 — Qty × Unit Price Total

Adds a live three-line price breakdown to the bundle's price area, updating in real time as the customer adjusts the quantity or selects items:

```
Each Item:    £49.00
Quantity:          3
Total:        £147.00
```

Useful when customers are likely to order more than one bundle, making the total cost immediately visible. The unit price reflects any active discounts applied by the bundle plugin.

**Admin panel:** *Item Display* → **Show qty × unit price total** checkbox. Two text fields let you customise the label for the per-item row (default: *Each Item:*) and the total row (default: *Total:*). The *Quantity:* label is fixed. Both text fields are greyed out when the feature is disabled.

---

#### Feature 9 — Bundle Qty +/- Buttons

Replaces the browser's default quantity spinner arrows on the main bundle quantity input with styled **−** and **+** buttons that match the look of the individual item quantity controls already provided by the bundle plugin. This gives the page a more consistent, polished appearance — particularly noticeable when Feature 5 (Bundle Qty Message) is also active.

A sub-setting lets you scale the button size down if they appear too large relative to other elements on the page.

**Admin panel:** *Item Display* → **Use +/- buttons for bundle quantity** checkbox. When enabled, the **+/- Button Size** field (50–100) appears below it — lower the number to scale the buttons down proportionally. Default is 100 (no change).

---

#### Feature 10 — Swap Price Block and Qty Row Positions

Moves the price and totals display so it appears **below** the quantity input and Add to Cart button, rather than above it. By default the bundle plugin shows pricing above the action controls. This feature reverses that order — useful if your theme or bundle layout looks more natural with the Add to Cart controls higher on the page and the pricing summary below.

**Admin panel:** *Item Display* → **Swap price block and qty row positions** checkbox.

---

#### Feature 11 — Auto-fit Qty Row to Single Line

Automatically resizes the Add to Cart button so the quantity label (Feature 5), the quantity input and +/- buttons (Feature 9), and the Add to Cart button all sit on a single line rather than wrapping. If there is not enough space to fit everything without truncating the button text, the adjustment is skipped and the layout falls back to the theme default.

Most useful when Features 5 and 9 are also active, as those add width to the quantity row that may otherwise push the button onto a second line.

**Admin panel:** *Item Display* → **Auto-fit qty row to single line** checkbox.

---

### Image Swap Rules

---

#### Feature 8 — Image Swap Rules

Automatically swaps a required item's displayed image based on which optional items the customer has currently selected in the bundle. Different combinations of selected optional items can trigger different replacement images. When no rule matches, the original image is restored automatically.

For example: a bundle contains a required item (a wall bracket) and two optional items (a silver finish and a black finish). When the customer selects the silver finish option, the bracket image switches to show a silver bracket. When they select the black finish, it switches to the black bracket.

Rules can target the bundle item image, the main WooCommerce product gallery image, or both at the same time. Each optional item in a rule can also require a specific quantity — for example, only trigger the swap when the customer has selected exactly 2 of a particular optional item.

Multiple rules can be set up to cover all the combinations your bundle requires.

**Admin panel:** *Image Swap Rules* section. Click **Add rule** for each combination:

- **Change image of** — choose the required item whose image should change.
- **When these optional items are selected** — tick each optional item that must be selected to trigger this rule. Optionally enter a quantity beside each ticked item if an exact quantity match is required (leave blank to match any quantity).
- **Swap** — choose *Item image* (the image shown next to that item in the bundle), *Gallery image* (the main product photo), or *Both*.
- **Image** — click *Choose Image* to select the replacement from your media library.

---

## Detailed Logging

When troubleshooting a bundle that is not behaving as expected, enable **Detailed Logging** in the *Product Bundles AIM* tab. With logging on, diagnostic entries are written to your WordPress debug log (`wp-content/debug.log`) and to the browser console, covering each action the plugin takes as the customer interacts with the bundle.

Keep detailed logging **off** in normal use — enable it temporarily while investigating an issue, then disable it again.

---

## File Structure

```
easy-product-bundles-for-woocommerce-aim/
├── easy-product-bundles-for-woocommerce-aim.php   # Plugin bootstrap & constants
├── src/
│   ├── Plugin.php          # Singleton, wires Admin + Frontend + GitHubUpdater
│   ├── Admin.php           # Product tab, panel, save hooks, auto-enforcement
│   ├── Frontend.php        # Filter hooks, script/style enqueue, CSS injection
│   └── GitHubUpdater.php   # GitHub Releases auto-updater (hooks into WP update system)
└── assets/
    ├── aim-admin.css        # Admin panel styles
    ├── aim-admin.js         # Admin panel UI (item pickers, link management)
    ├── aim-frontend.css     # Frontend styles (qty wrapper, max message, qty-total line)
    └── aim-frontend.js      # Runtime features: qty linking, max enforcement, show-overrides, qty-total, image swap, qty +/-, position swap, auto-width row
```

---

## Changelog

### 1.2.0

- **GitHub auto-updater:** added `GitHubUpdater` class (`src/GitHubUpdater.php`). Hooks into the WordPress native plugin update system via `pre_set_site_transient_update_plugins`, `plugins_api`, and `upgrader_post_install`. Checks the GitHub Releases API (`/releases/latest`) for a newer tag, injects update data so the standard WordPress *Update now* button appears, populates the *View details* modal with version info and changelog (from the release body), and renames the extracted zip folder to the correct plugin slug after installation. Release data is cached as a transient for 12 hours (5 minutes on failure) to avoid hammering the API. To publish a new version: push code to GitHub and create a release tagged `vX.Y.Z`.
- **Plugin description — author:** `Author` header changed from `AIM` to `ArtInMetal.com.au`; `Author URI` set to `https://artinmetal.com.au`. WordPress displays this as a linked *By ArtInMetal.com.au* in the plugins list.
- **Plugin description — View details link:** `Plugin URI` set to `https://github.com/34by151/easy-product-bundles-AIM`. A *View details* link is added to the plugin row meta; clicking it opens the GitHub repository page.
- **Version bump:** `1.1.8` → `1.2.0`.

### 1.1.8

- **Performance — suppress checkout-block assets on non-checkout pages:** the base plugin unconditionally enqueues `wepb-checkout-integration` CSS and JS on every page, including the site homepage. This caused a render-blocking stylesheet request (~260 ms) and a chained script dependency delay (~3,929 ms) on pages that contain no WooCommerce checkout block. `Frontend.php` now hooks into `wp_enqueue_scripts` at priority 999 and dequeues + deregisters both assets on every page except `is_checkout()` and `is_cart()`. The `wp_deregister_*` calls prevent re-enqueue if another asset lists them as a dependency.

### 1.1.7

- **Feature 8 — per-required-item gallery slides:** each non-optional bundle item after the first that has a *Gallery image* or *Both* scope rule now gets its own WooCommerce gallery slide, injected on page load immediately after slide 1. Each injected slide displays the bundle item's configured default image (falls back to the item's product featured image). When a matching rule fires the slide image swaps; when no rule matches it restores automatically.
- **Feature 8 — FlexSlider thumbnail strip fix:** swapping a gallery slide image now also updates the corresponding thumbnail in the strip beneath the main image.
- **Feature 8 — WooCommerce zoom fix:** swapping a gallery slide now also updates the zoom plugin's cached image so the hover magnifier tracks the swapped image immediately.
- **Feature 8 — scope renamed:** *Main product image* → *Gallery image* (saved value `'main'` → `'gallery'`). All existing rules with `scope:'main'` are normalised to `'gallery'` automatically on save and on frontend delivery — no manual migration needed.
- **Feature 8 — thumbnail URL delivered to JS:** `image_thumb_url` (WooCommerce gallery-thumbnail size) is now included in each rule's JS data alongside `image_url`.
- **Feature 8 — `aim_non_optional_order`:** PHP now passes the ordered list of non-optional item indices to JS so it can map each required item to its correct gallery slide.

### 1.1.6

- Extended Feature 8: added per-item qty matching to Image Swap Rules. Each optional item in a rule now has an optional qty field (blank = any qty, number = exact match required).
- Admin UI: replaced the optional-items multi-select with a checkbox list so a qty number input can appear beside each item.
- Data format: `selected` in each rule is now an array of `{index, qty}` objects. Existing rules with the old integer-array format are automatically normalised on load — no manual migration needed.

### 1.1.5

- Added Feature 9 sub-setting: **+/- Button Size** — number input (50–100%) that scales the +/- buttons proportionally using CSS `zoom`. Default: 100 (no change).
- Added Feature 11: **Auto-fit qty row to single line** — measures available width in the form row and sets the Add to Cart button width to fill the remaining space. Uses an off-screen clone to determine the minimum button width; skips if available width is less than the minimum. Default: unchecked.

#### 1.1.5 — Bug fixes (post-release)

- **Feature 9 button size not scaling:** `wp_localize_script` serialises PHP values as strings; the size value was not being parsed as a number. Fixed with `parseInt()` + clamp (50–100).
- **Feature 11 auto-width target width corrected:** the button fills all remaining row space, with minimum text width used only as a floor guard. Added `flexWrap: nowrap` to prevent sub-pixel rounding from re-wrapping the button.

### 1.1.4

- Added Feature 10: **Swap price block and qty row positions** — moves `.asnp-totalPrice-wrapper` to appear after `form.cart`. Default: unchecked.

### 1.1.3

- Added Feature 9: **Bundle Qty +/- Buttons** — replaces browser spin arrows with styled − and + buttons matching the bundle item qty controls. Default: unchecked.

### 1.1.2

- Extended Feature 8: added per-rule *Swap* scope — *Item image*, *Gallery image*, or *Both*. Each destination is updated or restored independently based on the matched rule's scope.

### 1.1.1

- Added Feature 8: **Image Swap Rules** — swap a required item's image based on which optional items are selected. Supports any number of rules and any combination of optional items.

### 1.1.0

- Added Feature 5: **Bundle Qty Message** — label injected before the main qty input using a theme-safe flex container.
- Added Feature 6: **No Selection Message** — replaces the base plugin's default alert text with a custom message.

### 1.0.3

- Redesigned Feature 7: replaced single "N × unit = total" span with a three-row layout (Each Item / Quantity / Total).
- Added *Each Bundle Message* and *Bundle Total Message* admin settings.
- Fixed: Total row currency symbol now always displays correctly.

### 1.0.2

- Added Feature 7: **Qty × Unit Price Total** — live price breakdown driven by bundle quantity and `asnpWepbPriceChanged`.
- Flattened asset layout: `assets/css/` and `assets/js/` merged into a single `assets/` directory.

### 1.0.0

- Initial release with Features 1–4.
- Auto-enforcement of `edit_quantity` and `max_quantity` on save for Quantity from Items targets.
- Diagnostic detailed logging (PHP + JS).
