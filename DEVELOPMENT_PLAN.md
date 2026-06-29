# Development Plan — woo-tet-price-without-vat

Status: **v1.0.0 built — all files created, `php -l` / `php tests/run.php` / `phpcs` pass.**
Remaining before release: create the GitHub repo, register the `woo-tet-price-without-vat`
slug on plugins.ttrp.gr, and live-test on a WooCommerce store (see "Manual testing" below).

## Goal

A standalone WooCommerce plugin in the `woo-tet-*` suite that shows a product's
price **with and without VAT** on the storefront, with configurable audience
targeting. Replaces/generalises the "wholesale-only" dual-price behavior that the
WooCommerce Wholesale Prices plugin provides, so the dual price can be shown to
**everyone**, **logged-in users**, or **selected roles**.

## Decisions locked in (from requirements Q&A)

- **Delivery:** new standalone plugin (not a snippet, not edits to Wholesale Prices).
- **Surfaces:** single product page, shop / category archives, **and cart / checkout** —
  each independently toggleable from the settings page.
- **Variations:** show a range on archives; **live per-variation update** on the
  single product page via JS.
- **Audience model:** mode select — *Everyone* / *Logged-in only* / *Selected roles* —
  plus a role checklist used when mode = Selected roles. Default mode = **Everyone**.
  The store's existing Wholesale role appears automatically in the checklist.

## Naming / conventions (match the suite)

