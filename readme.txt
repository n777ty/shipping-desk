=== Shipping Desk ===
Contributors: jailbreak777
Tags: woocommerce, shipping, shipping labels, easypost, shippo
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 7.0
Stable tag: 1.5.0
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

= 1.5.0 =
* New: address standardisation on the block checkout. The block checkout has no place to show the suggestion box, so the correction is applied on the server when the order is created: an address that only gains a ZIP+4, loses a formatting quirk, or has a suite folded into line 1 is written to the order with a note saying so, while anything that could move the parcel is left for the orders screen. One cached verifier call per order, nothing is ever blocked, and returning false from `shipping_desk_block_checkout_standardise` turns it off.
* New: the settings screen says which checkout the store is on, so a store using the block checkout is never told suggestions are running when there is nowhere to show them.
* Note: the checkout type is read from the checkout page's content, not from the theme - a theme does not choose the checkout.

= 1.4.1 =
* New: an address correction too small to stop a quote for is now offered on the orders screen instead of passing silently. The row reads "Address: standardised version available (ZIP+4 added, suite folded into line 1)" with a one-click Use standardised, which quotes again on that address and saves it to the order, so the order and the label finally describe the same place. Nothing is required, and nothing blocks the quote.
* Fixed: 1.4.0 said those minor corrections were "reported in the badge", and they were not - the badge had no branch for that verdict, so the screen showed nothing and "checked and standardisable" looked identical to "never checked".
* Fixed: a suite the verifier renamed while folding it ("Unit A" comes back inside line 1 as "APT A") was not recognised as the same unit, so line 2 survived and the label printed the apartment twice. Line 2 is now cleared when its unit is already in line 1 whatever the designator, and a second line that is not a unit, or names a different one, is never touched.
* Docs: the checkout page has always offered every correction, including a bare ZIP+4. Materiality only ever decided whether the orders screen stops the quote, and the documentation now says so.
* Unchanged: nothing blocks a checkout, there is still no re-validation on submit, and an unreachable verifier still leaves the address exactly as entered.

= 1.4.0 =
* New: address suggestions on the checkout page, for classic checkout. When a US address can be matched to a postal record more precisely, the customer sees the address they entered beside the suggested one, with the change named in words ("ZIP+4 added", "street standardised"), and can accept it in one click. Accepting rewrites the fields, re-prices the shipping and can be undone. Advisory only: nothing here blocks a checkout, there is no re-validation on submit, and an unreachable verifier leaves the address exactly as entered. Off by default.
* New: a verifier setting - Automatic (EasyPost when a key is set, otherwise the label provider), EasyPost only, the active label provider only, or Off. The settings screen says which verifier and which key mode would actually answer, and warns when EasyPost's mode differs from the label provider's, because a customer should never be shown a correction produced by a test key.
* New: an address the checkout has already checked is remembered for 90 days, so repeat customers and repeat attempts do not pay for the same lookup twice.
* Improved: every address is now verified, whatever its postcode looks like. A ZIP+4 used to be treated as proof that the address had already been checked; it is only proof that nine digits are present. Customers type them, autofill supplies them from map data that is not CASS-certified, and a street edited after validation keeps the old route code.
* Improved: a correction only asks the operator to decide when ignoring it could send the parcel elsewhere - an invalid address, or a different ZIP, city, state, country or materially different street. A ZIP+4-only or formatting-only change does not stop the quote and the address is used as entered, so the gate no longer adds a click to every order a verifier standardises.
* Fixed: the destination country was hard-coded to US in the rate request, so an international order was quoted as a domestic one and then tripped an address check over an address that was valid for its own country. The country now comes from the order, and a destination outside the US and its territories is refused with a clear message rather than quoted as something it is not. Puerto Rico, Guam, the US Virgin Islands, American Samoa and the Northern Mariana Islands remain in scope.
* Privacy: the checkout suggestion sends only address fields to the verifier - never the customer's name, email or phone - and the site's privacy policy text now says so.

= 1.3.15 =
* Housekeeping, no functional change: the 1.3.12 entry below used a real customer address as its example, and a code comment described the same case with it. Both now use a fabricated address. Nothing else changed.

