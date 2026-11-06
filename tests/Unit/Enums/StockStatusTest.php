<?php

namespace Tests\Unit\Enums;

use App\Features\Stock\Enums\StockStatus;
use PHPUnit\Framework\TestCase;

class StockStatusTest extends TestCase
{
    public function test_stock_status_labels(): void
    {
        $this->assertSame('Tersedia', StockStatus::AVAILABLE->label());
        $this->assertSame('Stok Menipis', StockStatus::LOW_STOCK->label());
        $this->assertSame('Stok Habis', StockStatus::OUT_OF_STOCK->label());
    }

    public function test_stock_status_badge_classes(): void
    {
        $this->assertStringContainsString('emerald', StockStatus::AVAILABLE->badgeClass());
        $this->assertStringContainsString('amber', StockStatus::LOW_STOCK->badgeClass());
        $this->assertStringContainsString('rose', StockStatus::OUT_OF_STOCK->badgeClass());
    }

    public function test_from_quantity_resolves_status_against_min_stock(): void
    {
        $this->assertSame(StockStatus::OUT_OF_STOCK, StockStatus::fromQuantity(0, 5));
        $this->assertSame(StockStatus::LOW_STOCK, StockStatus::fromQuantity(1, 5));
        $this->assertSame(StockStatus::LOW_STOCK, StockStatus::fromQuantity(5, 5));
        $this->assertSame(StockStatus::AVAILABLE, StockStatus::fromQuantity(6, 5));
    }

    public function test_zero_quantity_is_out_of_stock_even_when_min_stock_is_zero(): void
    {
        $this->assertSame(StockStatus::OUT_OF_STOCK, StockStatus::fromQuantity(0, 0));
        $this->assertSame(StockStatus::AVAILABLE, StockStatus::fromQuantity(1, 0));
    }
}
