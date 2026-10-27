# Stripe Payment

A Laravel app for taking one-off payments with Stripe Checkout. The focus is on handling money correctly: payments must stay correct when webhooks are duplicated, requests are retried, calls time out, or the app crashes partway through.

**Status:** work in progress. The design docs, data model and migrations are done. The checkout endpoint, webhook handling and refund worker are not built yet, and the Stripe SDK isn't installed.

## Scope

- One order, one successful payment, any number of partial refunds.
- A single currency (GBP).
- Order prices come from the stored order, never from the client.
- An order is marked paid only when a verified Stripe webhook confirms it. The Checkout success redirect never marks anything paid.
- Out of scope: subscriptions, multiple payment methods, multiple currencies, inventory, and guest checkout.

## Design docs

Read these before changing payment code:

| Doc | Covers |
|---|---|
| [docs/requirements.md](docs/requirements.md) | What the system must do and what is out of scope |
| [docs/data-model.md](docs/data-model.md) | Tables, constraints, which fields can't change, and the status transitions for orders, payments, refunds and webhook events |
| [docs/invariants.md](docs/invariants.md) | The guarantees the system keeps (refund limit, deduplication, payment truth, recovery) and how each one is enforced |
| [docs/checkout-api.md](docs/checkout-api.md) | The `POST /api/orders/{order}/checkout` contract: idempotency, concurrency, Stripe failures and error responses |

## Core models

- `Order`: what the customer is buying. Its price is fixed when the order is created. `paid_payment_id` points at the payment that paid for it.
- `Payment`: one row per Stripe Checkout Session attempt. An order can have several attempts.
- `Refund`: a full or partial refund of one payment. The row is written before Stripe is called.
- `WebhookEvent`: an inbox of Stripe events, deduplicated by `stripe_event_id` and processed by a queued job.

## Stack

Laravel 13 (PHP 8.3+), Inertia + React + TypeScript, and Fortify for authentication. Development uses SQLite, and the queue uses the `database` driver.

## Getting started

```bash
composer setup   # install dependencies, create .env, generate the app key, run migrations, build assets
composer dev     # start the dev server
composer test    # run Pint, PHPStan and the test suite
```
