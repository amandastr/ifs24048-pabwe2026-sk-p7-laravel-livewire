<?php

namespace Tests\Feature\Stock;

use App\Features\Stock\Livewire\StockDetail;
use App\Features\Stock\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class StockDetailLivewireTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_stock_detail(): void
    {
        $stock = Stock::factory()->create();

        $this->get(route('stocks.show', $stock->id))
            ->assertRedirect(route('login'));
    }

    public function test_member_cannot_access_other_member_stock(): void
    {
        $owner = User::factory()->member()->create();
        $otherMember = User::factory()->member()->create();

        $stock = Stock::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($otherMember);

        $this->get(route('stocks.show', $stock->id))
            ->assertStatus(403);
    }

    public function test_member_can_open_own_stock_detail_via_route(): void
    {
        $user = User::factory()->member()->create();
        $stock = Stock::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('stocks.show', $stock->id))
            ->assertOk()
            ->assertSeeLivewire(StockDetail::class);
    }

    public function test_member_can_view_own_stock_detail(): void
    {
        $user = User::factory()->member()->create();
        $stock = Stock::factory()->create([
            'user_id' => $user->id,
            'name' => 'Kertas HVS Pro',
            'sku' => 'ATK-009',
            'description' => '# Deskripsi Markdown',
            'quantity' => 40,
            'unit' => 'rim',
            'price' => 52000,
            'min_stock' => 10,
            'status' => 'available',
        ]);

        $this->actingAs($user);

        Livewire::test(StockDetail::class, ['stock' => $stock])
            ->assertOk()
            ->assertSet('name', 'Kertas HVS Pro')
            ->assertSet('sku', 'ATK-009')
            ->assertSet('description', '# Deskripsi Markdown')
            ->assertSet('quantity', 40)
            ->assertSet('unit', 'rim')
            ->assertSet('price', '52000.00')
            ->assertSet('minStock', 10)
            ->assertSee('Kertas HVS Pro')
            ->assertSee('ATK-009')
            ->assertSee('Tersedia')
            ->assertSee('Rp 52.000');
    }

    public function test_detail_without_optional_values(): void
    {
        $user = User::factory()->member()->create();
        $stock = Stock::factory()->create([
            'user_id' => $user->id,
            'name' => 'Barang Polos',
            'sku' => null,
            'description' => null,
            'price' => null,
            'quantity' => 0,
            'status' => 'out_of_stock',
        ]);

        $this->actingAs($user);

        Livewire::test(StockDetail::class, ['stock' => $stock])
            ->assertSet('sku', null)
            ->assertSet('description', '')
            ->assertSet('price', null)
            ->assertSee('Belum diatur')
            ->assertSee('Stok Habis')
            ->assertSee('Belum ada deskripsi untuk stok ini');
    }

    public function test_admin_can_view_but_not_modify_stock_detail(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->member()->create();
        $stock = Stock::factory()->create(['user_id' => $member->id, 'quantity' => 10]);

        $this->actingAs($admin);

        Livewire::test(StockDetail::class, ['stock' => $stock])
            ->assertOk()
            ->call('openEditModal')->assertForbidden();

        Livewire::test(StockDetail::class, ['stock' => $stock])
            ->call('adjustQuantity', 1)->assertForbidden();

        Livewire::test(StockDetail::class, ['stock' => $stock])
            ->call('deleteStock')->assertForbidden();

        $this->assertDatabaseHas('stocks', ['id' => $stock->id, 'quantity' => 10]);
    }

    public function test_open_and_close_edit_modal(): void
    {
        $user = User::factory()->member()->create();
        $stock = Stock::factory()->create([
            'user_id' => $user->id,
            'name' => 'Nama Detail',
            'description' => 'Deskripsi lama',
            'quantity' => 8,
        ]);

        $this->actingAs($user);

        Livewire::test(StockDetail::class, ['stock' => $stock])
            ->assertSet('showEditModal', false)
            ->set('name', 'Diubah Sementara')
            ->call('openEditModal')
            ->assertSet('showEditModal', true)
            ->assertSet('name', 'Nama Detail')
            ->assertSet('description', 'Deskripsi lama')
            ->assertSet('quantity', 8)
            ->call('closeEditModal')
            ->assertSet('showEditModal', false);
    }

    public function test_edit_modal_status_preview_follows_quantity(): void
    {
        $user = User::factory()->member()->create();
        $stock = Stock::factory()->create(['user_id' => $user->id, 'quantity' => 50, 'min_stock' => 5]);

        $this->actingAs($user);

        Livewire::test(StockDetail::class, ['stock' => $stock])
            ->call('openEditModal')
            ->set('quantity', 2)
            ->assertSee('Stok Menipis')
            ->set('quantity', 0)
            ->assertSee('Stok Habis');
    }

    public function test_update_stock_validation_and_success(): void
    {
        $user = User::factory()->member()->create();
        $stock = Stock::factory()->create(['user_id' => $user->id, 'name' => 'Nama Awal']);

        $this->actingAs($user);

        // Validation errors
        Livewire::test(StockDetail::class, ['stock' => $stock])
            ->call('openEditModal')
            ->set('name', 'ab') // min:3
            ->set('quantity', -2)
            ->set('unit', '')
            ->set('price', '-1')
            ->set('minStock', 'x')
            ->call('saveStock')
            ->assertHasErrors(['name', 'quantity', 'unit', 'price', 'minStock']);

        // Success
        Livewire::test(StockDetail::class, ['stock' => $stock])
            ->call('openEditModal')
            ->set('name', 'Nama Telah Diperbarui')
            ->set('sku', 'NEW-001')
            ->set('description', '## Rincian Baru')
            ->set('quantity', 4)
            ->set('unit', 'dus')
            ->set('price', '75000')
            ->set('minStock', 10)
            ->call('saveStock')
            ->assertHasNoErrors()
            ->assertSet('showEditModal', false);

        $this->assertDatabaseHas('stocks', [
            'id' => $stock->id,
            'name' => 'Nama Telah Diperbarui',
            'sku' => 'NEW-001',
            'description' => '## Rincian Baru',
            'quantity' => 4,
            'unit' => 'dus',
            'price' => 75000,
            'min_stock' => 10,
            'status' => 'low_stock',
        ]);
    }

    public function test_update_stock_can_clear_sku_and_price(): void
    {
        $user = User::factory()->member()->create();
        $stock = Stock::factory()->create(['user_id' => $user->id, 'sku' => 'OLD', 'price' => 1000]);

        $this->actingAs($user);

        Livewire::test(StockDetail::class, ['stock' => $stock])
            ->call('openEditModal')
            ->set('sku', '')
            ->set('price', '')
            ->call('saveStock')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('stocks', ['id' => $stock->id, 'sku' => null, 'price' => null]);
    }

    public function test_adjust_quantity_updates_quantity_and_status(): void
    {
        $user = User::factory()->member()->create();
        $stock = Stock::factory()->create([
            'user_id' => $user->id,
            'quantity' => 6,
            'min_stock' => 5,
            'status' => 'available',
        ]);

        $this->actingAs($user);

        Livewire::test(StockDetail::class, ['stock' => $stock])
            ->call('adjustQuantity', -1)
            ->assertSet('quantity', 5);

        $this->assertDatabaseHas('stocks', ['id' => $stock->id, 'quantity' => 5, 'status' => 'low_stock']);

        Livewire::test(StockDetail::class, ['stock' => $stock->fresh()])
            ->call('adjustQuantity', -9)
            ->assertSet('quantity', 0);

        $this->assertDatabaseHas('stocks', ['id' => $stock->id, 'quantity' => 0, 'status' => 'out_of_stock']);

        Livewire::test(StockDetail::class, ['stock' => $stock->fresh()])
            ->call('adjustQuantity', 30)
            ->assertSet('quantity', 30);

        $this->assertDatabaseHas('stocks', ['id' => $stock->id, 'quantity' => 30, 'status' => 'available']);
    }

    public function test_cover_photo_upload_and_delete(): void
    {
        Storage::fake('public');

        $user = User::factory()->member()->create();
        $stock = Stock::factory()->create(['user_id' => $user->id, 'cover_image_path' => null]);

        $this->actingAs($user);

        // Invalid file
        $invalidFile = UploadedFile::fake()->create('document.pdf', 100);
        Livewire::test(StockDetail::class, ['stock' => $stock])
            ->set('coverPhoto', $invalidFile)
            ->assertHasErrors(['coverPhoto']);

        // Valid image upload
        $imageFile = UploadedFile::fake()->image('cover.jpg', 800, 400);
        $component = Livewire::test(StockDetail::class, ['stock' => $stock])
            ->set('coverPhoto', $imageFile)
            ->assertHasNoErrors();

        $stock->refresh();
        $this->assertNotNull($stock->cover_image_path);
        Storage::disk('public')->assertExists($stock->cover_image_path);

        // Delete cover photo
        $component->call('deleteCoverPhoto');

        $stock->refresh();
        $this->assertNull($stock->cover_image_path);
    }

    public function test_detail_renders_cover_image_when_present(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('stock-covers/existing.png', 'fake-image');

        $user = User::factory()->member()->create();
        $stock = Stock::factory()->create(['user_id' => $user->id, 'cover_image_path' => 'stock-covers/existing.png']);

        $this->actingAs($user);

        Livewire::test(StockDetail::class, ['stock' => $stock])
            ->assertSee('Ganti Cover')
            ->assertSee('stock-covers/existing.png');
    }

    public function test_delete_stock_redirects(): void
    {
        $user = User::factory()->member()->create();
        $stock = Stock::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user);

        Livewire::test(StockDetail::class, ['stock' => $stock])
            ->call('deleteStock')
            ->assertRedirect(route('stocks.index'));

        $this->assertDatabaseMissing('stocks', ['id' => $stock->id]);
    }

    public function test_confirm_and_cancel_delete_modal_actions(): void
    {
        $user = User::factory()->member()->create();
        $stock = Stock::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user);

        Livewire::test(StockDetail::class, ['stock' => $stock])
            ->assertSet('confirmingStockDeletion', false)
            ->call('confirmDeleteStock')
            ->assertSet('confirmingStockDeletion', true)
            ->call('cancelDeleteStock')
            ->assertSet('confirmingStockDeletion', false)
            ->assertSet('confirmingCoverDeletion', false)
            ->call('confirmDeleteCover')
            ->assertSet('confirmingCoverDeletion', true)
            ->call('cancelDeleteCover')
            ->assertSet('confirmingCoverDeletion', false);
    }
}
