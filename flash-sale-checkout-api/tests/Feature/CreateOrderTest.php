<?php

namespace Tests\Feature;

use App\Models\Hold;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Carbon\Carbon;


class CreateOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function createProduct(array $overrides = [])
    {
        return Product::create(array_merge([
            'name' => 'Test Product',
            'price' => 100,
            'stock' => 10,
        ], $overrides));
    }

    protected function createHold(array $overrides = [])
    {
        return Hold::create(array_merge([
            'product_id' => null,
            'qty' => 1,
            'expires_at' => Carbon::now()->addMinutes(10),
            'released' => false,
            'used' => false,
        ], $overrides));
    }

    public function test_creates_order_from_valid_hold()
    {
        $product = $this->createProduct(['price' => 100, 'stock' => 10]);

        $hold = $this->createHold([
            'product_id' => $product->id,
            'qty' => 1,
            'expires_at' => Carbon::now()->addMinutes(10),
            'released' => false,
            'used' => false,
        ]);

        $this->postJson('/api/orders', ['hold_id' => $hold->id])
             ->assertStatus(201)
             ->assertJsonPath('hold_id', $hold->id)
             ->assertJsonPath('status', 'pre_payment');

        $this->assertDatabaseHas('orders', ['hold_id' => $hold->id]);
        $this->assertDatabaseHas('holds', ['id' => $hold->id, 'used' => true]);
    }

    public function test_fails_on_expired_hold()
    {
        $product = $this->createProduct(['price' => 100, 'stock' => 10]);

        $hold = $this->createHold([
            'product_id' => $product->id,
            'qty' => 1,
            'expires_at' => Carbon::now()->subMinute(),
            'released' => false,
            'used' => false,
        ]);

        $this->postJson('/api/orders', ['hold_id' => $hold->id])
             ->assertStatus(422);

        $this->assertDatabaseMissing('orders', ['hold_id' => $hold->id]);
    }

    public function test_cannot_use_hold_twice()
    {
        $product = $this->createProduct(['price' => 100, 'stock' => 10]);

        $hold = $this->createHold([
            'product_id' => $product->id,
            'qty' => 1,
            'expires_at' => Carbon::now()->addMinutes(10),
            'released' => false,
            'used' => false,
        ]);

        $this->postJson('/api/orders', ['hold_id' => $hold->id])
             ->assertStatus(201);

        $this->postJson('/api/orders', ['hold_id' => $hold->id])
             ->assertStatus(422);
    }
}
