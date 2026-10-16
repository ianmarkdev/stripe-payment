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

Response (201 for a new session, 200 for an existing open one):

```json
{
  "payment_id": "uuid",
  "checkout_url": "https://checkout.stripe.com/...",
  "status": "pending",
  "expires_at": "2026-10-16T12:30:00Z"
}
```

Every response field is persisted on the payment row, so returning an existing attempt needs no Stripe call (except the stale case in section 4):

| Field | Source |
|---|---|
| `payment_id` | `payments.id` |
| `checkout_url` | `payments.checkout_url`, saved from the Stripe session in Tx 2 (set once) |
| `status` | `payments.status` |
| `expires_at` | `payments.expires_at`, saved from the Stripe session in Tx 2 (set once) |

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
- Stored as `payments.idempotency_key`, **globally unique** (schema change needed).
- Same key, same order: return the stored attempt instead of creating another.
- Same key, different order: the lookup finds the key on another order → 422 `idempotency_key_reused`.
- Same key while the first request is still running: the unique constraint lets only one row win; the second request sees it `creating` and gets 409 `request_in_progress` (retryable).
- Because an order has at most one active attempt (section 4), a request with a different key also gets the open session back. The key mainly protects simultaneous duplicates and identifies which attempt a retry belongs to.

## 4. Multiple attempts
Decided by the order's latest attempt:

| Latest attempt | Action |
|---|---|
| None, or all `expired` / `failed` / `abandoned` | Create a new session (201) |
| `creating` | 409 `request_in_progress` (retryable) |
| `pending`, before `expires_at` | Return the persisted `checkout_url` (200) |
| `pending`, past `expires_at` | Retrieve the session from Stripe first: `open` → return it; `expired` → mark expired, create new; `complete` → apply it like the webhook would, then answer from the new status |
| `processing` | 409 `payment_processing` (retryable). The session is complete and a delayed payment is settling, so there is no URL to return |
| `needs_review` | 409 `payment_needs_review` (not retryable until a human resolves it) |
| `succeeded` | 409 `order_already_paid` |

`pending` (session open, customer hasn't paid) and `processing` (session complete, delayed payment method still settling) are different situations: only `pending` has a usable `checkout_url`.

Sessions are created with `expires_at` = 30 minutes.

## 5. Stripe and database failures
Write locally before calling Stripe:

1. **Tx 1:** insert the payment row as `creating` (no session ID yet).
2. Create the session with idempotency key `checkout-{payment_id}`, `metadata.payment_id` and `client_reference_id` = order ID.
3. **Tx 2:** save the session details (see section 6).

**Webhook before Tx 2.** A webhook can find a `creating` payment via `metadata.payment_id`. The webhook worker locks the order, then the payment, attaches `stripe_session_id` (set once), and applies the event directly: `creating → processing / succeeded / expired`. Both sides account for the other winning:
- Tx 2 moves status only with `WHERE status = 'creating'`. If the webhook already moved it on, Tx 2 changes no status; it only fills `checkout_url` / `expires_at` if they are still null, and the response reflects the current status.
- The webhook never moves a payment back to `creating` or `pending`, and only attaches a session ID that is null or identical.

**Timeout (unknown outcome).** The row stays `creating`; return 503. Not proof that Stripe failed.

**Reconciliation of stale `creating` rows.** Stripe can prune idempotency keys once they are 24 hours old, so a late retry could create a *new* session instead of returning the original. The job therefore never relies on old keys:
- **Younger than 1 hour:** retry the create with the same key (well inside the retention window). Success → `pending` as in Tx 2.
- **Older than 1 hour:** don't retry. List Checkout Sessions created around the payment's `created_at` and match `metadata.payment_id`. Found → attach it and apply its current status. Stripe confirms none exists → `abandoned`.
- **Outcome can't be established** (Stripe lookups keep failing, or the result is ambiguous) → `needs_review`. A human checks Stripe and resolves it. The job never blindly creates another session for it.

## 6. Concurrency
The order row lock serialises checkout requests, cancellation and webhook handling. Stripe is never called inside a transaction.

```
Tx 1 (short):  lock order → check eligibility + no active attempt → insert payment `creating` → commit
No tx:         call Stripe
Tx 2 (short):  lock order → save session ID, URL, expires_at (set once)
               → status creating → pending (conditional) → recheck order status → commit
```

A concurrent request blocks on the lock, then sees the `creating` row and gets 409 `request_in_progress`. The `creating` row is the reservation. Calling Stripe outside the transaction avoids holding the lock during a slow network call, and avoids a rollback leaving a Stripe session with no local row.

**Cancellation during the Stripe call.** The order lock isn't held while Stripe is called, so an order can be cancelled in between. Tx 2 rechecks the order after locking it:
- Still `awaiting_payment` → normal response (201).
- `cancelled` → save the session details anyway (so it can be tracked), commit, then call Stripe to expire the session, and respond 409 `order_cancelled`. The `checkout_url` is never returned.
- If the expire call fails, a reconciliation rule retries it: any `pending` payment on a `cancelled` order is expired.
- If the session completes anyway, the payment succeeds on a cancelled order and gets a refund (Invariant E).

The cancel action can't expire a `creating` attempt itself (it has no session ID yet); Tx 2's recheck covers that case.

## 7. HTTP responses
All errors use one shape (including validation errors):

```json
{ "error": { "code": "order_already_paid", "message": "This order has already been paid.", "retryable": false } }
```

| Situation | Status | `code` | Retryable |
|---|---|---|---|
| New session created | 201 | — | — |
| Existing open session returned | 200 | — | — |
| Missing or malformed idempotency key | 422 | `validation_failed` | no |
| Key reused for a different order | 422 | `idempotency_key_reused` | no |
| Not logged in | 401 | `unauthenticated` | no |
| Not your order / not found | 404 | `order_not_found` | no |
| Order paid / cancelled (including cancelled during the Stripe call) | 409 | `order_already_paid` / `order_cancelled` | no |
| Request in progress / payment processing | 409 | `request_in_progress` / `payment_processing` | yes |
| Attempt needs manual review | 409 | `payment_needs_review` | no |
| Stripe error or timeout | 503 + `Retry-After` | `payment_provider_unavailable` | yes (same key) |
| Unexpected error | 500 | `internal_error` | no |

## Schema changes required
Not yet in the migrations:
- `orders.user_id` (FK to users).
- `payments.idempotency_key` (unique), `payments.checkout_url` (nullable, set once), `payments.expires_at` (nullable, set once).
- `payments.stripe_session_id` becomes nullable (set once).
- Payment statuses gain `creating`, `processing`, `abandoned` and `needs_review` (see data-model.md Q6).