= 1.3.14 =
* Improved: text fields now match the buttons - 30px high with 6px corners, the same border colour and 12px type, on every screen of the plugin, including the settings pages whose fields had stayed square while the buttons beside them were rounded. Only text-entry fields are affected: checkboxes, radio buttons, hidden fields and the file picker stay exactly as WordPress renders them, and field widths are untouched, so .regular-text still sizes itself.

= 1.3.13 =
* Fixed: some buttons kept WordPress's square corners and their own height - "Save Boxes" beside a rounded "Add Box", "Got it" in the what's-new dialog, and every button on the PrintNode screen. The stylesheet styled `.button` by naming particular classes under a page wrapper, so the buttons WordPress renders for the plugin (`submit_button()`, which adds no class of ours) and the dialog printed outside any wrapper matched no rule at all. One screen-wide rule now covers every button on every screen, and a gate fails the build if a new button is covered by neither that rule nor one of its own.

= 1.3.12 =
* Fixed: a suite the verifier folded into line 1 was printed twice on the label. 1.3.5 taught the order to drop the now-redundant second line, but the rate payload - which is what the provider turns into a shipment, and what the label is bought from - kept it, so a UPS label read "123 EXAMPLE ST APT 4B / APT4B / SPRINGFIELD, IL 62701-1234" while the order looked correct. Both now take their address from one shared function, so a fold cannot be applied to one and missed by the other.

= 1.3.11 =
* Fixed: Get Rates and Buy Label sat a pixel or two out of line. Get Rates is the only one of those buttons with a border, and with the default content-box its 30px height plus 1px borders measured 32px against its neighbour's 30px - baseline alignment then turned the difference into a visible offset, and made the shorter button look a shade bigger. Both are border-box now and aligned by their middles, so they share a top and bottom edge exactly.

= 1.3.10 =
* Changed: every button in the plugin is 30px high with 6px corners - Get Rates, Buy Label, Print, Refund, Reship, Reset Shipping, the bulk Apply, the pagination Go, the address panel's "Use selected address", the flagged-orders review button and the what's-new dismissal. They were 28px with 8px corners, and the ones built on WordPress's own .button class were carrying core's 3px corners, so a single row could show three different shapes.
* Unchanged on purpose: the provider segments and the test/live chip stay pills, because they sit inside the segmented track from 1.3.6 and a pill there is the design. WordPress's own Save button on the settings screen is left to core - its class is shared with the rest of wp-admin.

= 1.3.9 =
* Fixed: the Bulk Actions dropdown now shares the same style as the other controls - 30px high, 6px corners, 12px type. It was the last one left on WordPress core's styling, for the same reason as the rest: a single-class rule loses to core's `.wp-core-ui select`.
* Changed: the Apply button beside it is 30px high with 6px corners too, so the toolbar reads as one row rather than a taller dropdown next to a shorter button.
* Fixed: the shared rule's full width would have stretched that dropdown across the toolbar; the bulk select keeps its own width (160-260px) with a more specific rule.

= 1.3.8 =
* Fixed: the Box, Signature, Weight and Insurance controls now share one style - 30px high, 6px corners, 12px type, one border colour. They looked like they came from different plugins because they did: every plugin rule for these controls was a single CSS class, and WordPress core styles them with selectors that are one class plus an element (`.wp-core-ui select`, `input[type="number"]`), so core's 3px/4px corners, 30px height and 14px type won. Only the Signature select, whose rule happened to be two classes, took the plugin's styling - which is why it was the only rounded one.
* Changed: those rules are now scoped to the page wrapper and qualified with the element, so they apply as intended and would keep applying if core's selectors tightened.

= 1.3.7 =
* Fixed: the Signature dropdown sat 9px above the Box dropdown beside it. The two share a row, the row's cells are vertically centred, and the Signature column is the taller of the two once its group replaces the placeholder and the insurance estimate appears - so centring pushed its content up. Both columns are top-aligned now, which fixes their heights out of the question.
* Fixed: the Signature select is 28px high with an 8px radius and 12px type, matching the Box select. It was 26px/6px/11px, so even with the labels level the two controls did not share a baseline.

