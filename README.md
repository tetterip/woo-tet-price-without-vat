# Price With and Without VAT for WooCommerce

A lightweight WooCommerce plugin that shows product prices **with and without VAT** on the single product page, shop/category archives, and cart/checkout. Display can be limited to everyone, logged-in users, or selected user roles (e.g. wholesale customers) — a generalisation of the wholesale-only dual price that the WooCommerce Wholesale Prices plugin offers.

---

## Features

- Shows both the **incl. VAT** and **excl. VAT** price using WooCommerce's own tax calculations (`wc_get_price_including_tax()` / `wc_get_price_excluding_tax()`), so each product's tax class and tax status are respected.
- **Audience targeting:** show the dual price to *everyone*, *logged-in users only*, or *selected user roles*. The roles list is built from your site's roles, so the Wholesale Customer role appears automatically.
- **WooCommerce Wholesale Prices aware:** for logged-in wholesale customers, the with/without VAT prices are computed from the wholesale price (simple, variation, and variable range), falling back to the regular price for everyone else.
- **Per-location toggles:** enable/disable independently on the single product page, shop/category archives, cart, and checkout.
- **Works with classic themes, block themes, and Elementor** — prices are added through the `woocommerce_get_price_html` filter, which Elementor's Product Price widget, the `woocommerce/product-price` block, and classic templates all use.
- **Keeps WooCommerce's price + suffix + one line:** the main price is left as WooCommerce renders it, gets a VAT suffix matching your tax-display setting (e.g. `incl. VAT`), and the complementary price is added on one line below (e.g. `excl. VAT`). Works on product, archive, cart, and checkout (classic, Elementor, and blocks).
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

- **Single product / archives:** a VAT suffix and the complementary price line are appended to the price via the `woocommerce_get_price_html` filter. Because Elementor's Product Price widget, the `woocommerce/product-price` block, and classic templates all call `get_price_html()`, this covers every theme/builder, and variations update natively.
- **Cart / checkout (classic & Elementor):** the suffix + complementary line are appended to the line subtotal via `woocommerce_cart_item_subtotal`. `is_cart()` / `is_checkout()` decide which toggle applies.
- **Cart / checkout (blocks):** per-line incl/excl prices are exposed on the Store API `cart-item` endpoint (`woocommerce_store_api_register_endpoint_data`), and `assets/pwv-blocks.js` reformats the line subtotal via WooCommerce's `wc.blocksCheckout` registered filters (suffix + complementary) — no build step required.
- **Which side is which:** the price matching your *tax display* setting becomes the suffix; the other becomes the line. Driven by `woocommerce_tax_display_shop` (product/archive) and `woocommerce_tax_display_cart` (cart/checkout).
- **Wholesale Prices compatibility:** when the WooCommerce Wholesale Prices plugin renders its block-level wholesale price container, the VAT suffix is injected inside it (next to the wholesale price) instead of being appended after it, so it stays inline rather than wrapping onto a new line.

---

## Updates and privacy

This plugin gets its updates from the ttrp.gr update server (`https://plugins.ttrp.gr/`), not from WordPress.org.
When WordPress checks for updates, and when you open **ttrp.gr Plugins → All Plugins**, your site sends that server:

- the plugin's slug and installed version,
- the WordPress version and the site's address (in the request's `User-Agent` header),
- the admin language (All Plugins page only).

The server logs these requests, with the IP address, for 180 days and keeps a list of which sites run which plugin
version. No order, customer or other personal data is sent. Questions: info@ttrp.gr. Details: [privacy policy](https://plugins.ttrp.gr/?page=privacy&lang=en) and [terms of use](https://plugins.ttrp.gr/?page=terms&lang=en); administrators see a one-time notice about this in wp-admin.

## Security

Please report vulnerabilities privately to **info@ttrp.gr**, not in a public issue. See [SECURITY.md](SECURITY.md).

---

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).
