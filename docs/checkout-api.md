# Checkout API Contract

Constraints this contract preserves:
- Never trust client-supplied prices.
- Never mark an order paid from the Checkout success redirect.
- Don't call Stripe while holding a database transaction open.
- Use a stable Stripe idempotency key for the logical session-creation operation, not a newly generated key on every retry.
- Repeated and concurrent API requests cannot create multiple valid payment attempts.
- A timeout is an uncertain outcome, not proof that Stripe failed.

## Endpoint
`POST /api/orders/{order}/checkout` creates (or returns) the Stripe Checkout Session for an existing order. It never marks the order paid; only verified webhook processing does that.

Request:

```json
{ "idempotency_key": "client-generated-uuid" }
```

The client sends nothing else. Amount, currency and line items come from the stored order; `success_url` and `cancel_url` come from server config (no open redirect).

Response (201 for a new session, 200 for an existing active one):

```json
{
  "payment_id": "uuid",
  "checkout_url": "https://checkout.stripe.com/...",
  "status": "pending",
  "expires_at": "2026-10-16T12:30:00Z"
}
```

## 1. Authentication and authorization
- Checkout requires an authenticated customer. Guest checkout is out of scope for v1.
- An order belongs to the customer who created it (`orders.user_id`, schema change needed). A policy checks ownership.
- Another customer's order returns 404, so the endpoint doesn't reveal that the order exists.

## 2. Order eligibility
Enforced in the application service, while the order row is locked (not only in the controller):
- Only `awaiting_payment` orders can start checkout. `paid` → 409 `order_already_paid`; `cancelled` → 409 `order_cancelled`.
- Sanity checks: amount > 0 and ≤ the application maximum, currency is supported, order has line items. A failure here is a server-side data problem and returns 500 `internal_error`.

## 3. Idempotency
- The client generates one UUID per logical "Pay" action and resends the same key on every retry of it. A new action gets a new key.
- Stored as `payments.idempotency_key`, unique on `(order_id, idempotency_key)` (schema change needed).
- Same key, same order: return the stored attempt instead of creating another.
- Same key, different order: 422 `idempotency_key_reused`.
- Same key while the first request is still running: the unique constraint lets only one row win; the second request sees it `creating` and gets 409 `request_in_progress` (retryable).
- Because an order has at most one active attempt (section 4), a request with a different key also gets the active session back. The key mainly protects simultaneous duplicates and identifies which attempt a retry belongs to.

## 4. Multiple attempts
Decided by the order's latest attempt:

| Latest attempt | Action |
|---|---|
| None, or all `expired` / `failed` | Create a new session (201) |
| `creating` | 409 `request_in_progress` (retryable) |
| `pending`, before `expires_at` | Return its existing `checkout_url` (200) |
| `pending`, past `expires_at` | Retrieve the session from Stripe first: `open` → return it; `expired` → mark expired, create new; `complete` → 409 `payment_processing` (webhook will catch up) |
| `succeeded` | 409 `order_already_paid` |

Sessions are created with `expires_at` = 30 minutes.

## 5. Stripe and database failures
Write locally before calling Stripe:

1. Insert the payment row as `creating` (no session ID yet).
2. Create the session with idempotency key `checkout-{payment_id}`, `metadata.payment_id` and `client_reference_id` = order ID.
3. Save `stripe_session_id` + URL, move to `pending`.

- **Crash after Stripe created the session:** the row stays `creating`. Webhooks still match it via `metadata.payment_id`.
- **Timeout (unknown outcome):** the row stays `creating`; return 503. A retry reuses the same Stripe key, so it returns the original session if one was created.
- **Reconciliation job:** retries `creating` rows older than a few minutes with the same key. Rows still `creating` after 24 hours are marked `failed`. Any orphan session has expired by then, and a paid one was already matched by its webhook.

Schema impact: `payments.stripe_session_id` becomes nullable (set once); payment gains a `creating` status; webhook handling attaches the session ID to a `creating` payment before applying the event.

## 6. Concurrency
The order row lock serialises checkout requests. Stripe is never called inside a transaction.

```
Tx 1 (short):  lock order → check eligibility + no active attempt → insert payment `creating` → commit
No tx:         call Stripe
Tx 2 (short):  save session ID + URL, status → pending
```

A concurrent request blocks on the lock, then sees the `creating` row and gets 409 `request_in_progress`. The `creating` row is the reservation. Calling Stripe outside the transaction avoids holding the lock during a slow network call, and avoids a rollback leaving a Stripe session with no local row.

## 7. HTTP responses
All errors use one shape (including validation errors):

```json
{ "error": { "code": "order_already_paid", "message": "This order has already been paid.", "retryable": false } }
```

| Situation | Status | `code` | Retryable |
|---|---|---|---|
| New session created | 201 | — | — |
| Existing active session returned | 200 | — | — |
| Missing or malformed idempotency key | 422 | `validation_failed` | no |
| Key reused for a different order | 422 | `idempotency_key_reused` | no |
| Not logged in | 401 | `unauthenticated` | no |
| Not your order / not found | 404 | `order_not_found` | no |
| Order paid / cancelled | 409 | `order_already_paid` / `order_cancelled` | no |
| Request in progress / payment processing | 409 | `request_in_progress` / `payment_processing` | yes |
| Stripe error or timeout | 503 + `Retry-After` | `payment_provider_unavailable` | yes (same key) |
| Unexpected error | 500 | `internal_error` | no |
