Who can create an order?
A user can create an order.

How is the order price determined?
The order price is based on the product's full price.

When does an order become paid?
An order becomes paid when Stripe Checkout succeeds.

Who can request a refund?
An authorized user (an admin) can request a refund.

Can a payment be partially refunded?
Yes.

What happens if Stripe is unavailable?
The system should retry.

What happens if a webhook arrives twice?
Only one webhook should be accepted.

What is explicitly out of scope?
Subscriptions, multiple payment methods, and multiple currencies. 

That is one order, one successful payment, multiple partial refunds, one currency, no shopping inventory.