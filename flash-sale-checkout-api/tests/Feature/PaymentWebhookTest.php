<?php

namespace Tests\Feature;

use App\Models\Hold;
use App\Models\Order;
use App\Models\Product;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Carbon\Carbon;

class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function createProduct(array $attrs = [])
    {
        return Product::create(array_merge([
            'name' => 'Product',
            'price' => 100,
            'stock' => 10,
        ], $attrs));
    }

    protected function createHold(array $attrs = [])
    {
        return Hold::create(array_merge([
            'product_id' => null,
            'qty' => 1,
            'expires_at' => Carbon::now()->addMinutes(10),
            'released' => false,
            'used' => false,
        ], $attrs));
    }

    public function test_webhook_then_order_out_of_order_success_applies_payment()
    {
        $product = $this->createProduct(['stock' => 10, 'price' => 25]);
        $hold = $this->createHold(['product_id' => $product->id, 'qty' => 2]);

        // webhook arrives BEFORE order created
        $resp = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'k-out-1',
            'status' => 'success',
            'hold_id' => $hold->id,
            'amount' => 50,
        ]);
        $resp->assertStatus(200)->assertJson(['status' => 'recorded']);

        // now client creates order from hold
        $orderResp = $this->postJson('/api/orders', ['hold_id' => $hold->id]);
        $orderResp->assertStatus(201)->assertJsonPath('status', 'paid');

        $this->assertDatabaseHas('payments', [
            'idempotency_key' => 'k-out-1',
            'applied' => true,
        ]);

        $this->assertDatabaseHas('orders', [
            'hold_id' => $hold->id,
            'status' => 'paid',
        ]);

        // product stock decreased by 2
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 8]);
    }

    public function test_duplicate_webhook_is_idempotent()
    {
        $product = $this->createProduct(['stock' => 10, 'price' => 10]);
        $hold = $this->createHold(['product_id' => $product->id, 'qty' => 1]);

        // create order first
        $orderResp = $this->postJson('/api/orders', ['hold_id' => $hold->id]);
        $orderResp->assertStatus(201);

        // send webhook twice with same key
        $first = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'k-dup-1',
            'status' => 'success',
            'order_id' => $orderResp->json('id'),
            'amount' => 10,
        ]);
        $first->assertStatus(200)->assertJson(['status' => 'paid']);

        $second = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'k-dup-1',
            'status' => 'success',
            'order_id' => $orderResp->json('id'),
            'amount' => 10,
        ]);
        $second->assertStatus(200)->assertJson(['status' => 'paid']);

        // payment recorded only once
        $this->assertEquals(1, Payment::where('idempotency_key', 'k-dup-1')->count());
        $this->assertDatabaseHas('orders', ['id' => $orderResp->json('id'), 'status' => 'paid']);
    }

    public function test_webhook_failure_releases_hold_and_prevents_order()
    {
        $product = $this->createProduct(['stock' => 3, 'price' => 20]);
        $hold = $this->createHold(['product_id' => $product->id, 'qty' => 1]);

        // failure notification arrives first
        $this->postJson('/api/payments/webhook', [
            'idempotency_key' => 'k-fail-1',
            'status' => 'failure',
            'hold_id' => $hold->id,
        ])->assertStatus(200);

        // hold should be released, order creation must fail
        $this->assertDatabaseHas('holds', ['id' => $hold->id, 'released' => true, 'used' => false]);

        $this->postJson('/api/orders', ['hold_id' => $hold->id])->assertStatus(422);
    }
}
