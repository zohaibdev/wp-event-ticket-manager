=== Event Ticket Manager ===
Contributors: webnday
Tags: events, tickets, stripe, bookings, attendees
Author URI: https://webnday.com
Plugin URI: https://webnday.com
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.1
Stable tag: 1.5.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell event tickets from any page with a shortcode. In-page Stripe payments, attendee collection, roommate add-ons and booking management.

== Description ==

Event Ticket Manager is a standalone ticketing plugin. It does not require Advanced Custom Fields, WooCommerce, or any other plugin, and it never creates single event pages or event archives. You place a shortcode wherever you want tickets to be sold.

**What it does**

* Create and manage events with dates, participant limits and statuses.
* Give each event as many ticket types as it needs - Adult, Child, VIP, Early Bird, whatever you name them - each with its own price and optional per-type limit.
* Let customers mix ticket types in one order; the total updates as they go and every attendee form is generated for the right type.
* Sell tickets through a responsive ticket block: `[event_ticket event_id="123"]`.
* Collect details for every attendee in the order through a four step checkout modal.
* Take card payments without leaving the page: the Stripe Payment Element is rendered inside the modal, including 3D Secure challenges.
* Offer an optional roommate add-on, shown right under the event title, which can optionally count toward the participant limit.
* Ask the purchaser for their details once. They become attendee 1, so a single ticket never shows two forms for the same person.
* Show a full Order Complete summary in the modal, with the booking number and the verified Stripe transaction ID.
* Match your brand with a colour customizer that drives CSS variables across the block, modal, steps and buttons.
* Send a customer confirmation and an admin notification when a payment is confirmed.
* Manage bookings, attendees and payment details in the WordPress admin.
* Add your own fields to the customer, attendee, roommate and event forms.

**Security model**

* Prices and totals are always recalculated server-side from the event record. Values posted by the browser are ignored.
* Availability is checked server-side, inside a database transaction that locks the event row, so two simultaneous buyers cannot oversell the last place.
* Only the publishable key reaches the browser. The PaymentIntent is created server-side and the browser receives nothing but its client secret.
* A booking becomes `paid` only after the PaymentIntent has been verified with the secret key, either by the webhook or by a server-side lookup. The browser reporting success is never enough on its own.
* Webhook events are recorded and processed once, and the paid transition is a conditional UPDATE, so retries and replays cannot double-book or double-email.
* All queries use `$wpdb->prepare()`, all admin actions check capabilities and nonces, and all output is escaped.
* Stripe secret keys are stored as options, are never printed back into the settings form, and are never exposed to the frontend.

== Installation ==

1. Upload the `event-ticket-manager` folder to `/wp-content/plugins/`.
2. Activate the plugin. Database tables and default settings are created on activation.
3. Go to **Event Tickets > Settings > Stripe**, enable Stripe and paste your API keys.
4. In your Stripe dashboard, add a webhook endpoint pointing at the URL shown on that settings tab and subscribe it to `payment_intent.succeeded`, `payment_intent.payment_failed`, `payment_intent.canceled` and `charge.refunded`. Paste the signing secret back into the settings.
5. Create an event under **Event Tickets > Events** and publish it.
6. Copy the event shortcode and paste it into any page or post.

== Frequently Asked Questions ==

= Does this need WooCommerce or ACF? =

No. The plugin is standalone and includes its own lightweight custom field system.

= Do customers leave my site to pay? =

No. The Stripe Payment Element is embedded in the checkout modal, and card authentication (3D Secure) happens in an overlay on the same page. When the payment clears, the modal moves to an Order Complete step showing the booking number, the full order summary and the Stripe transaction ID.

= Can one event sell different kinds of ticket? =

Yes. Under Event Tickets > Events, the Tickets section is a repeater: add a row per ticket type, name it whatever you like, set its price, and optionally cap how many of that type can be sold. Customers get a quantity selector per type and can buy any mix. Every attendee is stored against the ticket type they hold.

= What happened to my existing events? =

On upgrade, each existing event gets a single ticket type called "General Admission" carrying its old price, so nothing changes for buyers. Past bookings keep their own snapshot of what was purchased and are never recalculated.

= Can I change the colours? =

Yes, under Event Tickets > Settings > Customizer. The six colours are published as CSS variables on the ticket block and modal, and the primary, text and background colours are passed to Stripe so the card form matches too.

= What happens if a customer abandons the checkout? =

The pending booking holds its places for the window you configure (30 minutes by default), then an hourly cleanup task releases them and voids the unpaid Stripe intent.

= Does deactivating the plugin delete my bookings? =

No. Deactivation and uninstall both keep your data unless you explicitly tick the delete option under Settings > General before deleting the plugin.

= Can I override the templates? =

