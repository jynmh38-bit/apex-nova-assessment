<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryApiTest extends TestCase
{
    use RefreshDatabase;

    private function token(): string
    {
        return User::factory()->create()->createToken('test')->plainTextToken;
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Keyboard', 'sku' => 'KEY-TEST', 'price' => '99.90', 'stock' => 10,
            'category_id' => Category::firstOrCreate(['name' => 'Electronics'])->id,
        ], $overrides);
    }

    public function test_product_routes_require_authentication_even_without_accept_header(): void
    {
        $this->get('/api/products')->assertUnauthorized();
        $this->postJson('/api/products', [])->assertUnauthorized();
        $this->getJson('/api/products/1')->assertUnauthorized();
        $this->patchJson('/api/products/1', [])->assertUnauthorized();
        $this->deleteJson('/api/products/1')->assertUnauthorized();
    }

    public function test_login_issues_a_real_token_and_logout_revokes_it(): void
    {
        User::factory()->create(['email' => 'engineer@example.com', 'password' => 'secret123']);
        $response = $this->postJson('/api/login', ['email' => 'engineer@example.com', 'password' => 'secret123'])
            ->assertOk()->assertJsonPath('token_type', 'Bearer')->assertJsonMissingPath('user.password');
        $token = $response->json('token');
        $this->withToken($token)->getJson('/api/user')->assertOk();
        $this->withToken($token)->postJson('/api/logout')->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/products')->assertUnauthorized();
    }

    public function test_incorrect_credentials_are_rejected_without_issuing_a_token(): void
    {
        User::factory()->create(['email' => 'engineer@example.com']);
        $this->postJson('/api/login', ['email' => 'engineer@example.com', 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_create_product_normalizes_sku_and_links_suppliers(): void
    {
        $supplier = Supplier::create(['name' => 'Supply Co', 'email' => 'sales@example.com']);
        $response = $this->withToken($this->token())->postJson('/api/products', $this->payload([
            'sku' => ' key-001 ', 'supplier_ids' => [$supplier->id],
        ]))->assertCreated()->assertJsonPath('data.sku', 'KEY-001')
            ->assertJsonPath('data.price', '99.90')->assertJsonPath('data.in_stock', true)
            ->assertJsonPath('data.suppliers.0.id', $supplier->id)->assertJsonPath('data.category.name', 'Electronics');
        $this->assertDatabaseHas('product_supplier', ['product_id' => $response->json('data.id'), 'supplier_id' => $supplier->id]);
    }

    public function test_invalid_product_does_not_write_any_rows(): void
    {
        $this->withToken($this->token())->postJson('/api/products', $this->payload([
            'price' => '-1', 'stock' => -1, 'category_id' => 999, 'supplier_ids' => [999],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['price', 'stock', 'category_id', 'supplier_ids.0']);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_supplier', 0);
    }

    public function test_duplicate_sku_is_rejected_after_normalization(): void
    {
        Product::create($this->payload(['sku' => 'KEY-001']));
        $this->withToken($this->token())->postJson('/api/products', $this->payload(['sku' => ' key-001 ']))
            ->assertUnprocessable()->assertJsonValidationErrors('sku');
    }

    public function test_list_combines_filters_and_preserves_pagination_query(): void
    {
        $other = Category::create(['name' => 'Other']);
        $match = Product::create($this->payload(['sku' => 'MATCH', 'price' => '50.00', 'stock' => 5]));
        Product::create($this->payload(['sku' => 'MATCH-2', 'price' => '60.00', 'stock' => 7]));
        Product::create($this->payload(['sku' => 'WRONG-CATEGORY', 'category_id' => $other->id, 'price' => '50.00', 'stock' => 5]));
        Product::create($this->payload(['sku' => 'TOO-EXPENSIVE', 'price' => '200.00', 'stock' => 5]));
        Product::create($this->payload(['sku' => 'NO-STOCK', 'price' => '50.00', 'stock' => 0]));
        $response = $this->withToken($this->token())->getJson('/api/products?category_id='.$match->category_id.'&min_price=40&max_price=100&min_stock=1&max_stock=10&per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id)
            ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2);
        $this->assertStringContainsString('min_price=40', $response->json('links.next'));
    }

    public function test_zero_stock_filter_and_invalid_ranges(): void
    {
        Product::create($this->payload(['stock' => 0]));
        Product::create($this->payload(['sku' => 'IN-STOCK', 'stock' => 1]));
        $this->withToken($this->token())->getJson('/api/products?max_stock=0')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.in_stock', false);
        $this->getJson('/api/products?min_price=100&max_price=1&min_stock=5&max_stock=0&per_page=101')
            ->assertUnprocessable()->assertJsonValidationErrors(['max_price', 'max_stock', 'per_page']);
    }

    public function test_show_product_and_unknown_id(): void
    {
        $product = Product::create($this->payload());
        $this->withToken($this->token())->getJson('/api/products/'.$product->id)->assertOk()->assertJsonPath('data.id', $product->id);
        $this->getJson('/api/products/999')->assertNotFound();
    }

    public function test_partial_update_preserves_suppliers_when_omitted_and_can_clear_them(): void
    {
        $product = Product::create($this->payload());
        $supplier = Supplier::create(['name' => 'Supplier', 'email' => 'supplier@example.com']);
        $replacement = Supplier::create(['name' => 'Replacement', 'email' => 'replacement@example.com']);
        $product->suppliers()->attach($supplier);
        $this->withToken($this->token())->patchJson('/api/products/'.$product->id, ['name' => 'Updated', 'sku' => $product->sku])
            ->assertOk()->assertJsonPath('data.name', 'Updated')->assertJsonCount(1, 'data.suppliers');
        $this->patchJson('/api/products/'.$product->id, ['supplier_ids' => [$replacement->id]])
            ->assertOk()->assertJsonPath('data.suppliers.0.id', $replacement->id);
        $this->assertDatabaseMissing('product_supplier', ['product_id' => $product->id, 'supplier_id' => $supplier->id]);
        $this->patchJson('/api/products/'.$product->id, ['supplier_ids' => []])->assertOk()->assertJsonCount(0, 'data.suppliers');
    }

    public function test_invalid_supplier_update_keeps_original_product(): void
    {
        $product = Product::create($this->payload());
        $this->withToken($this->token())->patchJson('/api/products/'.$product->id, ['name' => 'Changed', 'supplier_ids' => [999]])
            ->assertUnprocessable();
        $this->assertSame('Keyboard', $product->fresh()->name);
    }

    public function test_delete_is_soft_and_hides_product_from_reads_and_updates(): void
    {
        $product = Product::create($this->payload());
        $this->withToken($this->token())->deleteJson('/api/products/'.$product->id)->assertNoContent();
        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->getJson('/api/products/'.$product->id)->assertNotFound();
        $this->patchJson('/api/products/'.$product->id, ['name' => 'Changed'])->assertNotFound();
        $this->getJson('/api/products')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_login_rate_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => 'missing@example.com', 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/api/login', ['email' => 'missing@example.com', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_reference_lists_are_authenticated_and_paginated(): void
    {
        Category::create(['name' => 'Electronics']);
        Supplier::create(['name' => 'Supplier', 'email' => 'supplier@example.com']);
        $this->getJson('/api/categories')->assertUnauthorized();
        $this->withToken($this->token())->getJson('/api/categories')->assertOk()->assertJsonPath('data.0.name', 'Electronics');
        $this->getJson('/api/suppliers')->assertOk()->assertJsonPath('meta.total', 1);
    }
}
