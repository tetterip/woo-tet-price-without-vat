# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.5.0] - 2026-06-30

### Fixed
- When the WooCommerce Wholesale Prices plugin is active, the VAT suffix was rendering on its own line *below* the wholesale price because the plugin wraps it in a block-level `.wholesale_price_container`. The suffix is now injected inside that container (right after the wholesale price), so it sits inline next to the wholesale amount on single product and archive pages.

---

## [1.4.0] - 2026-06-29

### Changed
- Reworked the display everywhere (single, archive, cart, checkout): instead of replacing/adding two labelled price lines, the plugin now **keeps WooCommerce's main price**, adds a **VAT suffix** to it matching the store's tax-display setting (`woocommerce_tax_display_shop` / `woocommerce_tax_display_cart`), and adds **one** line for the complementary VAT price. So a store showing prices incl. VAT renders e.g. `10,00 € incl. VAT` + a single `8,06 € excl. VAT` line. The `show incl` / `show excl` toggles now control each VAT side (whichever matches the display becomes the suffix, the other becomes the line).

---

## [1.3.0] - 2026-06-29

### Added
- **WooCommerce Wholesale Prices integration.** For logged-in wholesale customers, the with/without VAT lines on product and archive pages are now computed from the wholesale price (simple products, variations, and the variable-product range) instead of the regular retail price. Reads the raw wholesale price via `WWP_Wholesale_Prices::get_product_raw_wholesale_price()` and the `{role}_min_wholesale_price` / `{role}_max_wholesale_price` meta, then applies WooCommerce's own tax functions so the VAT math matches regular products. Falls back to the regular price when the plugin is inactive, the user isn't a wholesale customer, or no wholesale price is set. (Cart/checkout already used the wholesale price WooCommerce applies to the cart.)

---

## [1.2.0] - 2026-06-29

### Added
- Cart & Checkout **blocks** support via the Store API (`woocommerce_store_api_register_endpoint_data`) plus a no-build JS integration (`assets/pwv-blocks.js`) using WooCommerce's `wc.blocksCheckout` registered filters.
- Skip display entirely when WooCommerce taxes are disabled.

### Changed
- Single product and shop/archive prices now render via the `woocommerce_get_price_html` filter instead of classic template actions, so they work with classic themes, block templates, and page builders (Elementor custom single/loop templates) alike.
- Variations now update natively (each variation's price HTML runs through the same filter) — the custom variation script is no longer needed.
- Cart & Checkout now show exactly **two prices** (with and without VAT) by replacing the line subtotal, instead of appending two prices after the existing one (which produced three).

### Removed
- `assets/pwv-variation.js` and the `woocommerce_available_variation` injection (superseded by the price-HTML filter).

---

## [1.1.0] - 2026-06-29

### Changed
- Settings page: the "User roles" field is now a Select2 multi-select instead of a checkbox list.
- The "User roles" row is shown only when the audience is set to "Selected user roles".
- "Display locations" is now a single row labelled "Show in these locations" with the locations as checkboxes; the archive location label is shortened to "Shop".

---

## [1.0.0] - 2026-06-29

### Added
- Initial release.
- Shows product prices with and without VAT on the single product page, shop/category archives, and cart/checkout.
- Audience targeting: everyone, logged-in users only, or selected user roles.
- Independent per-location toggles (single, archives, cart, checkout).
- Per-line toggles and custom labels for the incl. VAT and excl. VAT lines.
- Live per-variation price updates on the single product page for variable products; price range on archives.
- Minimum WooCommerce version check (7.0) — shows an admin notice and stays inactive on older versions.
- English (default) and Greek (`el`) translations.
- Declared compatible with WooCommerce HPOS.
