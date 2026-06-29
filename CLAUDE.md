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
├── update-checker.php              # TTRP_Update_Checker (checks plugins.ttrp.gr)
├── assets/
│   ├── price-without-vat.css       # Front-end styling for the dual-price block
│   ├── pwv-variation.js            # Live per-variation update on single product pages
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
| `woocommerce_single_product_summary` @11 | `ttrp_pwv_render_single` | After the price (10) |
| `woocommerce_after_shop_loop_item_title` @11 | `ttrp_pwv_render_archive` | After the loop price (10) |
| `woocommerce_cart_item_subtotal` | `ttrp_pwv_cart_item_subtotal` | Cart + checkout; `is_cart()`/`is_checkout()` gate the two toggles |
| `woocommerce_available_variation` | `ttrp_pwv_available_variation` | Injects per-variation `ttrp_pwv_html` for the JS |
| `wp_enqueue_scripts` | `ttrp_pwv_enqueue_front_assets` | CSS always; variation JS only on single product pages when displayed |

## Key behaviours to preserve

- **Audience gate** — `ttrp_pwv_should_display()` is checked in every render path.
  Pure helper `ttrp_pwv_user_matches( $user_roles, $allowed_roles )` is unit-tested.
- **Tax status** — non-`taxable` products/variations are skipped (no VAT distinction).
- **Variable products** — `ttrp_pwv_variable_range_html()` builds a min–max range with
  `wc_get_price_(in|ex)cluding_tax( $product, ['price'=>$raw] )`. The JS keeps the
  parent range markup and restores it on `reset_data`.
- **Roles list** — `ttrp_pwv_get_roles()` reads `wp_roles()->get_names()`, so any role
  (including Wholesale Customer) appears automatically.

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
