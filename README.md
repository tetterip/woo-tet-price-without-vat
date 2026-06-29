# Price With and Without VAT for WooCommerce

A lightweight WooCommerce plugin that shows product prices **with and without VAT** on the single product page, shop/category archives, and cart/checkout. Display can be limited to everyone, logged-in users, or selected user roles (e.g. wholesale customers) — a generalisation of the wholesale-only dual price that the WooCommerce Wholesale Prices plugin offers.

---

## Features

- Shows both the **incl. VAT** and **excl. VAT** price using WooCommerce's own tax calculations (`wc_get_price_including_tax()` / `wc_get_price_excluding_tax()`), so each product's tax class and tax status are respected.
- **Audience targeting:** show the dual price to *everyone*, *logged-in users only*, or *selected user roles*. The roles list is built from your site's roles, so the Wholesale Customer role appears automatically.
- **Per-location toggles:** enable/disable independently on the single product page, shop/category archives, cart, and checkout.
- **Works with classic themes, block themes, and Elementor** — prices are added through the `woocommerce_get_price_html` filter, which Elementor's Product Price widget, the `woocommerce/product-price` block, and classic templates all use.
- **Cart & Checkout (classic, Elementor, and blocks):** shows exactly two prices — with and without VAT — instead of adding a redundant third next to the existing one.
- **Variable products:** shows a price range, and selected variations update natively.
- **Configurable lines & labels:** toggle each line on/off and customise the "incl. VAT" / "excl. VAT" labels.
- Non-taxable products and tax-disabled stores are skipped automatically.
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

- **Single product / archives:** the dual-price lines are appended to the price via the `woocommerce_get_price_html` filter. Because Elementor's Product Price widget, the `woocommerce/product-price` block, and classic templates all call `get_price_html()`, this covers every theme/builder, and variations update natively.
- **Cart / checkout (classic & Elementor):** the line subtotal is *replaced* with the with/without VAT lines via `woocommerce_cart_item_subtotal`, so only two prices show. `is_cart()` / `is_checkout()` decide which toggle applies.
- **Cart / checkout (blocks):** per-line incl/excl prices are exposed on the Store API `cart-items` endpoint (`woocommerce_store_api_register_endpoint_data`), and `assets/pwv-blocks.js` reformats the line subtotal via WooCommerce's `wc.blocksCheckout` registered filters — no build step required.

---

## License

GPL-2.0+
