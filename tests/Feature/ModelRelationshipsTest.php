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
        return $order->payment()->create(array_merge([
            'stripe_session_id' => 'cs_test_'.uniqid(),
            'amount' => $order->price,
        ], $attributes));
    }

    public function test_order_has_one_payment()
    {
        $order = $this->createOrder();
        $payment = $this->createPayment($order);

        $this->assertInstanceOf(Payment::class, $order->payment);
        $this->assertTrue($order->payment->is($payment));
    }

    public function test_order_without_payment_returns_null()
    {
        $order = $this->createOrder();

        $this->assertNull($order->payment);
    }

    public function test_payment_belongs_to_order()
    {
        $order = $this->createOrder();
        $payment = $this->createPayment($order);

        $this->assertInstanceOf(Order::class, $payment->order);
        $this->assertTrue($payment->order->is($order));
    }

    public function test_order_cannot_have_a_second_payment()
    {
        $order = $this->createOrder();
        $this->createPayment($order);

        $this->expectException(QueryException::class);

        $this->createPayment($order);
    }

    public function test_deleting_order_cascades_to_payment()
    {
        $order = $this->createOrder();
        $payment = $this->createPayment($order);

        $order->delete();

        $this->assertModelMissing($payment);
    }
}
