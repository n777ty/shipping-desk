# Shipping Desk for WooCommerce

Buy, print and refund **EasyPost**, **Shippo** or **Veeqo** shipping labels from the
WooCommerce orders screen. Three providers, one workflow, and the ability to switch
between them without losing the ability to print or refund labels you already bought.

Requires WooCommerce 7.0+, WordPress 6.0+, PHP 7.4+. Licensed GPL-2.0-or-later.
HPOS (High-Performance Order Storage) compatible.

## What it does

**Rate shopping** — one request per order across every carrier the provider has enabled.
The service matching the order's chosen shipping method is preselected ("Best match"),
with the cheapest as the fallback. Quotes are cached with their expiry so revisiting an
order doesn't pay for a second quote.

**Address validation** — runs before rates when an address has no ZIP+4. The operator sees
the standardised address next to the one as entered and decides; nothing is rewritten
silently.

**Buying** — box presets with weight auto-filled from the products plus box tare, signature
confirmation and declared value priced into the quote where the provider allows it, and
bulk buy with per-order results: one failed label never takes a batch down.

**Printing** — browser printing or silent server-side printing through
[PrintNode](https://www.printnode.com/). Both paths receive the same file: labels are bought
as 4x6 PDFs, and carriers that return a different page size — UPS at 4x7, FedEx on a US
letter page — are cropped to a true 4x6 page box, so a 4x6 thermal printer prints at 100%
instead of scaling the label down to a quarter of the stock.

**Refunds** — refund or cancel through the provider that sold the label, including the ones
bought before you switched providers. Each order records who sold its label.

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`, or install the zip through
   **Plugins → Add New → Upload Plugin**.
2. Activate **Shipping Desk**.
3. Open **Shipping Desk → Settings**, paste the API key for the provider you use, and pick
   the test or production key mode next to the provider selector.
4. Optional, for silent printing: add a PrintNode API key, refresh the printer list and
   choose a printer.

## Providers

| Provider | Keys | Notes |
|---|---|---|
| EasyPost | test `EZTK…` and production `EZAK…`, side by side | also used for address validation |
| Shippo | test and production, side by side | includes the V2 address validator |
| Veeqo | a single API key | the original provider |

Rate limits differ by provider and by key: Shippo allows 500 POSTs a minute on a live key
but 50 on a test key, and EasyPost publishes no writes-per-second figure. Bulk work is paced
to match, and an HTTP 429 slows the rest of a run rather than being retried at the same pace
that caused it.

## Development

No Composer, no PHPUnit: six standalone checks that run anywhere PHP runs.

```bash
php tests/helpers-test.php
php tests/veeqo-client-rate-test.php
php tests/shippo-client-reflection-test.php
php tests/easypost-client-test.php
php tests/easypost-provider-test.php
php tests/rebrand-guard-test.php    # fails if a pre-1.0 internal name returns
```

Each exits 0 on success. They stub the WordPress HTTP layer, so they exercise the real
payloads — the shipment bodies, the option and meta keys, the retry and backoff behaviour —
without a WordPress install or an API key.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
