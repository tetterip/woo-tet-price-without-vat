# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
