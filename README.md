# Price With and Without VAT for WooCommerce

A lightweight WooCommerce plugin that shows product prices **with and without VAT** on the single product page, shop/category archives, and cart/checkout. Display can be limited to everyone, logged-in users, or selected user roles (e.g. wholesale customers) — a generalisation of the wholesale-only dual price that the WooCommerce Wholesale Prices plugin offers.

---

## Features

- Shows both the **incl. VAT** and **excl. VAT** price using WooCommerce's own tax calculations (`wc_get_price_including_tax()` / `wc_get_price_excluding_tax()`), so each product's tax class and tax status are respected.
- **Audience targeting:** show the dual price to *everyone*, *logged-in users only*, or *selected user roles*. The roles list is built from your site's roles, so the Wholesale Customer role appears automatically.
- **Per-location toggles:** enable/disable independently on the single product page, shop/category archives, cart, and checkout.
- **Variable products:** shows a price range on archives, and updates live to the selected variation's prices on the product page.
- **Configurable lines & labels:** toggle each line on/off and customise the "incl. VAT" / "excl. VAT" labels.
- Non-taxable products are skipped automatically (no misleading "with/without VAT" when there is no VAT).
- Translation-ready — ships with English (default) and Greek (`el`) translations.
- Declared compatible with WooCommerce HPOS (High-Performance Order Storage).

---

## Requirements

- WordPress 6.0+
- WooCommerce 7.0+
- PHP 7.4+

---

## Installation

1. Download the latest zip from the [Releases](https://github.com/tetterip/woo-tet-price-without-vat/releases) page.
2. Go to **WP Admin > Plugins > Add New > Upload Plugin** and upload the zip.
3. Activate the plugin.
4. Configure it under **ttrp.gr Plugins > Price With/Without VAT**.

---

## Settings

**ttrp.gr Plugins > Price With/Without VAT**

| Setting | Default | Notes |
|---|---|---|
| Show prices to | Everyone | `Everyone` / `Logged-in users only` / `Selected user roles` |
| User roles | — | Used only when "Selected user roles" is chosen |
| Single product page | On | |
| Shop / category archives | On | |
| Cart | Off | Appended to each line item's subtotal |
| Checkout | Off | Appended to each line item's subtotal in the order review |
| Price including VAT | On | Toggle + custom label |
| Price excluding VAT | On | Toggle + custom label |

---

## How it works

- **Single product / archives:** the dual-price block is rendered after the price via `woocommerce_single_product_summary` (priority 11) and `woocommerce_after_shop_loop_item_title` (priority 11).
- **Cart / checkout:** the block is appended to the line subtotal via `woocommerce_cart_item_subtotal`, with `is_cart()` / `is_checkout()` deciding which toggle applies.
- **Variations:** per-variation incl/excl HTML is injected into the variation data via `woocommerce_available_variation`, and `assets/pwv-variation.js` swaps it in on the `found_variation` event (restoring the range on `reset_data`).

---

## License

GPL-2.0+
