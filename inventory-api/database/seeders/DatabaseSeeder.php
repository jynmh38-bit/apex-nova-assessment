<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(['email' => 'interviewer@example.com'], ['name' => 'Demo Engineer', 'password' => 'DemoPassword123!']);
        $electronics = Category::firstOrCreate(['name' => 'Electronics']);
        $office = Category::firstOrCreate(['name' => 'Office']);
        $supplier = Supplier::firstOrCreate(['email' => 'sales@example.com'], ['name' => 'Demo Supply Co']);
        foreach ([['USB-C Keyboard', 'KEY-001', '89.90', 25, $electronics->id], ['Wireless Mouse', 'MOU-001', '49.50', 5, $electronics->id], ['Notebook', 'NOTE-001', '8.00', 0, $office->id]] as [$name, $sku, $price, $stock, $category]) {
            $product = Product::firstOrCreate(['sku' => $sku], ['name' => $name, 'price' => $price, 'stock' => $stock, 'category_id' => $category]);
            $product->suppliers()->syncWithoutDetaching([$supplier->id]);
        }
    }
}
