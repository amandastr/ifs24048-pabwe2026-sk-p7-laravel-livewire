<?php

namespace Tests\Feature\Admin;

use App\Features\Admin\Livewire\AdminUserStockList;
use App\Features\Admin\Livewire\UserList;
use App\Features\Stock\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class AdminLivewireTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_forbidden_from_admin_pages(): void
    {
        $member = User::factory()->member()->create();
        $targetUser = User::factory()->member()->create();

        $this->actingAs($member)
            ->get('/admin/users')
            ->assertStatus(403);

        $this->actingAs($member)
            ->get("/admin/users/{$targetUser->id}/stocks")
            ->assertStatus(403);
    }

    public function test_admin_can_view_user_list(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['name' => 'Budi Santoso', 'email' => 'budi@test.com']);
        User::factory()->create(['name' => 'Citra Lestari', 'email' => 'citra@test.com']);

        $this->actingAs($admin);

        Livewire::test(UserList::class)
            ->assertSee('Budi Santoso')
            ->assertSee('Citra Lestari')
            ->set('search', 'Budi')
            ->assertSee('Budi Santoso')
            ->assertDontSee('Citra Lestari');
    }

    public function test_admin_user_list_shows_stock_count(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->member()->create(['name' => 'Fajar Nugraha']);
        Stock::factory()->count(3)->create(['user_id' => $member->id]);

        $this->actingAs($admin);

        Livewire::test(UserList::class)
            ->assertSee('Fajar Nugraha')
            ->assertSee('Lihat Stok')
            ->assertSeeHtml(route('admin.users.stocks', $member->id));
    }

    public function test_admin_can_view_user_stocks_read_only(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->member()->create(['name' => 'Dewi Sartika']);

        Stock::factory()->create(['user_id' => $member->id, 'name' => 'Kertas Matematika', 'sku' => 'MTK-1', 'quantity' => 50, 'status' => 'available', 'price' => 12000]);
        Stock::factory()->create(['user_id' => $member->id, 'name' => 'Spidol Fisika', 'sku' => 'FSK-1', 'quantity' => 2, 'status' => 'low_stock', 'price' => null]);

        $this->actingAs($admin);

        Livewire::test(AdminUserStockList::class, ['user' => $member])
            ->assertSee('Dewi Sartika')
            ->assertSee('Kertas Matematika')
            ->assertSee('Spidol Fisika')
            ->assertSee('Rp 12.000')
            ->assertSee('Stok Menipis')
            ->set('search', 'Matematika')
            ->assertSee('Kertas Matematika')
            ->assertDontSee('Spidol Fisika')
            ->set('search', '')
            ->set('statusFilter', 'low_stock')
            ->assertSee('Spidol Fisika')
            ->assertDontSee('Kertas Matematika');
    }

    public function test_admin_stock_page_has_no_edit_or_delete_actions(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->member()->create();
        Stock::factory()->create(['user_id' => $member->id, 'name' => 'Barang Read Only']);

        $this->actingAs($admin);

        Livewire::test(AdminUserStockList::class, ['user' => $member])
            ->assertSee('Barang Read Only')
            ->assertDontSee('adjustQuantity')
            ->assertDontSee('deleteStock')
            ->assertDontSee('openEditModal');
    }

    public function test_admin_user_stock_list_shows_empty_state(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->member()->create();

        $this->actingAs($admin);

        Livewire::test(AdminUserStockList::class, ['user' => $member])
            ->assertSee('Pengguna ini belum memiliki stok barang.');
    }

    public function test_admin_can_open_user_stocks_route(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->member()->create();

        $this->actingAs($admin)
            ->get("/admin/users/{$member->id}/stocks")
            ->assertOk()
            ->assertSeeLivewire(AdminUserStockList::class);
    }

    public function test_admin_can_export_user_stocks(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->member()->create(['name' => 'Eko Prasetyo']);
        Stock::factory()->create(['user_id' => $member->id, 'name' => 'Stok Export Admin']);

        $this->actingAs($admin);

        Livewire::test(AdminUserStockList::class, ['user' => $member])
            ->call('exportExcel')
            ->assertFileDownloaded();
    }
}