Yes. Copy any file from `frontend/templates/` into `your-theme/event-ticket-manager/` and edit it there.

== Requirements ==

* PHP 8.1 or newer.
* MySQL with InnoDB tables, which WordPress uses by default. The overbooking protection relies on transactions and row locking.
* A Stripe account.
* An HTTPS site. Stripe.js refuses to collect card details over plain HTTP outside localhost.

== Changelog ==

= 1.5.1 =
* Email templates now ship with default copy instead of four empty editors. Clearing a body still falls back to the built-in layout, and Reset restores the default copy.
* Added {{roommate_block}} and {{payment_summary}} block variables that omit themselves when a booking has no roommate, no fees or no coupon.
* {{ticket_summary}} and {{attendee_list}} now render as HTML lists so they survive email clients.

= 1.5.0 =
* Events can now be a Ticket Event or an RSVP Event. RSVP events register attendees without ever loading Stripe.
* RSVP notifications can go to their own address: per event, or globally under Settings > Email, falling back to the ticket admin address.
* Added Settings > Email Templates: edit the subject and body of the four notification emails, with dynamic {{variables}} and a reset button. Leaving a body empty keeps the built-in layout, so existing sites see no change.
* Orders that come to zero - a free ticket, or a coupon covering the whole amount - now complete directly instead of failing. No PaymentIntent is ever created for a zero amount.
* Added coupons: percentage or fixed, with usage limits, per-customer limits, date windows and per-event restriction. Customers apply and remove them on the review step.
* Added fees: fixed or percentage, global or per event, calculated from the ticket subtotal plus roommate add-on.
* Checkout, bookings, the admin booking screen and both emails now show subtotal, fees, discount and final total. Bookings snapshot the pricing so editing a coupon or fee later never rewrites history.

= 1.4.1 =
* Fixed the event description never appearing on the ticket block. It now shows directly under the event title.
* Added an Event Instructions field to the event editor, shown at the bottom of the ticket block under the Get Tickets button. Stored in existing event meta, so no database change was needed.

= 1.4.0 =
* Every ticket variation now has its own Minimum Quantity and Maximum Quantity, set per variation in the event editor. No variation is required because of its position any more.
* Added a Price Label per variation - the text shown beside the price, such as "per person" or "per group". Blank falls back to "each".
* The old per-type ticket Limit has become that variation's Maximum Quantity. Existing values are carried over automatically. Note this is now a per-order maximum rather than total stock for the type; review any values you had set.

= 1.3.3 =
* Fixed the booking screen's Payment Information box showing a $0.00 ticket price on orders with more than one ticket type. It now lists each type with its own quantity, unit price and subtotal.
* Fixed the Events list Ticket Price column showing $0.00 for events priced through ticket types. It now lists each type and its price.

= 1.3.2 =
* Fixed a payment error caused by telling Stripe never to collect a phone number without supplying one. Only name and email are opted out now, and both are always passed when confirming.

= 1.3.1 =
* The first enabled ticket type is now the base ticket: its quantity starts at 1 and cannot be reduced to 0, on the frontend and on the server. All other types stay optional and start at 0. Reordering ticket types moves the requirement with them.

= 1.3.0 =
* Events can now offer any number of named ticket types, each with its own price and optional limit.
* The ticket block shows a row and quantity selector per type, with live per-line and combined totals.
* The checkout modal generates one attendee form per ticket sold, labelled with its ticket type; the purchaser remains the first attendee and is never asked twice.
* Bookings, the admin booking screen and both emails show a full ticket-type breakdown.
* Attendees and booking lines snapshot their ticket title, so renaming or deleting a type never rewrites history.
* Existing single-price events are migrated to one "General Admission" ticket type automatically.
* Bookings are no longer written until a payment is verified. Unpaid checkouts hold their seats in a separate short-lived table, so the Bookings screen only ever contains real orders.
* Added a unique index on the Stripe payment reference, making duplicate paid bookings impossible at the database level.

= 1.1.0 =
* Payment now happens inside the checkout modal with the Stripe Payment Element. The hosted Checkout redirect has been removed.
* Added an Order Complete step with the full order summary and the verified Stripe transaction ID.
* The purchaser is collected once and stored as attendee 1, so a single ticket no longer shows duplicate forms.
* Rebuilt the step indicator with icons, active/completed states and a progress bar.
* Moved the roommate add-on directly under the event title and gave it a light, high-contrast design.
* Added a branding customizer (Settings > Customizer) that drives CSS variables across the frontend and the Stripe form.
* Rebuilt the review step for proper text contrast.
* Expired holds now cancel their unpaid PaymentIntent.
* Refunds made in the Stripe dashboard are reflected on the booking.

= 1.0.0 =
* Initial release.
