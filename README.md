# whmcs-stripe-partial

A WHMCS merchant gateway that charges saved cards through Stripe with [partial authorization](https://docs.stripe.com/payments/partial-authorization) turned on. When the issuer approves only part of the amount (for example, a prepaid or debit card with a low balance), the module captures the approved part, records it on the invoice, and leaves the rest due.

## How it works

1. Cards are saved through Stripe Elements and a SetupIntent (`usage=off_session`). Card data never touches WHMCS. The pay method's remote token is `{"customer":"cus_…","method":"pm_…"}`.
2. When WHMCS charges the card (on the cron or through "Attempt Capture"), the module creates a PaymentIntent with:
   - `capture_method=manual`
   - `off_session=true`, `confirm=true`
   - `payment_method_options[card][request_partial_authorization]=if_available`
3. Once the PaymentIntent reaches `requires_capture`, the module compares `amount_capturable` with the amount requested:
   - **Full approval:** it captures, then returns `success` and WHMCS marks the invoice paid.
   - **Partial approval:** it captures `amount_capturable`, applies that amount with `addInvoicePayment`, and returns `pending`. The invoice stays Unpaid with the remaining balance, so the next capture attempt or the client can pay the rest.
   - **Partial approval below `Minimum Partial %`, or with `Accept Partial Authorizations` off:** it cancels the PaymentIntent, which releases the hold, and returns `declined`.
4. Refunds go through `/v1/refunds` against the PaymentIntent ID, which is stored as the transaction ID.

## Install

Copy `modules/` into the WHMCS root, so you get:

```
modules/gateways/stripepartial.php
modules/gateways/stripepartial/lib.php
modules/gateways/callback/stripepartial.php
```

Then go to **Setup → Payments → Payment Gateways → All Payment Gateways**, activate **Stripe (Partial Authorization)**, and enter the publishable and secret keys. Assign it to products or clients as needed.

## Notes

- Partial authorization depends on the card network and issuer, and Stripe may need to enable it on your account. Ineligible cards are authorized in full or declined as usual.
- WHMCS's built-in Stripe module is ionCube-encoded and can't be edited. See "Moving existing Stripe cards" below.
- An uncaptured authorization would expire after about 7 days, but every authorization is captured or cancelled in the same request, so none are left open.
- Tested with PHP lint only. Run it against Stripe test mode before going live.

## Moving existing Stripe cards

The built-in Stripe module stores each saved card's token in the same format this gateway uses, so existing cards can be moved over without clients entering them again. Both gateways must use the **same Stripe account keys**.

`migrate-stripe-paymethods.php` moves the following from `stripe` to `stripepartial`: saved pay methods, client default gateways, services, domains, and Unpaid/Payment Pending invoices.

```
php migrate-stripe-paymethods.php --whmcs=/var/www/whmcs                 # dry run, shows counts
php migrate-stripe-paymethods.php --whmcs=/var/www/whmcs --clients=12    # dry run for one client
php migrate-stripe-paymethods.php --whmcs=/var/www/whmcs --clients=12 --apply
php migrate-stripe-paymethods.php --whmcs=/var/www/whmcs --apply         # everyone
php migrate-stripe-paymethods.php --whmcs=/var/www/whmcs --revert --apply
php migrate-stripe-paymethods.php --whmcs=/var/www/whmcs --from=stripe_dynamic   # cards saved under another Stripe module
```

Back up the database first. Try one test client, run "Attempt Capture" on one of their invoices, and confirm the charge appears in Stripe before you migrate everyone. Keep the built-in Stripe gateway active, because past transactions still reference it for refunds.

### Without SSH: admin addon

Upload `modules/addons/stripepartialmigrate/stripepartialmigrate.php` to the same path in WHMCS. Activate **Stripe Card Migration** under **System Settings → Addon Modules** and give your admin role access. Then open **Addons → Stripe Card Migration**. Enter client IDs, click Preview to see the counts, then click Apply. Choose the reverse direction to undo. Every run is written to the Activity Log.

## Webhook

Records refunds and chargebacks made outside WHMCS (in the Stripe dashboard, or by the card issuer).

1. In Stripe, go to **Developers → Webhooks → Add endpoint** and enter `https://YOUR-WHMCS/modules/gateways/callback/stripepartial_webhook.php`.
2. Select these events: `charge.refunded`, `charge.refund.updated`, `charge.dispute.created`, `charge.dispute.updated`, `charge.dispute.closed`, `charge.dispute.funds_withdrawn`, `charge.dispute.funds_reinstated`, `payment_intent.succeeded`, `payment_intent.payment_failed`.
3. Copy the endpoint's signing secret (`whsec_…`) into **Webhook Signing Secret** in the gateway settings.

What it does:
- **Refunds:** each refund is recorded as a transaction on the invoice. An invoice refunded in full is marked Refunded. A refund already recorded by WHMCS isn't added again.
- **Chargebacks:** when funds are withdrawn, it records the amount and sets the invoice to Collections. If the dispute is won and funds are reinstated, it records them back and restores the invoice status. Opened and closed disputes are written to the Activity Log.
- **Failed payments:** written to the Activity Log.
- **Successful payments:** ignored, because the gateway already records them when it charges.

Requests with an invalid signature, or more than 5 minutes old, are rejected.

## One-time payments

Clients can pay an invoice by card from the client area. The card form uses Stripe Elements with `confirmCardPayment`, which handles 3D Secure automatically. The card is saved for future automatic charges unless the client unticks "Save this card".

- When WHMCS shows the gateway's card form for an invoice, it uses the payment form instead of the plain save-card form.
- A standalone payment page is also available at `https://YOUR-WHMCS/modules/gateways/callback/stripepartial_pay.php?id=INVOICEID`. The client must be logged in and own the invoice. You can link to it from invoice emails or templates.
- The payment is recorded when the browser returns. The `payment_intent.succeeded` webhook also records it, in case the browser is closed before then. Each PaymentIntent is recorded only once.
