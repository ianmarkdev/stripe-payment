<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
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
            'cart_id' => 'cart_123',
            'line_items' => [['sku' => 'ABC', 'qty' => 1, 'unit_price' => 2500]],
        ], $attributes));
    }

    private function createPayment(Order $order, array $attributes = []): Payment
    {
        return $order->payments()->create(array_merge([
            'stripe_session_id' => 'cs_test_'.uniqid(),
            'amount' => $order->price,
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

    public function test_deleting_order_cascades_to_payments()
    {
        $order = $this->createOrder();
        $first = $this->createPayment($order, ['status' => 'expired']);
        $second = $this->createPayment($order);

        $order->delete();

        $this->assertModelMissing($first);
        $this->assertModelMissing($second);
    }
}
