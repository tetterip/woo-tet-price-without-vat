# CLAUDE.md — Price With and Without VAT for WooCommerce

## What this plugin does

Shows product prices **with and without VAT** on the storefront, with configurable
audience targeting. Generalises the wholesale-only dual price from the WooCommerce
Wholesale Prices plugin so it can be shown to everyone, logged-in users, or selected
roles. Ships as two PHP files plus assets, languages, and a zero-dependency test —
no autoloader, no Composer, no build step beyond `build.sh` (git archive).

## File layout

```
woo-tet-price-without-vat/
├── woo-tet-price-without-vat.php   # All plugin logic
├── ttrp-common/                    # Shared ttrp.gr library: updates + "All Plugins" page (synced from the update server repo; never edit)
├── assets/
│   ├── price-without-vat.css       # Front-end styling for the dual-price block
│   ├── pwv-admin.js                # Settings page: Select2 roles + conditional row
│   ├── pwv-blocks.js               # Cart/Checkout blocks integration (wc.blocksCheckout)
│   ├── ttrp-admin.css              # Shared TTRP admin design system (settings page only)
│   ├── ttrp-logo.svg               # Brand menu icon
│   └── ttrp.svg                    # Settings footer logo
├── languages/                      # .pot + el .po/.mo (text domain: price-without-vat)
├── tests/run.php                   # Zero-dependency unit tests (php tests/run.php)
├── build.sh                        # git archive zip builder
└── .github/workflows/release.yml   # tag v* -> build -> GitHub release
```

## Conventions

- **Prefix:** functions/options use `ttrp_pwv_`; constants `TTRP_PWV_`. The shared
  brand-menu callback `ttrp_brand_dashboard` is guarded by `function_exists` so only
  one suite plugin defines it. (phpcs prefixes: `ttrp_pwv`, `TTRP_PWV`, `ttrp_brand`.)
- **Text domain:** `price-without-vat`, loaded on `init`. UI in English; Greek in `.po`.
  The plugin name "Price With/Without VAT" stays English in all locales.
- **Version:** `TTRP_PWV_VERSION` read from the plugin header via `get_file_data` — the
  header `Version:` line is the single source of truth.
