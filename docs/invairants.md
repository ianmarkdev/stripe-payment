Invariant A: Refund limit

The sum of successful refund plus amounts reserved for pending or uncertain refunds cannot exceed the originally caputred amount


Invariant B: Duplicate requests

Retrying the same logical refunds request should be rejected.

Invariant C: Payment truth
Payment confirmation should be comes from the stripe as truth of the source.

Invariant D: Event deduplication
Processing same webhook events doesn't affect the local states repeatedly.