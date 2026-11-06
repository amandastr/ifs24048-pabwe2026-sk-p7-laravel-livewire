<?php

namespace Tests\Unit\DTO;

use App\Features\Ai\DTO\GeneratedStockItem;
use PHPUnit\Framework\TestCase;

class GeneratedStockItemTest extends TestCase
{
    public function test_dto_properties_and_to_array(): void
    {
        $item = new GeneratedStockItem(
            name: 'Kertas HVS A4',
            sku: 'ATK-001',
            description: 'Kertas 80gsm',
            quantity: 40,
            unit: 'rim',
            price: 52000.0,
            minStock: 10,
        );

        $this->assertSame('Kertas HVS A4', $item->name);
        $this->assertSame([
            'name' => 'Kertas HVS A4',
            'sku' => 'ATK-001',
            'description' => 'Kertas 80gsm',
            'quantity' => 40,
            'unit' => 'rim',
            'price' => 52000.0,
            'min_stock' => 10,
        ], $item->toArray());
    }

    public function test_dto_defaults(): void
    {
        $item = new GeneratedStockItem(name: 'Spidol');

        $this->assertNull($item->sku);
        $this->assertNull($item->description);
        $this->assertSame(0, $item->quantity);
        $this->assertSame('pcs', $item->unit);
        $this->assertNull($item->price);
        $this->assertSame(5, $item->minStock);
    }
}