- **HPOS:** declared compatible on `before_woocommerce_init` (plugin doesn't touch orders).

## Settings (`ttrp_pwv_settings` option)

```php
[
    'audience'      => 'everyone', // 'everyone' | 'logged_in' | 'roles'
    'roles'         => [],         // string[] role keys (used when audience='roles')
    'show_single'   => 'yes',
    'show_archive'  => 'yes',
    'show_cart'     => 'no',
    'show_checkout' => 'no',
    'show_incl'     => 'yes',
    'show_excl'     => 'yes',
    'label_incl'    => 'incl. VAT',
    'label_excl'    => 'excl. VAT',
]
```

Settings page lives under the shared **ttrp.gr Plugins** menu
(`ttrp-price-without-vat`). Saved via a nonce-protected POST handler
(`ttrp_pwv_save_settings`), sanitized field-by-field. `ttrp_pwv_settings()` caches
the merged option in a per-request static.

`assets/pwv-admin.js` (enqueued with `wc-enhanced-select`) drives the settings UI:
the roles field is a Select2 multi-select that is only shown when the audience is
"Selected user roles". The display locations are a single labelled row of checkboxes.

## Hook map

| Hook | Function | Notes |
|---|---|---|
| `before_woocommerce_init` | (closure) | HPOS compatibility |
| `init` | `ttrp_pwv_init` | textdomain, admin menu, conditional front-end hooks |
| `woocommerce_get_price_html` @99 | `ttrp_pwv_filter_price_html` | Single + archive; works for classic, block templates, and Elementor since they all call `get_price_html()`. Variations update natively (each variation's price HTML runs through it). |
| `woocommerce_cart_item_subtotal` @99 | `ttrp_pwv_cart_item_subtotal` | Classic/Elementor cart + checkout; **replaces** the subtotal with the two VAT lines. `is_cart()`/`is_checkout()` gate the two toggles |
| `woocommerce_blocks_loaded` | `ttrp_pwv_register_store_api_data` | Exposes per-line incl/excl on the Store API `cart-item` endpoint |
| `wp_enqueue_scripts` | `ttrp_pwv_enqueue_front_assets` | Front-end CSS |
| `wp_enqueue_scripts` | `ttrp_pwv_enqueue_block_assets` | `pwv-blocks.js` on block cart/checkout (depends on `wc-blocks-checkout`) |

## Key behaviours to preserve

- **Audience gate** — `ttrp_pwv_should_display()` is checked in every render path; it also
  returns false when `wc_tax_enabled()` is false. Pure helper
  `ttrp_pwv_user_matches( $user_roles, $allowed_roles )` is unit-tested.
- **Single context** — `ttrp_pwv_filter_price_html` only acts on the main product
  (`ttrp_pwv_is_main_product()`, which resolves variations to their parent), so related/
  upsell products on a single page are not annotated.
- **Tax status** — non-`taxable` products/variations are skipped (no VAT distinction).
- **Variable products** — `ttrp_pwv_variable_range_html()` builds a min–max range; selected
  variations are handled by WC's native variation price update (no custom JS).
- **Display model** — `ttrp_pwv_suffix_parts()` keeps WooCommerce's main price, adds a
  VAT **suffix** matching the tax-display mode (`ttrp_pwv_display_mode()` →
  `woocommerce_tax_display_shop` / `_cart`), and appends **one** line for the complementary
  VAT price. `show_incl` / `show_excl` each gate their VAT side (suffix when it matches the
  display, line when it's the complement). It returns `['suffix', 'line']` so callers can
  place them independently; `ttrp_pwv_suffix_and_line()` is the concatenated form used by the
  cart path. Classic cart appends to the subtotal; the blocks path (`pwv-blocks.js`) reformats
  `subtotalPriceFormat` the same way (`<price/>` + suffix + complement).
- **Suffix placement (single/archive)** — `ttrp_pwv_merge_price_html()` merges the parts into
  the price HTML: normally the suffix is appended inline after the main price, but when the
  WooCommerce Wholesale Prices plugin's block-level `.wholesale_price_container` is present it
  injects the suffix inside the container (after the `wholesale-price-suffix` slot) so it stays
  next to the wholesale amount instead of wrapping below. The complementary line is always last.
- **Roles list** — `ttrp_pwv_get_roles()` reads `wp_roles()->get_names()`, so any role
  (including Wholesale Customer) appears automatically.
- **Wholesale Prices integration** — `ttrp_pwv_wholesale_prices()` / `ttrp_pwv_wholesale_range()`
  read the WooCommerce Wholesale Prices plugin's raw wholesale price
  (`WWP_Wholesale_Prices::get_product_raw_wholesale_price()` and the
  `{role}_min_wholesale_price` / `{role}_max_wholesale_price` meta) for the current user's
  wholesale role (`WWP_Wholesale_Roles::getInstance()->getUserWholesaleRole()`), then run it
  through WC's tax functions. Returns null (→ regular price) when not applicable. Only the
  product/archive path needs this; cart/checkout already price the wholesale cart item.

## Requirements

- PHP 7.4+, WordPress 6.0+, WooCommerce 7.0+. No Composer, no node_modules.

## Releasing

Follow the suite version-bump checklist (plugin header + CHANGELOG + README + CLAUDE.md).
Push a `v*` tag; the release workflow runs `build.sh` and publishes the zip. The update
checker slug `woo-tet-price-without-vat` must be registered on plugins.ttrp.gr for
in-dashboard updates to return info.

## Testing

`php tests/run.php` — covers `ttrp_pwv_user_matches()` and the `ttrp_pwv_lines_markup()`
output assembly. Run `php -l` and `phpcs` (shared ruleset + this plugin's prefix file)
before releasing.

## Proposed features / backlog (review 2026-09-26)

Proposals from a suite-wide review, **not yet approved or scheduled** — discuss with the user before implementing. Remove or tick items here as they ship.

1. **Run the manual checklist** in `DEVELOPMENT_PLAN.md` (all unchecked) on WordPress Playground, then retire that file: it still mentions `update-checker.php` (replaced by `ttrp-common`) and build-time open questions. Move anything still true into this file.
2. **Net totals for B2B** — "Subtotal / Total excl. VAT" rows in cart, checkout and order emails, not only per line.
3. **VAT amount line** (optional) — e.g. "ΦΠΑ 24%: €3.87" under the price.
4. **Per-role tax display** — make excl. VAT the *main* price for chosen roles (wholesale), incl. VAT for everyone else, instead of only adding a second line.
5. **Exclusions** — hide the dual price for selected products / categories.
6. **Quantity-aware product page** — update both prices live when the quantity changes.
- **Tests + CI (suite item S1)** — `tests/run.php` already exists (24 tests); add `.github/workflows/tests.yml` (PHP lint + `php tests/run.php` on 7.4 / 8.1 / 8.2) as in `woo-tet-acs-tracking`.
