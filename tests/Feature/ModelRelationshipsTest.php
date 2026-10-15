<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    private function createOrder(array $attributes = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'ORD-'.uniqid(),
            'price' => 2500,
            'currency' => 'gbp',
            'cart_id' => 'cart_123',
            'line_items' => [['sku' => 'ABC', 'qty' => 1, 'unit_price' => 2500]],
        ], $attributes));
    }

    private function createPayment(Order $order, array $attributes = []): Payment
    {
        return $order->payments()->create(array_merge([
            'stripe_session_id' => 'cs_test_'.uniqid(),
            'amount' => $order->price,
            'currency' => $order->currency,
        ], $attributes));
    }

    public function test_order_has_many_payment_attempts()
    {
        $order = $this->createOrder();
        $expired = $this->createPayment($order, ['status' => 'expired']);
        $retry = $this->createPayment($order);

        $this->assertCount(2, $order->payments);
        $this->assertTrue($order->payments->contains($expired));
        $this->assertTrue($order->payments->contains($retry));
    }

    public function test_order_without_payments_returns_empty_collection()
    {
        $order = $this->createOrder();

        $this->assertTrue($order->payments->isEmpty());
    }

    public function test_payment_belongs_to_order()
    {
        $order = $this->createOrder();
        $payment = $this->createPayment($order);

        $this->assertInstanceOf(Order::class, $payment->order);
        $this->assertTrue($payment->order->is($order));
    }

    public function test_payment_attempts_cannot_share_a_stripe_session_id()
    {
        $order = $this->createOrder();
        $this->createPayment($order, ['stripe_session_id' => 'cs_test_dup']);

        $this->expectException(QueryException::class);

        $this->createPayment($order, ['stripe_session_id' => 'cs_test_dup']);
    }

    public function test_order_with_payments_cannot_be_deleted()
    {
        $order = $this->createOrder();
        $this->createPayment($order);

        $this->expectException(QueryException::class);

        $order->delete();
    }

    public function test_order_paid_payment_returns_the_winning_payment()
    {
        $order = $this->createOrder();
        $this->createPayment($order, ['status' => 'expired']);
        $winner = $this->createPayment($order, ['status' => 'succeeded']);

        $order->update(['paid_payment_id' => $winner->id, 'status' => 'paid']);

        $this->assertTrue($order->paidPayment->is($winner));
    }

    public function test_order_without_paid_payment_returns_null()
    {
        $order = $this->createOrder();
        $this->createPayment($order);

        $this->assertNull($order->paidPayment);
    }

    public function test_paid_payment_must_belong_to_the_same_order()
    {
        $order = $this->createOrder();
        $otherOrder = $this->createOrder();
        $otherPayment = $this->createPayment($otherOrder, ['status' => 'succeeded']);

        $this->expectException(QueryException::class);

        $order->update(['paid_payment_id' => $otherPayment->id]);
    }

    public function test_payment_has_many_refunds_and_refund_belongs_to_payment()
    {
        $payment = $this->createPayment($this->createOrder(), ['status' => 'succeeded']);
        $refund = $this->createRefund($payment);

        $this->assertCount(1, $payment->refunds);
        $this->assertTrue($payment->refunds->first()->is($refund));
        $this->assertTrue($refund->payment->is($payment));
    }

    public function test_refunds_cannot_share_an_idempotency_key()
    {
        $payment = $this->createPayment($this->createOrder(), ['status' => 'succeeded']);
        $this->createRefund($payment);

        $this->expectException(QueryException::class);

        $this->createRefund($payment);
    }

    public function test_payment_with_refunds_cannot_be_deleted()
    {
        $payment = $this->createPayment($this->createOrder(), ['status' => 'succeeded']);
        $this->createRefund($payment);

        $this->expectException(QueryException::class);

        $payment->delete();
    }

    private function createRefund(Payment $payment, array $attributes = []): Refund
    {
        return $payment->refunds()->create(array_merge([
            'idempotency_key' => 'auto:'.$payment->id,
            'reason' => 'duplicate_payment',
            'amount' => $payment->amount,
            'currency' => $payment->currency,
        ], $attributes));
    }
}