| Thing | Value |
|---|---|
| Plugin slug / folder | `woo-tet-price-without-vat` |
| Main file | `woo-tet-price-without-vat.php` |
| Text domain | `price-without-vat` (loaded on `init`) |
| Version constant | `TTRP_PWV_VERSION` (read from plugin header via `get_file_data`) |
| Function prefix | `ttrp_pwv_` (avoids collisions with siblings' generic `ttrp_` names) |
| Settings option key | `ttrp_pwv_settings` |
| Admin menu slug | `ttrp-price-without-vat` (under shared `ttrp-plugins` brand menu) |
| Starting version | `1.0.0` |

HPOS compatibility declared on `before_woocommerce_init` (plugin doesn't touch
orders, so unconditional). UI strings in English; Greek in `.po`/`.mo`.

## Settings schema (`ttrp_pwv_settings`)

```php
[
    'audience'     => 'everyone',          // 'everyone' | 'logged_in' | 'roles'
    'roles'        => [],                   // string[] of role keys (used when audience='roles')
    'show_single'  => 'yes',               // show on single product page
    'show_archive' => 'yes',               // show on shop / category archives
    'show_cart'    => 'no',                // show on cart page line items
    'show_checkout'=> 'no',                // show on checkout order review line items
    'show_incl'    => 'yes',               // render the "incl. VAT" line
    'show_excl'    => 'yes',               // render the "excl. VAT" line
    'label_incl'   => 'incl. VAT',         // label text (translatable default)
    'label_excl'   => 'excl. VAT',
]
```

## Technical approach

- **Audience gate** — `ttrp_pwv_should_display()`: `everyone` → true; `logged_in` →
  `is_user_logged_in()`; `roles` → current user's roles intersect settings `roles`.
  Pure helper `ttrp_pwv_user_matches( $user_roles, $allowed_roles )` is unit-tested.
- **Price computation** — `wc_get_price_including_tax( $product )` /
  `wc_get_price_excluding_tax( $product )` so per-product tax class & `tax_status`
  (taxable/none) are respected. For variable products use
  `get_variation_price('min'|'max')` + `wc_get_price_including_tax( $product, ['price'=>$p] )`
  to build a min–max range.
- **Render hooks** (separate from cart so we don't pollute mini-cart/widgets):
  - Single: `woocommerce_single_product_summary` priority 11 (right after price @10).
  - Archive: `woocommerce_after_shop_loop_item_title` priority 11 (after loop price @10).
  - Single + archive call the same `ttrp_pwv_render( $product )`.
  - Cart: `woocommerce_cart_item_price` (unit price) and/or `woocommerce_cart_item_subtotal`
    filters — append the dual-price note to the line item; respects qty for subtotal.
  - Checkout: `woocommerce_cart_item_subtotal` also fires in the checkout order-review
    table; gate by `is_checkout()` vs `is_cart()` so the cart/checkout toggles are
    independent. A shared `ttrp_pwv_cart_line( $product, $qty )` builds the markup.
- **Variation live update**:
  - `add_filter('woocommerce_available_variation', ...)` to inject formatted
    `ttrp_pwv_incl_html` / `ttrp_pwv_excl_html` into each variation's JS data.
  - `assets/pwv-variation.js` listens on `.variations_form` for `found_variation`
    (inject specific variation values) and `reset_data` (restore the range).
  - JS enqueued only on single product pages, only when display is allowed.
- **Output safety**: all rendered HTML escaped; labels via `esc_html`; price html
  from `wc_price()` (allowed via `wp_kses_post`). Nonce + `manage_options` on settings save.

## Files created

- [x] `woo-tet-price-without-vat.php` — bootstrap, settings, audience gate, render, variation filter
- [x] `update-checker.php` — copied from sibling (TTRP_Update_Checker), init with slug
- [x] `assets/price-without-vat.css` — styling for the dual-price block
- [x] `assets/pwv-variation.js` — live per-variation update
- [x] `assets/ttrp-admin.css` — copied from sibling (shared admin design system)
- [x] `assets/ttrp-logo.svg`, `assets/ttrp.svg` — copied from sibling (brand menu icon + footer)
- [x] `languages/price-without-vat.pot` — translation template
- [x] `languages/price-without-vat-el.po` / `.mo` — Greek (compiled with msgfmt)
- [x] `tests/run.php` — zero-dep tests for `ttrp_pwv_user_matches()` + `ttrp_pwv_lines_markup()`
- [x] `phpcs.xml.dist` — per-plugin prefix (`ttrp_pwv`/`TTRP_PWV`/`ttrp_brand`) + text-domain
- [x] `build.sh` — `git archive` zip builder
- [x] `.github/workflows/release.yml` — tag `v*` → build → GitHub release
- [x] `CHANGELOG.md`, `README.md`, `CLAUDE.md`

## Manual testing (do on a real WooCommerce store)

- [ ] Simple taxable product: incl/excl lines show on single + archive.
- [ ] Variable product: range shows; selecting a variation updates both lines; reset restores range.
- [ ] Non-taxable product (`tax_status` = none): nothing shown.
- [ ] Audience = Selected roles: visible only to chosen role (test with wholesale role + logged out).
- [ ] Cart/checkout toggles: line subtotal note appears only where enabled, qty-aware.
- [ ] Custom labels + single-line modes (incl only / excl only).
- [ ] Greek locale: labels and settings page translated.

## Build order (resume here)

1. Scaffold dirs + copy shared assets (ttrp-admin.css, ttrp-logo.svg, ttrp.svg, update-checker.php).
2. Write main plugin PHP: bootstrap + HPOS + textdomain + version constant.
3. Add settings page (defaults, helper, admin page, role checklist from `wp_roles()`).
4. Add audience gate + pure `ttrp_pwv_user_matches()` helper.
5. Add price computation + `ttrp_pwv_render()` + single/archive hooks.
6. Add cart/checkout line rendering (`woocommerce_cart_item_subtotal` / `_price`),
   gated by `show_cart` / `show_checkout` via `is_cart()` / `is_checkout()`.
7. Add `woocommerce_available_variation` filter + `pwv-variation.js` + conditional enqueue.
8. Add `price-without-vat.css`.
9. Wire `update-checker.php` init at bottom of main file.
10. Add `tests/run.php`; run `php tests/run.php` and `php -l`; run `phpcs`.
11. Translations (.pot → el .po/.mo).
12. build.sh + release workflow + phcs.xml.dist.
13. CHANGELOG / README / CLAUDE.md; final lint + version sanity.

## Open questions to confirm before/early in build

- **Default audience** — confirm *Everyone* is the right default (vs *Selected roles*
  preselected to the Wholesale role to mirror current site behavior).
- **Which line is "extra"** — store may already display one price (incl or excl) per
  WC tax display settings. Plan renders an explicit labelled block of both lines
  regardless; confirm that's wanted vs only showing the *missing* one.
- **Update server slug** — `woo-tet-price-without-vat` must be registered on
  plugins.ttrp.gr for the update checker to return info (otherwise updates just no-op).
