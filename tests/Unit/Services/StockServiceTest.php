<?php

namespace Tests\Unit\Services;

use App\Features\Stock\Contracts\StockRepositoryInterface;
use App\Features\Stock\Enums\StockStatus;
use App\Features\Stock\Models\Stock;
use App\Features\Stock\Repositories\StockRepository;
use App\Features\Stock\Services\StockService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    protected StockService $service;
    protected StockRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new StockRepository();
        $this->service = new StockService($this->repository);
    }

    public function test_get_stocks_for_user(): void
    {
        $user = User::factory()->create();
        Stock::factory()->count(2)->create(['user_id' => $user->id]);

        $paginator = $this->service->getStocksForUser($user->id);
        $this->assertCount(2, $paginator);
    }

    public function test_create_stock_derives_status_from_quantity(): void
    {
        $user = User::factory()->create();

        $stock = $this->service->createStock($user->id, [
            'name' => 'Service Stock',
            'sku' => 'SRV-001',
            'description' => 'Desc',
            'quantity' => 20,
            'unit' => 'box',
            'price' => 15000,
            'min_stock' => 5,
            'status' => 'out_of_stock', // ignored: status always follows quantity
        ]);

        $this->assertInstanceOf(Stock::class, $stock);
        $this->assertEquals('Service Stock', $stock->name);
        $this->assertEquals('SRV-001', $stock->sku);
        $this->assertSame(20, $stock->quantity);
        $this->assertEquals('box', $stock->unit);
        $this->assertEquals('available', $stock->status);

        $low = $this->service->createStock($user->id, ['name' => 'Low', 'quantity' => 3, 'min_stock' => 5]);
        $this->assertEquals('low_stock', $low->status);

        $empty = $this->service->createStock($user->id, ['name' => 'Empty']);
        $this->assertEquals('out_of_stock', $empty->status);
        $this->assertSame(0, $empty->quantity);
        $this->assertEquals('pcs', $empty->unit);
        $this->assertSame(5, $empty->min_stock);
        $this->assertNull($empty->sku);
        $this->assertNull($empty->price);
    }

    public function test_create_stock_never_stores_negative_quantity(): void
    {
        $user = User::factory()->create();

        $stock = $this->service->createStock($user->id, ['name' => 'Negatif', 'quantity' => -4, 'min_stock' => -1]);

        $this->assertSame(0, $stock->quantity);
        $this->assertSame(0, $stock->min_stock);
        $this->assertEquals('out_of_stock', $stock->status);
    }

    public function test_update_stock_overrides_only_given_fields_and_syncs_status(): void
    {
        $user = User::factory()->create();
        $stock = Stock::factory()->create([
            'user_id' => $user->id,
            'name' => 'Old Name',
            'sku' => 'OLD-1',
            'quantity' => 50,
            'min_stock' => 5,
            'status' => 'available',
        ]);

        $updated = $this->service->updateStock($stock, [
            'name' => 'New Name',
            'description' => 'Updated Desc',
            'quantity' => 2,
        ]);

        $this->assertTrue($updated);
        $stock->refresh();
        $this->assertEquals('New Name', $stock->name);
        $this->assertEquals('Updated Desc', $stock->description);
        $this->assertEquals('OLD-1', $stock->sku);
        $this->assertSame(2, $stock->quantity);
        $this->assertEquals('low_stock', $stock->status);

        $this->service->updateStock($stock, ['sku' => null, 'price' => 999, 'unit' => 'dus', 'min_stock' => 1]);
        $stock->refresh();
        $this->assertNull($stock->sku);
        $this->assertEquals('999.00', $stock->price);
        $this->assertEquals('dus', $stock->unit);
        $this->assertEquals('available', $stock->status);
    }

    public function test_resolve_status(): void
    {
        $this->assertEquals('out_of_stock', $this->service->resolveStatus(0, 5));
        $this->assertEquals('low_stock', $this->service->resolveStatus(5, 5));
        $this->assertEquals('available', $this->service->resolveStatus(6, 5));
    }

    public function test_delete_stock(): void
    {
        $user = User::factory()->create();
        $stock = Stock::factory()->create(['user_id' => $user->id]);

        $deleted = $this->service->deleteStock($stock);
        $this->assertTrue($deleted);
        $this->assertDatabaseMissing('stocks', ['id' => $stock->id]);
    }

    public function test_adjust_quantity_updates_quantity_and_status(): void
    {
        $user = User::factory()->create();
        $stock = Stock::factory()->create([
            'user_id' => $user->id,
            'quantity' => 7,
            'min_stock' => 5,
            'status' => StockStatus::AVAILABLE->value,
        ]);

        // 7 -> 6 is still above min stock
        $this->service->adjustQuantity($stock, -1);
        $stock->refresh();
        $this->assertSame(6, $stock->quantity);
        $this->assertEquals(StockStatus::AVAILABLE->value, $stock->status);

        // 6 -> 5 hits the min stock threshold
        $this->service->adjustQuantity($stock, -1);
        $stock->refresh();
        $this->assertSame(5, $stock->quantity);
        $this->assertEquals(StockStatus::LOW_STOCK->value, $stock->status);

        // Decreasing beyond zero is clamped to 0 and marked out of stock
        $this->service->adjustQuantity($stock, -50);
        $stock->refresh();
        $this->assertSame(0, $stock->quantity);
        $this->assertEquals(StockStatus::OUT_OF_STOCK->value, $stock->status);

        // Increasing restocks the item
        $this->service->adjustQuantity($stock, 20);
        $stock->refresh();
        $this->assertSame(20, $stock->quantity);
        $this->assertEquals(StockStatus::AVAILABLE->value, $stock->status);
    }

    public function test_cover_photo_management(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $user = User::factory()->create();
        $stock = Stock::factory()->create(['user_id' => $user->id]);

        $fakeFile = \Illuminate\Http\UploadedFile::fake()->image('cover.jpg');

        // Update cover
        $path = $this->service->updateCoverPhoto($stock, $fakeFile);
        $stock->refresh();
        $this->assertEquals($path, $stock->cover_image_path);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($path);

        // Update cover again (replaces old)
        $newFakeFile = \Illuminate\Http\UploadedFile::fake()->image('new-cover.jpg');
        $newPath = $this->service->updateCoverPhoto($stock, $newFakeFile);
        $stock->refresh();
        $this->assertEquals($newPath, $stock->cover_image_path);
        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing($path);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($newPath);

        // Delete cover
        $deleted = $this->service->deleteCoverPhoto($stock);
        $this->assertTrue($deleted);
        $stock->refresh();
        $this->assertNull($stock->cover_image_path);
        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing($newPath);

        // Delete stock cleans up cover photo if present
        $anotherFile = \Illuminate\Http\UploadedFile::fake()->image('another.jpg');
        $anotherPath = $this->service->updateCoverPhoto($stock, $anotherFile);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($anotherPath);

        $this->service->deleteStock($stock);
        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing($anotherPath);
    }
}

