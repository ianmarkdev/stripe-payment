Invariant A: Refund limit

The sum of successful refunds plus amounts reserved for pending or uncertain refunds cannot exceed the originally captured amount.

Enforced by:
- Every refund is a row in the `refunds` table, created in `pending` status before Stripe is called. Pending and submitted (uncertain) refunds therefore count against the limit from the moment they are recorded.
- Creating a refund locks the payment row, sums its `pending`, `submitted` and `succeeded` refunds, and rejects the new refund if that sum plus the new amount exceeds `payments.amount`. Only a `failed` refund releases its reservation.


Invariant B: Duplicate requests

An order may have at most one successful payment that remains unrefunded as its valid purchase payment.
Retrying the same logical refund request should be rejected.

Enforced by:
- `orders.paid_payment_id` points at the order's single winning payment. It is set once and never replaced, and a composite FK ensures it is a payment of the same order.
- A payment success is applied in one transaction that locks the order row first (see data-model.md Q6, "Applying a payment success"). Only the first successful attempt on an `awaiting_payment` order becomes `paid_payment_id`. Any other success (a second attempt, or a success on a `cancelled` order) is still recorded truthfully as `succeeded`, never fulfils the order, and gets a refund (Invariant E).
- What the order lock guarantees: one winning payment and at most one fulfilment. What it does not guarantee: that Stripe collects only once. It only coordinates our own database; it cannot lock Stripe. Extra successful payments are an expected case, handled by Invariant E.
- Before a new Checkout Session is created, the previous open session is expired via the Stripe API. This makes double success rarer, but correctness never depends on it: a session can still complete while the expiry call or its webhook is in flight.
- Every refund has a unique `idempotency_key`: `auto:{payment_id}` for automatic refunds (so a payment gets at most one), or the client's `Idempotency-Key` for requested refunds (so retrying the same request finds the existing row instead of creating a second refund).

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

Invariant E: Successful payment recovery

A payment that succeeds but cannot fulfil its order (another payment already won, or the order is cancelled) is always refunded exactly once, even if the application crashes at any point.

Enforced by:
- Durable refund intent: the `pending` refund row is inserted in the same transaction that marks the payment `succeeded` and decides it lost (data-model.md Q6, step 4). The payment success and the intent to refund it are committed together or not at all. Stripe is called only after commit.
- Duplicate webhooks: replaying the success event finds the payment already `succeeded` and stops; even if it didn't, `idempotency_key = auto:{payment_id}` rejects a second refund row.
- Refund worker: a scheduled job picks up `pending` refunds whose `next_attempt_at` is null or past, locks the refund row, and calls Stripe with `idempotency_key = refund-{refund_id}` and metadata `refund_id`. On acceptance it stores `stripe_refund_id` (set once) and moves to `submitted` or `succeeded`. On a temporary error it records `attempts + 1`, `last_error` and a backoff `next_attempt_at`, and stays `pending`.
- Final status: `refund.updated` / `charge.refunded` webhooks move `submitted` refunds to `succeeded` or `failed`, looked up by `stripe_refund_id`. These go through the same webhook inbox (Invariant D), so they are deduplicated and retried like any other event.
- Crash recovery, by where the crash happens:
  - Before the success transaction commits: nothing is stored; the webhook job retries from the inbox.
  - After commit, before Stripe is called: the `pending` refund row is still there; the worker picks it up.
  - After Stripe accepted the refund, before we stored the result: the worker retries. Within 24 hours the same Stripe idempotency key returns the original refund. After that, the worker first lists the charge's refunds and matches metadata `refund_id`, and only creates a new refund if none exists.
- Exhausted retries: a refund only becomes `failed` after retries run out and a Stripe lookup confirms no refund exists for it. `failed` refunds are surfaced for a human, never silently dropped.
