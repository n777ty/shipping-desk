=== Shipping Desk ===
Contributors: jailbreak777
Tags: woocommerce, shipping, shipping labels, easypost, shippo
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 7.0
Stable tag: 1.3.0
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

= 1.3.0 =

* Changed: tracking numbers are handed to the site's tracking plugin instead of written into an order note. Advanced Shipment Tracking first, then WooCommerce Shipment Tracking; the customer is emailed a note only when neither is installed.
* New: refunding a label removes its tracking number from the tracking plugin, so a replacement label's email shows only the live number instead of the void one beside it. Reshipping without a refund keeps both numbers, because both parcels really shipped. A reset removes the number only if that label was refunded - a label that is still valid stays on the order. A label refunded in the provider's own dashboard is removed too, as soon as the plugin learns of it.
* New: the private note now records the provider reference (for example the EasyPost shipment id) and no longer repeats the carrier and tracking number, which AST's integrations parse and would import a second time.
* New: a Tracking section in Settings shows which plugin is receiving tracking, plus a "Complete on purchase" option that moves the order to Completed on every purchase, whichever plugin is installed.
* New: with no tracking plugin installed, the customer note carrying the tracking number is emailed with the subject "Your order has shipped" instead of WooCommerce's "A note has been added to your order".
* New: the Insurance field states what the value costs ("~$2.50 premium"), or says plainly that nothing is insured. The EasyPost estimate no longer depends on rates having been fetched to appear.
* New: a bought label is checked against the insurance that was asked for. EasyPost accepts the declared value whether or not the carrier applies cover, so the answer is read from the bought shipment: if it comes back uninsured or short, the order gets a note saying so and the operator is told straight away - in bulk runs too. Bulk purchases also record the insured value and premium now, which they previously ignored.
* New: a label that records insurance with no premium behind it is flagged as well. EasyPost charges cover as its own fee, so a declared value with no fee means either the cover was never charged or the shipment was never bought - the order gets a note saying to check the dashboard, and a bulk run lists it in the summary. It only fires when a label was actually produced, so a test-mode shipment that omits the fee fields is left alone.

* Fixed: saving one Settings section wiped the others. Every option on the page shared a single settings group, and WordPress saves a group by overwriting all of its options, setting to null any field missing from the submitted form - so ticking the reship checkbox erased the provider, every API key and the from address. Each form now has its own group, checkboxes post an explicit 0, and the legacy EasyPost key is no longer registered.
* Fixed: a normal purchase on a Processing order was logged as "reshipped completed order", because the reship check ran after the new completion step. It now compares against the status from before the purchase. The label note is also written before the tracking hand-off, so the timeline reads in the order things happened.
* Fixed: the rate column showed only the carrier's own price when the Insurance value was typed before Get Rates. The totals were restated only when that field changed, and the change handler ignores a value that has not moved since the quote - so the normal order of work never showed EasyPost's estimated premium. Rates are now restated as soon as they arrive.
* Fixed: the reship panel never showed an insurance premium. It rebuilds the order row from the server's HTML but copied only three of the row's data attributes, dropping the one naming the provider - so the premium was treated as zero before the declared value was even read. The panel now copies the whole attribute set.
* Fixed: switching provider - or its test/production mode - left the previous provider's rates in the Rates column, where they could still be bought with a shipment id the newly selected provider does not know. Quotes are now stamped with the account that priced them, ignored when that no longer matches, and cleared on the switch itself.
* Fixed: a PHP error raised after a label was bought - by a notification hook or a third-party email template, for example - was reported as a failed purchase, inviting a second buy. The purchase is now reported as successful with the error named, because the label and its tracking number already exist.

= 1.2.0 =
* New: a "Today" badge in the header showing what has been spent on postage since midnight, with a label count. It resets by itself at midnight and costs one option read per page; nothing is queried and nothing is shown on a day with no purchases.
* Note: the total is what was charged (postage plus insurance where the provider reports it). A refund later in the day does not reduce it.


= 1.1.3 =
* Changed: "Visit plugin site" now goes to the GitHub repository instead of the author's Telegram. The "By Jailbreak777" link is unchanged.


= 1.1.2 =
* Fixed: after an update, the Plugins screen could keep offering the version that had just been installed. The check compared the release against the code loaded in that request, which during an update's own re-check is still the previous version; it now compares against the plugin header on disk.


= 1.1.1 =
* New: a Settings link under the plugin name on the Plugins screen, going straight to the plugin's Settings page.


= 1.1.0 =
* New: updates are offered in Plugins, taken from the published GitHub releases, with one-click updating. The release's published checksum is verified before anything is installed.
* New: "View version details" shows the changelog, and a one-time "What's new" popup appears after an update - once per version, per person.
* Changed: only releases are used, never a branch or a tag archive, so the plugin always stays in its own folder.


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
