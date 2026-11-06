<?php

namespace Tests\Unit\Repositories;

use App\Features\Stock\Models\Stock;
use App\Features\Stock\Repositories\StockRepository;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected StockRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new StockRepository();
    }

    public function test_create_and_find(): void
    {
        $user = User::factory()->create();
        $stock = $this->repository->create([
            'user_id' => $user->id,
            'name' => 'Test Stock',
            'sku' => 'TST-001',
            'description' => 'Test Desc',
            'quantity' => 10,
            'status' => 'available',
        ]);

        $this->assertInstanceOf(Stock::class, $stock);
        $this->assertDatabaseHas('stocks', ['id' => $stock->id, 'name' => 'Test Stock', 'sku' => 'TST-001']);

        $found = $this->repository->find($stock->id);
        $this->assertNotNull($found);
        $this->assertEquals($stock->id, $found->id);

        $foundOrFail = $this->repository->findOrFail($stock->id);
        $this->assertEquals($stock->id, $foundOrFail->id);
    }

    public function test_find_or_fail_throws_exception_when_not_found(): void
    {
        $this->expectException(ModelNotFoundException::class);
        $this->repository->findOrFail(9999);
    }

    public function test_update_and_delete(): void
    {
        $user = User::factory()->create();
        $stock = $this->repository->create([
            'user_id' => $user->id,
            'name' => 'Original',
        ]);

        $updated = $this->repository->update($stock, ['name' => 'Updated Name']);
        $this->assertTrue($updated);
        $this->assertDatabaseHas('stocks', ['id' => $stock->id, 'name' => 'Updated Name']);

        $deleted = $this->repository->delete($stock);
        $this->assertTrue($deleted);
        $this->assertDatabaseMissing('stocks', ['id' => $stock->id]);
    }

    public function test_all_for_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Stock::factory()->count(3)->create(['user_id' => $user->id]);
        Stock::factory()->count(2)->create(['user_id' => $otherUser->id]);

        $stocks = $this->repository->allForUser($user->id);
        $this->assertCount(3, $stocks);
    }

    public function test_for_user_with_filters(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        Stock::factory()->create(['user_id' => $user->id, 'name' => 'Kertas HVS', 'sku' => 'ATK-001', 'status' => 'available']);
        Stock::factory()->create(['user_id' => $user->id, 'name' => 'Spidol Hitam', 'sku' => 'ATK-002', 'status' => 'low_stock']);
        Stock::factory()->create(['user_id' => $user->id, 'name' => 'Toner', 'sku' => 'PRN-009', 'description' => 'Untuk kertas laser', 'status' => 'out_of_stock']);
        Stock::factory()->create(['user_id' => $other->id, 'name' => 'Kertas Milik Orang Lain']);

        // Without filter only returns the user's own stocks
        $all = $this->repository->forUser($user->id);
        $this->assertCount(3, $all);

        // Status filter
        $low = $this->repository->forUser($user->id, status: 'low_stock');
        $this->assertCount(1, $low);

        // Search on name and description
        $searchName = $this->repository->forUser($user->id, search: 'kertas');
        $this->assertCount(2, $searchName);

        // Search on SKU
        $searchSku = $this->repository->forUser($user->id, search: 'ATK-');
        $this->assertCount(2, $searchSku);

        // Combined filter
        $combined = $this->repository->forUser($user->id, status: 'available', search: 'kertas');
        $this->assertCount(1, $combined);
    }

    public function test_insert_many(): void
    {
        $user = User::factory()->create();

        $emptyResult = $this->repository->insertMany([]);
        $this->assertTrue($emptyResult);

        $records = [
            ['user_id' => $user->id, 'name' => 'Batch 1', 'quantity' => 5, 'status' => 'low_stock', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $user->id, 'name' => 'Batch 2', 'quantity' => 50, 'status' => 'available', 'created_at' => now(), 'updated_at' => now()],
        ];

        $result = $this->repository->insertMany($records);
        $this->assertTrue($result);
        $this->assertDatabaseCount('stocks', 2);
    }
}
