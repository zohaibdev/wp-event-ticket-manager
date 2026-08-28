# Event Ticket Manager

A standalone WordPress plugin for selling event tickets through a shortcode. Multiple ticket types per event, no ACF, no WooCommerce, no single-event pages, no archives — and no redirect away from your site to pay.

The installable plugin lives in [`event-ticket-manager/`](event-ticket-manager/).

## Quick start

1. Copy `event-ticket-manager/` into `wp-content/plugins/` and activate it.
2. **Event Tickets → Settings → Stripe**: enable Stripe, paste your keys, register the webhook URL shown on that tab.
3. **Event Tickets → Settings → Customizer**: set your brand colours (optional).
4. **Event Tickets → Events → Add New**: create the event and add its ticket types (name, price, optional limit), then publish.
5. Paste the event's shortcode into any page: `[event_ticket event_id="123"]`.

## Architecture

Every service is a class with a single responsibility, wired together by `ETM_Plugin` and reachable via `etm()->service( 'name' )`.

| File | Responsibility |
| --- | --- |
| [`includes/class-plugin.php`](event-ticket-manager/includes/class-plugin.php) | Container, activation/deactivation, migrations |
| [`includes/class-database.php`](event-ticket-manager/includes/class-database.php) | Table names and the dbDelta schema |
| [`includes/class-events.php`](event-ticket-manager/includes/class-events.php) | Event CRUD, validation, duplication, meta |
| [`includes/class-event-tickets.php`](event-ticket-manager/includes/class-event-tickets.php) | Ticket types per event: CRUD, per-type limits, selection validation, attendee slots |
| [`includes/class-fees.php`](event-ticket-manager/includes/class-fees.php) | Global and per-event fees, fixed or percentage |
| [`includes/class-coupons.php`](event-ticket-manager/includes/class-coupons.php) | Coupon CRUD, server-side validation and discount calculation |
| [`includes/class-email-templates.php`](event-ticket-manager/includes/class-email-templates.php) | Editable email subjects/bodies and `{{variable}}` substitution |
| [`includes/class-pending-orders.php`](event-ticket-manager/includes/class-pending-orders.php) | Unpaid checkouts: holds seats without writing a booking |
| [`includes/class-capacity.php`](event-ticket-manager/includes/class-capacity.php) | Seat maths and the `FOR UPDATE` reservation lock |
| [`includes/class-bookings.php`](event-ticket-manager/includes/class-bookings.php) | Booking CRUD, status transitions, booking numbers, hold expiry |
| [`includes/class-attendees.php`](event-ticket-manager/includes/class-attendees.php) | Attendee rows per booking |
| [`includes/class-stripe.php`](event-ticket-manager/includes/class-stripe.php) | Stripe REST client, key management, PaymentIntents, signature verification |
| [`includes/class-checkout.php`](event-ticket-manager/includes/class-checkout.php) | Frontend AJAX: validate → reserve → PaymentIntent → verify |
| [`includes/class-webhooks.php`](event-ticket-manager/includes/class-webhooks.php) | REST webhook endpoint, idempotency, the shared paid transition |
| [`includes/class-emails.php`](event-ticket-manager/includes/class-emails.php) | Customer and admin notifications |
| [`includes/class-custom-fields.php`](event-ticket-manager/includes/class-custom-fields.php) | Internal field system: storage, rendering, validation |
| [`includes/class-settings.php`](event-ticket-manager/includes/class-settings.php) | Option groups, defaults, sanitisation, branding CSS |
| [`includes/class-shortcodes.php`](event-ticket-manager/includes/class-shortcodes.php) | `[event_ticket]` rendering and asset loading |

### Ticket types

An event owns any number of ticket types (`wp_et_event_tickets`). Nothing is hardcoded — the admin names them. A purchase is a set of `{ticket_type_id: quantity}` pairs; the server looks every ID up against the event, refuses unknown, foreign or disabled types, applies the per-type limit and the event-wide participant limit, then prices the order from the ticket rows.

