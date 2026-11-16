=== Shipping Desk ===
Contributors: jailbreak777
Tags: woocommerce, shipping, shipping labels, easypost, shippo
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 7.0
Stable tag: 1.0.5
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Buy, print and refund EasyPost, Shippo or Veeqo shipping labels from the WooCommerce orders screen.

== Description ==

Shipping Desk puts the whole label workflow on one screen: pick a box, get rates, choose a service, buy, print. Three label providers are supported and switched with one radio button; existing labels keep working after a switch, because print and refund follow the provider that sold them.

**Rate shopping**

* One rate request per order across every carrier the provider has enabled.
* The service that matches the order's chosen shipping method is preselected ("Best match"), cheapest second.
* Rates are cached with their expiry, so revisiting an order does not pay for a second quote.
* Address validation runs before rates when the address has no ZIP+4, and the operator decides whether to accept a correction.

**Buying**

* Box presets with weight auto-filled from the products plus the box tare.
* Signature confirmation and declared value (insurance) are priced into the quote where the provider allows it.
* Bulk buy and bulk buy + print, with per-order results: one failure never takes the batch down.
* Labels are bought as 4x6 PDF. Carriers that return a different page size — UPS at 4x7, FedEx on a US letter page — are cropped to a true 4x6 page box before printing, so a 4x6 thermal printer prints at 100% instead of scaling.

**Printing**

* Browser printing, or silent server-side printing through PrintNode.
* Both paths receive the same 4x6 file.

**Providers**

* **EasyPost** — test and production keys side by side, with a mode switch in the header.
* **Shippo** — test and production keys side by side.
* **Veeqo** — the original provider, unchanged for existing installs.

Each provider has its own rate limits and the plugin paces bulk work to match: bulk purchases and bulk rate requests are spaced, and a 429 slows the rest of the run rather than being retried into the same wall.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install the zip through **Plugins → Add New → Upload Plugin**.
2. Activate **Shipping Desk**.
3. Open **Shipping Desk → Settings** and paste the API key for the provider you use, then choose the test/live key mode next to the provider selector.
4. Optional: for silent printing, add a PrintNode API key, refresh the printer list and pick a printer.

= Upgrading from Veeqo Shipping =

If the older **Veeqo Shipping** plugin (plugin folder `veeqo-shipping`) is installed, deactivate it before activating Shipping Desk: they are different plugins to WordPress, and both active at once would double-register the order-screen hooks. This build does not read that build's options or its per-order label data, so if you are moving from it, keep the old plugin installed until you have finished printing or refunding any label you still need there.

== Frequently Asked Questions ==

= Do I need all three providers? =

No. One key is enough; the provider buttons switch between the ones you have configured.

= Will my existing labels still print and refund after switching provider? =

Yes. Every order records the provider that bought its label, and print, refund and the tracking link follow that record rather than the current setting.

= Why is my label printing small in the bottom corner? =

That is the 4x6 printer fitting a larger page. Shipping Desk crops the page box to 4x6 before printing, on both the browser and PrintNode paths. If you print a label saved from the provider's own dashboard, it still has the carrier's page size and will scale.

= Where do I get API keys? =

EasyPost: Account → API Keys. Shippo: Settings → API. Veeqo: Settings → API keys. Test keys are free to use; labels bought with them are not real.

= Does it work with High-Performance Order Storage (HPOS)? =

Yes. The plugin declares HPOS compatibility and reads and writes order data through the WooCommerce CRUD API.

== Screenshots ==

1. The orders screen with provider buttons, test/live chip and the rates panel.
2. A rate list with the matching service preselected.
3. Printable label options, including PrintNode.

== Changelog ==

= 1.0.5 =
* Removed: the confirmation prompt on Buy Label. Buying is one click again. The insurance premium is already shown in the rate column, so the dialog only restated it.


= 1.0.4 =
* Changed: the rate column's insurance breakdown is shorter - "$10.70 ($8.20 + ~$2.50 ins.)" instead of "$10.70 (base $8.20 + $2.50 est. insurance)". The tilde marks the premium as an estimate.


= 1.0.3 =
* New: EasyPost rates now show the insurance premium alongside the postage, e.g. "$10.70 (base $8.20 + $2.50 est. insurance)", so the rate column answers "what will this cost" before buying. The premium follows EasyPost's rule: one cent per dollar of declared value, rounded half up, with a $1.00 minimum.
* Changed: changing the declared value on an EasyPost order no longer discards the loaded quotes, because its quotes do not depend on insurance. Shippo still re-quotes.


= 1.0.2 =
* New: buying a label now asks for confirmation and states the insurance that will be requested, with EasyPost's estimated charge. A blank insurance field is called out at that moment, because an uninsured label cannot be insured afterwards.
* Changed: the orders row carries the current provider, so the confirmation can be provider-specific without leaking one provider's vocabulary into the other.


= 1.0.1 =
* Fixed: an insured EasyPost label could record the insurance premium alone as the label's cost, because the postage fee was recognised under one spelling and not the other. Both are accepted now, and the test covers each spelling plus an uncharged premium.
* New: the order now records what was insured and what the cover cost; the order note says "Insured for $X (premium $Y)" and the purchase confirmation repeats it.
* Changed: the insurance field's tooltip states EasyPost's pricing (1% of the declared value, $1.00 minimum, $5,000 maximum, charged at purchase).

= 1.0.0 =
* First public release. Formerly an internal plugin built around Veeqo, now provider-neutral: EasyPost, Shippo and Veeqo.
* EasyPost support: rates, labels, refunds, insurance, signature confirmation, address validation.
* Rate matching against the order's shipping method.
* Labels normalised to a 4x6 page box for both browser and PrintNode printing.
* Bulk buying in chunks with paced requests and adaptive backoff on HTTP 429.

== Upgrade Notice ==

= 1.0.0 =
First public release. If the older Veeqo Shipping plugin is installed, deactivate it before activating this one — see "Upgrading from Veeqo Shipping" above.