= 1.3.6 =
* Changed: the orders screen header is two rows. The title gets the whole first line and the provider control and today's spend share the second, one at each end. In one row the 22px/700 total was the strongest thing on the page and the title - inherited from WordPress core at 23px/400 - was the weakest, so the number outranked the page it belonged to.
* Changed: the provider buttons are one segmented pill instead of three rectangles. Same surface, border and radius as the spend badge, active option filled, so the row has one shape language rather than three corner radii. The test/live chip is a pill at the right end of the same track.
* Fixed: the switch's status message no longer lengthens that pill when a switch is in progress or fails; it sits beside the control in the header row.
* Unchanged on purpose: the plugin still sets no font-family, so the header uses the WordPress admin font rather than the theme's. A theme font would have to be loaded into wp-admin as well, and beside WordPress's own controls it reads as a different product.

= 1.3.5 =
* Fixed: a suite or apartment the verifier folded into line 1 is no longer written twice. Accepting a correction that turns "123 Main St" + "Apt 5" into "123 Main St APT 5" wrote the new line 1 and kept the old line 2, so the order read "123 Main St APT 5, Apt 5" and the label would have printed it that way. A second line the new first line already contains is dropped, and the note says the suite moved into line 1. A second line the verifier simply does not mention is still kept - matching is on the words, so "Suite 5" is never treated as contained in "Suite 500".

= 1.3.4 =
* Fixed: ticking "also save this as the order's shipping address" now writes the verified address onto the order when the suggestion is accepted, not when a label is eventually bought. The old arrangement stored the choice and acted on it at purchase; in practice the order kept its old address while the quotes were priced from the corrected one, with no note and no change to explain it.
* Fixed: a correction no longer blanks address line 2 when the verifier returns the rest of the address without it, and a corrected country is now written as well.
* Changed: the address line above the quotes says whether the order itself was updated, so a quote priced from a corrected address is not mistaken for an order that was corrected with it.

= 1.3.3 =
* New: the Today badge also shows the average postage per label, once the day has more than one label. It sits after the count, quieter, separated by the pill's own hairline - the total and the count keep the emphasis. A day with a single label does not show it, because there the average is the total.
* New: two layouts for that badge, chosen with the shipping_desk_spend_layout filter. "stacked" (default) makes the total the dominant figure with the label count and average in a column beside it; "inline" keeps everything on one baseline. Both share one markup, so the choice costs nothing.
* Changed: in the stacked layout the two small figures are separated by a hairline, and the average now sits above the label count - the two money figures together, the count last. Flip it with the shipping_desk_spend_order filter.

= 1.3.2 =
* Changed: Print waits longer when the provider has not finished building the file yet - four reads, backing off 0.5s, 1s then 1.5s. EasyPost has been seen taking more than two seconds after a successful purchase.
* New: the label URL returned by the purchase is now stored on the order, and Print falls back to it when the provider reports no label yet. A bought label can be printed without waiting at all, even when EasyPost is slow.
* Fixed: a print could report "Not Found" - EasyPost's 404, which carries no detail - for a shipment bought seconds earlier that the API had not caught up with. A 404 now retries inside the same short window, and the URL recorded at purchase is used when the provider cannot be read.
* Changed: when a label is not ready yet, the browser now waits and retries by itself - two more attempts, 2.5s apart, with the button reading "Waiting for label...". EasyPost has been measured taking longer than the server is willing to block, so the second click was doing work the plugin should do.

= 1.3.1 =
* Changed: the origin address has its own Save button, separate from the API keys. Correcting an address no longer re-posts every credential, and a stray edit in a key field cannot be committed by a save that was about the address. Each form saves its own settings group, which is what WordPress actually saves.
* New: API key fields ask password managers to stay out, using the signals they actually honour (`autocomplete="new-password"` plus the LastPass, 1Password, Bitwarden and Dashlane opt-outs). If a key still changes without a keystroke behind it, the save asks for confirmation first. This was found the honest way: a manager filled a key while the origin address was being edited.
* Fixed: the Veeqo API key could not be saved, and any save of the API keys form cleared it. The field posted a different option name than the one registered, so the typed value was ignored and the stored key was set to null - the same failure as the legacy EasyPost key. The settings gate now asserts that every registered option has a field on the page.
* Fixed: clicking Print straight after buying an EasyPost label could report that the shipment has no label, even though the purchase had succeeded - EasyPost finishes building the PDF a moment later. The print request now reads the shipment up to three times, 0.8s apart, before concluding anything. The message no longer says to buy the label again: it exists, a second buy would be charged, and a retry is normally enough.

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