Attendee forms follow from the selection. Lines are ordered by the admin's display order and flattened into slots:

```
Adult x2 + Child x2  ->  slot 0  Adult 1   (the purchaser - "Your Details")
                         slot 1  Adult 2
                         slot 2  Child 1
                         slot 3  Child 2
```

Each attendee row stores `ticket_type_id` plus a `ticket_title_snapshot`, and each booking stores its lines in `wp_et_booking_tickets` with unit price and subtotal snapshotted. Renaming or deleting a ticket type later never rewrites what a past customer bought.

### Sharing a Room

A shared room is a ticket variation, not an add-on. It is priced, limited and
selected by exactly the same machinery as any other type; the only thing that
sets it apart is a `variation_type` of `sharing_room` on its row, which the
server reads from the database and never from the request.

What differs is who it asks about. A standard variation produces one attendee
form per ticket sold. A shared room produces none: the room holds the purchaser,
who is already named in Your Details, plus one other person. So the checkout
asks for that one roommate once, and the quantity never multiplies the question:

```
Adult x2                 ->  Your Details (Adult 1) + one Adult 2 form
Adult x2 + Sharing x1    ->  the same, plus one Roommate name/email
Adult x2 + Sharing x2    ->  the same, plus one Roommate name/email
Sharing x3               ->  Your Details, plus one Roommate name/email
```

The group lives in the markup once and is shown or hidden, never cloned, so
there is no path by which a quantity could produce a second one. While hidden
its inputs are disabled, which keeps them out of validation and out of the
submitted data.

Bookings store the pair in the existing `roommate_name` / `roommate_email`
columns plus a `sharing_room` meta marker, with `roommate_price` left at zero:
the room was charged as the ticket line it is. Bookings taken through the
add-on the plugin used to sell keep their own quantity and price and still
display them; nothing historical is rewritten. On upgrade, every event that had
the add-on switched on is given a matching Sharing a Room ticket type carrying
its old title, price, label and maximum, so it keeps selling what it was selling.

### Pricing

One function prices every order, and it is the only place money is decided:

```
ETM_Checkout::calculate_totals( $event, $selection, $coupon )

  ticket_subtotal   sum of (ticket type price x quantity), read from the DB,
                    Sharing a Room included: it is a variation like any other
= subtotal          <- percentage fees AND percentage discounts both use this
+ fees_total        ETM_Fees::calculate()   fixed + percentage, each applied once
- discount_total    ETM_Coupons::discount_for()   capped at the subtotal
= total             never below zero
```

`ETM_Checkout::requires_payment()` then decides whether Stripe is involved at all: an RSVP event never charges, and a total of zero completes directly. The browser gets these figures from `etm_quote_order` for display only; the identical calculation runs again before a PaymentIntent is created.

### Purchase flow

Payment happens in the modal. The browser holds a client secret; the secret key never leaves the server, and no browser claim is trusted.

```
Browser                          WordPress                        Stripe
   |  pick quantities per type       |                               |
   |  step 1: purchaser + attendees  |                               |
   |  step 2: review                 |                               |
   |  "Continue to Payment" -------->|                               |
   |                            validate fields                      |
   |                            recalc prices from DB                |
   |                            LOCK event row (FOR UPDATE)          |
   |                            check capacity, upsert pending       |
   |                            COMMIT                               |
   |                                 |--- create PaymentIntent ----->|
   |<-- client_secret + pk ----------|                               |
   |  step 3: Payment Element mounts in the modal                    |
   |  confirmPayment() -- card + any 3DS challenge, in place ------->|
   |  "paid" (unverified) <------------------------------------------|
   |  confirm -------------------->  |                               |
   |                                 |--- retrieve intent ---------->|
   |                            verify status + amount               |
   |                            re-check capacity                    |
   |                            mark paid (conditional UPDATE)       |
   |                            booking number + both emails         |
   |<-- verified order summary ------|                               |
   |  step 4: Order Complete                                         |
   |                                 |<-- webhook (same path) -------|
```

