Invariant A: Refund limit

The sum of successful refunds plus amounts reserved for pending or uncertain refunds cannot exceed the originally captured amount.

Enforced by (planned, needs the refunds table):
- Every refund is a row in a `refunds` table, created in `pending` status before Stripe is called. Pending and uncertain refunds therefore count against the limit from the moment they are requested.
- Creating a refund locks the payment row, sums its non-failed refunds, and rejects the new refund if that sum plus the new amount exceeds `payments.amount`.


Invariant B: Duplicate requests

An order may have at most one successful payment that remains unrefunded as its valid purchase payment.
Retrying the same logical refund request should be rejected.

Enforced by:
- `orders.paid_payment_id` (nullable, unique) points at the order's single valid purchase payment. It is set once and never replaced.
- A payment success is applied in one transaction that locks the order row first (see data-model.md Q6, "Applying a payment success"). Only the first successful attempt on an `awaiting_payment` order becomes `paid_payment_id`. Any other success (a second attempt, or a success on a `cancelled` order) still records the payment as `succeeded` but never fulfils the order again; it goes to the duplicate-payment refund path.
- Before a new Checkout Session is created, the previous open session is expired via the Stripe API, and the new session is only created once that call confirms the old one did not complete. This makes double success rare; the order lock is what guarantees the invariant.
- Refund idempotency (planned, needs the refunds table): at most one duplicate-payment refund row per payment (unique constraint), and the Stripe call uses `idempotency_key = refund-{refund_id}`. Stripe only keeps idempotency keys for about 24 hours, so the unique row is the real guard; duplicate webhooks find the existing row and do not start a second refund.

Invariant C: Payment truth

Payment confirmation comes from Stripe as the source of truth.

Enforced by:
- Only the webhook processing job, handling a signature-verified Stripe event, can move a payment to `succeeded`. The Checkout success redirect page never marks anything paid; it only shows the current status.
- On success, Stripe's `amount_total` and `currency` must match the payment row's `amount` and `currency`. A mismatch is flagged and never fulfils the order.

Invariant D: Event deduplication

Processing the same webhook event more than once doesn't change local state more than once.

Enforced by:
- `webhook_events.stripe_event_id` is unique, so the same Stripe event is stored once. A duplicate delivery returns 200 without reprocessing.
- The event and its processing job are committed in the same transaction, and 200 is returned only after commit. A scheduled recovery job re-dispatches `received` events that are due, so a lost job cannot strand an event.
- Because an event may be dispatched more than once, the job locks the event row and exits if the status is already final. Every status change is a conditional update (`WHERE status = <expected>`), so replaying an event cannot move a final status again.
- Temporary failures stay `received` with `attempts`, `last_error` and `next_attempt_at`. Only exhausted retries become `failed`, and only a manual replay moves them back.
