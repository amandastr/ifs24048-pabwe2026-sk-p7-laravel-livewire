<?php

namespace Database\Seeders;

use App\Features\Stock\Enums\StockStatus;
use App\Features\Stock\Models\Stock;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Administrator',
                'username' => 'admin',
                'password' => Hash::make('admin'),
                'role' => 'admin',
            ]
        );

        $member = User::firstOrCreate(
            ['email' => 'member@example.com'],
            [
                'name' => 'Member User',
                'username' => 'member',
                'password' => Hash::make('member'),
                'role' => 'member',
            ]
        );

        $items = [
            ['name' => 'Kertas HVS A4 80gsm', 'sku' => 'ATK-001', 'description' => 'Kertas HVS **A4** untuk kebutuhan cetak harian kantor.', 'quantity' => 120, 'unit' => 'rim', 'price' => 52000, 'min_stock' => 20],
            ['name' => 'Spidol Whiteboard Hitam', 'sku' => 'ATK-002', 'description' => 'Spidol whiteboard tinta hitam, stok mulai menipis.', 'quantity' => 4, 'unit' => 'pcs', 'price' => 9500, 'min_stock' => 10],
            ['name' => 'Toner Printer Hitam', 'sku' => 'ATK-003', 'description' => 'Toner cadangan untuk printer laser ruang administrasi.', 'quantity' => 0, 'unit' => 'pcs', 'price' => 385000, 'min_stock' => 2],
        ];

        foreach ($items as $item) {
            Stock::firstOrCreate(
                ['user_id' => $member->id, 'sku' => $item['sku']],
                $item + [
                    'status' => StockStatus::fromQuantity($item['quantity'], $item['min_stock'])->value,
                ]
            );
        }
    }
}