The webhook runs the identical `ETM_Webhooks::confirm_paid_intent()` path, so a customer who closes the tab mid-payment still gets their booking confirmed and emailed.

## Security notes

- **Prices** are recalculated from the ticket type rows in `ETM_Event_Tickets::validate_selection()`. The browser sends only ticket IDs and quantities; titles, prices and totals all come from the database.
- **Capacity** is enforced inside a transaction that locks the event row, so concurrent buyers cannot oversell. Requires InnoDB (WordPress default).
- **Keys**: only the publishable key is ever sent to the browser, and only in the AJAX response that also carries the client secret.
- **Payment state** changes only after the PaymentIntent is retrieved with the secret key — by the webhook, or by the confirmation endpoint. `stripe.confirmPayment()` returning success is treated as a hint, not proof.
- **Idempotency**: each Stripe event ID is claimed with `add_option()`, which is atomic thanks to the unique index on `option_name`. `ETM_Bookings::mark_paid()` uses a conditional `UPDATE ... WHERE payment_status <> 'paid'`, so of two concurrent deliveries only one assigns the booking number and sends email.
- **No booking until paid**: an unpaid checkout lives in `wp_et_pending_orders`, which holds its seats and carries the validated order. The booking row is written only once a PaymentIntent is verified, and `stripe_payment_intent_id` carries a unique index so a concurrent webhook and browser confirmation cannot both insert.
- **No duplicate bookings**: the browser is handed a per-order token; retries and edits update that same hold and its PaymentIntent instead of creating new ones.
- **Expired holds** release their seats *and* cancel the unpaid intent, so a stale payment cannot succeed against a released place.
- Every admin action checks `manage_options` plus a nonce; every query uses `$wpdb->prepare()`; every template escapes on output.

## Branding

Six colours under **Settings → Customizer** are stored as an option and emitted as CSS custom properties on `.etm-block` and `.etm-modal`:

`--etm-primary`, `--etm-secondary`, `--etm-accent-color`, `--etm-text`, `--etm-surface`, `--etm-on-primary`, plus derived `--etm-primary-soft` and `--etm-primary-ring` tints.

The primary, text and background colours are also passed to Stripe Elements so the card form matches the rest of the modal.

## Extending

Templates in `frontend/templates/` can be overridden from a theme at `your-theme/event-ticket-manager/`.

| Hook | Purpose |
| --- | --- |
| `etm_booking_paid` | Fires once when a booking is confirmed — the place to add PDF tickets, QR codes or CRM syncs |
| `etm_stripe_payment_intent_params` | Adjust the PaymentIntent before creation |
| `etm_max_quantity_cap` | Change the per-ticket-type cap (default 20) |
| `etm_migrated_ticket_title` | Rename the ticket type created for pre-existing events |
| `etm_pending_hold_minutes` | Change how long pending bookings hold seats |
| `etm_cancel_expired_intents` | Keep expired intents open instead of cancelling them |
| `etm_custom_fields` | Filter the fields rendered for a group |
| `etm_customer_email` / `etm_admin_email` | Rewrite either notification before sending |
| `etm_event_is_purchasable` | Add your own sales window rules |

## Tests

`scratchpad/run-tests.php` exercises the money-, capacity- and signature-critical logic against WordPress stubs:

```bash
php run-tests.php
```

213 assertions across two suites. `run-tests.php` (136) covers Stripe amount conversion, webhook signature verification (tampering, replay, rotation), multi-type pricing, per-type and event-wide limits, attendee slot mapping across ticket types, seat maths, event and ticket validation, custom fields, booking numbers, branding, and settings. `render-test.php` (77) renders the real templates and asserts the ticket rows, dynamic attendee labels, review breakdown, payment step and both emails.

## Not included in v1

Event archives, single event pages, QR/PDF tickets, check-in, WooCommerce, coupons, a refund UI (dashboard refunds are reflected automatically), waitlists and seat selection are deliberately out of scope. The schema and hooks leave room for them.
