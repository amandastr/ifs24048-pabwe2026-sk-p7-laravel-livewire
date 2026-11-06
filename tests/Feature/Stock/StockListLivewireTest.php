<?php

namespace Tests\Feature\Stock;

use App\Features\Ai\Contracts\AiClientInterface;
use App\Features\Ai\DTO\GeneratedStockItem;
use App\Features\Stock\Livewire\StockList;
use App\Features\Stock\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class StockListLivewireTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_stocks(): void
    {
        $this->get('/stocks')->assertRedirect(route('login'));
    }

    public function test_member_can_view_stocks_page_via_route(): void
    {
        $user = User::factory()->member()->create();

        $this->actingAs($user)->get('/stocks')->assertOk()->assertSeeLivewire(StockList::class);
    }

    public function test_member_can_view_own_stocks(): void
    {
        $user = User::factory()->member()->create();
        Stock::factory()->create(['user_id' => $user->id, 'name' => 'Barang Spesifik Member', 'sku' => 'MBR-001']);

        $otherUser = User::factory()->member()->create();
        Stock::factory()->create(['user_id' => $otherUser->id, 'name' => 'Barang Rahasia Orang Lain']);

        $this->actingAs($user);

        Livewire::test(StockList::class)
            ->assertSee('Barang Spesifik Member')
            ->assertSee('MBR-001')
            ->assertDontSee('Barang Rahasia Orang Lain');
    }

    public function test_list_shows_status_label_and_quantity(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user);

        Stock::factory()->create(['user_id' => $user->id, 'name' => 'Item Habis', 'quantity' => 0, 'status' => 'out_of_stock', 'price' => null, 'sku' => null]);
        Stock::factory()->create(['user_id' => $user->id, 'name' => 'Item Menipis', 'quantity' => 2, 'status' => 'low_stock', 'price' => 15000]);

        Livewire::test(StockList::class)
            ->assertSee('Stok Habis')
            ->assertSee('Stok Menipis')
            ->assertSee('Rp 15.000');
    }

    public function test_create_stock_validation_and_success(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user);

        // Validation errors
        Livewire::test(StockList::class)
            ->call('openCreateModal')
            ->set('formName', '')
            ->set('formQuantity', -1)
            ->set('formMinStock', 'abc')
            ->set('formUnit', '')
            ->set('formPrice', '-5')
            ->call('saveStock')
            ->assertHasErrors(['formName', 'formQuantity', 'formMinStock', 'formUnit', 'formPrice']);

        // Success
        Livewire::test(StockList::class)
            ->call('openCreateModal')
            ->set('formName', 'Kertas HVS A4')
            ->set('formSku', 'ATK-001')
            ->set('formDescription', 'Kertas **A4** 80gsm')
            ->set('formQuantity', 3)
            ->set('formUnit', 'rim')
            ->set('formPrice', '52000')
            ->set('formMinStock', 10)
            ->call('saveStock')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false);

        $this->assertDatabaseHas('stocks', [
            'user_id' => $user->id,
            'name' => 'Kertas HVS A4',
            'sku' => 'ATK-001',
            'description' => 'Kertas **A4** 80gsm',
            'quantity' => 3,
            'unit' => 'rim',
            'price' => 52000,
            'min_stock' => 10,
            'status' => 'low_stock',
        ]);
    }

    public function test_create_stock_with_minimal_fields_uses_defaults(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user);

        Livewire::test(StockList::class)
            ->call('openCreateModal')
            ->assertSet('formQuantity', 0)
            ->assertSet('formUnit', 'pcs')
            ->assertSet('formMinStock', 5)
            ->set('formName', 'Barang Minimal')
            ->set('formQuantity', 25)
            ->set('formPrice', '')
            ->call('saveStock')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('stocks', [
            'user_id' => $user->id,
            'name' => 'Barang Minimal',
            'sku' => null,
            'price' => null,
            'unit' => 'pcs',
            'status' => 'available',
        ]);
    }

    public function test_form_status_preview_follows_quantity_and_min_stock(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user);

        Livewire::test(StockList::class)
            ->call('openCreateModal')
            ->assertSee('Stok Habis')
            ->set('formQuantity', 3)
            ->assertSee('Stok Menipis')
            ->set('formQuantity', 50)
            ->assertSee('Tersedia');
    }

    public function test_reopening_create_modal_resets_previous_form(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user);

        $stock = Stock::factory()->create(['user_id' => $user->id, 'name' => 'Sedang Diedit', 'price' => 2500]);

        Livewire::test(StockList::class)
            ->call('openEditModal', $stock->id)
            ->assertSet('formName', 'Sedang Diedit')
            ->call('openCreateModal')
            ->assertSet('editingStockId', null)
            ->assertSet('formName', '')
            ->assertSet('formPrice', null)
            ->assertSet('formQuantity', 0)
            ->assertSet('formMinStock', 5);
    }

    public function test_edit_stock_success(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user);

        $stock = Stock::factory()->create([
            'user_id' => $user->id,
            'name' => 'Nama Lama',
            'sku' => 'OLD-1',
            'quantity' => 50,
            'unit' => 'pcs',
            'price' => 1500.5,
            'min_stock' => 5,
        ]);

        Livewire::test(StockList::class)
            ->call('openEditModal', $stock->id)
            ->assertSet('editingStockId', $stock->id)
            ->assertSet('formName', 'Nama Lama')
            ->assertSet('formSku', 'OLD-1')
            ->assertSet('formQuantity', 50)
            ->assertSet('formPrice', '1500.50')
            ->assertSet('formMinStock', 5)
            ->set('formName', 'Nama Telah Diedit')
            ->set('formQuantity', 0)
            ->call('saveStock')
            ->assertHasNoErrors()
            ->assertSet('showFormModal', false);

        $this->assertDatabaseHas('stocks', [
            'id' => $stock->id,
            'name' => 'Nama Telah Diedit',
            'quantity' => 0,
            'status' => 'out_of_stock',
        ]);
    }

    public function test_edit_stock_without_price_leaves_price_empty(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user);

        $stock = Stock::factory()->create(['user_id' => $user->id, 'price' => null]);

        Livewire::test(StockList::class)
            ->call('openEditModal', $stock->id)
            ->assertSet('formPrice', null);
    }

    public function test_member_cannot_edit_or_adjust_or_delete_other_users_stock(): void
    {
        $user = User::factory()->member()->create();
        $other = User::factory()->member()->create();
        $foreign = Stock::factory()->create(['user_id' => $other->id, 'quantity' => 10]);

        $this->actingAs($user);

        Livewire::test(StockList::class)->call('openEditModal', $foreign->id)->assertForbidden();
        Livewire::test(StockList::class)->call('adjustQuantity', $foreign->id, 1)->assertForbidden();
        Livewire::test(StockList::class)->call('deleteStock', $foreign->id)->assertForbidden();

        $this->assertDatabaseHas('stocks', ['id' => $foreign->id, 'quantity' => 10]);
    }

    public function test_saving_a_stock_owned_by_someone_else_is_forbidden(): void
    {
        $user = User::factory()->member()->create();
        $other = User::factory()->member()->create();
        $foreign = Stock::factory()->create(['user_id' => $other->id, 'name' => 'Milik Orang Lain']);

        $this->actingAs($user);

        Livewire::test(StockList::class)
            ->set('editingStockId', $foreign->id)
            ->set('formName', 'Dibajak')
            ->call('saveStock')
            ->assertForbidden();

        $this->assertDatabaseHas('stocks', ['id' => $foreign->id, 'name' => 'Milik Orang Lain']);
    }

    public function test_adjust_quantity_from_list_updates_quantity_and_status(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user);

        $stock = Stock::factory()->create([
            'user_id' => $user->id,
            'quantity' => 6,
            'min_stock' => 5,
            'status' => 'available',
        ]);

        $component = Livewire::test(StockList::class);

        $component->call('adjustQuantity', $stock->id, -1);
        $stock->refresh();
        $this->assertSame(5, $stock->quantity);
        $this->assertEquals('low_stock', $stock->status);

        $component->call('adjustQuantity', $stock->id, -10);
        $stock->refresh();
        $this->assertSame(0, $stock->quantity);
        $this->assertEquals('out_of_stock', $stock->status);

        $component->call('adjustQuantity', $stock->id, 1);
        $stock->refresh();
        $this->assertSame(1, $stock->quantity);
        $this->assertEquals('low_stock', $stock->status);

        $component->call('adjustQuantity', $stock->id, 20);
        $this->assertEquals('available', $stock->fresh()->status);
    }

    public function test_delete_stock(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user);

        $stock = Stock::factory()->create(['user_id' => $user->id]);

        Livewire::test(StockList::class)
            ->call('deleteStock', $stock->id);

        $this->assertDatabaseMissing('stocks', ['id' => $stock->id]);
    }

    public function test_confirm_and_cancel_delete_stock(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user);

        $stock = Stock::factory()->create(['user_id' => $user->id]);

        Livewire::test(StockList::class)
            ->assertSet('confirmingStockDeletionId', null)
            ->call('confirmDelete', $stock->id)
            ->assertSet('confirmingStockDeletionId', $stock->id)
            ->call('cancelDelete')
            ->assertSet('confirmingStockDeletionId', null)
            ->call('confirmDelete', $stock->id)
            ->call('deleteStock')
            ->assertSet('confirmingStockDeletionId', null);

        $this->assertDatabaseMissing('stocks', ['id' => $stock->id]);
    }

    public function test_delete_without_target_does_nothing(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user);

        $stock = Stock::factory()->create(['user_id' => $user->id]);

        Livewire::test(StockList::class)->call('deleteStock');

        $this->assertDatabaseHas('stocks', ['id' => $stock->id]);
    }

    public function test_search_and_status_filter(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user);

        Stock::factory()->create(['user_id' => $user->id, 'name' => 'Kertas Folio', 'sku' => 'KRT-77', 'quantity' => 100, 'status' => 'available']);
        Stock::factory()->create(['user_id' => $user->id, 'name' => 'Spidol Merah', 'sku' => 'SPD-01', 'quantity' => 2, 'status' => 'low_stock']);

        Livewire::test(StockList::class)
            ->set('search', 'Folio')
            ->assertSee('Kertas Folio')
            ->assertDontSee('Spidol Merah')
            ->set('search', 'SPD-01')
            ->assertSee('Spidol Merah')
            ->assertDontSee('Kertas Folio')
            ->set('search', '')
            ->set('statusFilter', 'low_stock')
            ->assertSee('Spidol Merah')
            ->assertDontSee('Kertas Folio');
    }

    public function test_export_excel(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user);

        Stock::factory()->create(['user_id' => $user->id, 'name' => 'Exportable Stock']);

        Livewire::test(StockList::class)
            ->call('exportExcel')
            ->assertFileDownloaded();
    }

    public function test_import_excel(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach (['No', 'Name', 'SKU', 'Description', 'Qty', 'Unit', 'Price', 'Min Stock', 'Status'] as $i => $header) {
            $sheet->setCellValue(chr(65 + $i).'1', $header);
        }
        $sheet->fromArray([1, 'Imported Excel Stock', 'XLS-001', 'Excel Desc', 3, 'box', 25000, 10, 'available'], null, 'A2');

        $tempPath = tempnam(sys_get_temp_dir(), 'test_upload_').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        $fileContent = file_get_contents($tempPath);
        @unlink($tempPath);

        $uploadedFile = UploadedFile::fake()->createWithContent('stocks.xlsx', $fileContent);

        Livewire::test(StockList::class)
            ->call('openImportModal')
            ->assertSet('showImportModal', true)
            ->set('importFile', $uploadedFile)
            ->call('importExcel')
            ->assertSet('showImportModal', false);

        $this->assertDatabaseHas('stocks', [
            'user_id' => $user->id,
            'name' => 'Imported Excel Stock',
            'sku' => 'XLS-001',
            'description' => 'Excel Desc',
            'quantity' => 3,
            'unit' => 'box',
            'min_stock' => 10,
            'status' => 'low_stock',
        ]);
    }

    public function test_import_excel_requires_valid_file(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user);

        Livewire::test(StockList::class)
            ->call('openImportModal')
            ->call('importExcel')
            ->assertHasErrors(['importFile' => 'required']);
    }

    public function test_ai_generation_and_saving(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user);

        // Mock AiClientInterface
        $mockAiClient = $this->createStub(AiClientInterface::class);
        $mockAiClient->method('generateStocks')->willReturn([
            new GeneratedStockItem('AI Item 1', 'AI-001', 'Desc 1', 30, 'rim', 52000.0, 10),
            new GeneratedStockItem('AI Item 2', null, null, 2, 'pcs', null, 5),
        ]);
        $this->app->instance(AiClientInterface::class, $mockAiClient);

        // Test validation for prompt and count
        Livewire::test(StockList::class)
            ->call('openAiModal')
            ->set('aiPrompt', '')
            ->set('aiCount', 0)
            ->call('generateWithAi')
            ->assertHasErrors(['aiPrompt', 'aiCount']);

        Livewire::test(StockList::class)
            ->call('openAiModal')
            ->set('aiPrompt', 'Daftar ATK')
            ->set('aiCount', 99)
            ->call('generateWithAi')
            ->assertHasErrors(['aiCount']);

        $component = Livewire::test(StockList::class)
            ->call('openAiModal')
            ->assertSet('showAiModal', true)
            ->set('aiPrompt', 'Buatkan daftar perlengkapan kantor')
            ->set('aiCount', 2)
            ->call('generateWithAi')
            ->assertSet('aiIsGenerating', false)
            ->assertCount('aiGeneratedItems', 2)
            ->assertCount('aiSelectedIndices', 2)
            ->assertSee('AI Item 1')
            ->assertSee('AI-001')
            ->assertSee('Rp 52.000');

        // Try saving with no items selected
        $component->set('aiSelectedIndices', [])
            ->call('saveAiGenerated')
            ->assertSet('aiErrorMessage', 'Pilih minimal satu item stok yang ingin disimpan.');

        // Save selected items
        $component->set('aiSelectedIndices', [0, 1, 99])
            ->call('saveAiGenerated')
            ->assertSet('showAiModal', false);

        $this->assertDatabaseHas('stocks', [
            'user_id' => $user->id,
            'name' => 'AI Item 1',
            'sku' => 'AI-001',
            'quantity' => 30,
            'unit' => 'rim',
            'price' => 52000,
            'min_stock' => 10,
            'status' => 'available',
        ]);
        $this->assertDatabaseHas('stocks', ['user_id' => $user->id, 'name' => 'AI Item 2', 'status' => 'low_stock']);
        $this->assertDatabaseCount('stocks', 2);
    }

    public function test_ai_generation_handles_exception(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user);

        $mockAiClient = $this->createStub(AiClientInterface::class);
        $mockAiClient->method('generateStocks')->willThrowException(new \RuntimeException('API quota exceeded'));
        $this->app->instance(AiClientInterface::class, $mockAiClient);

        Livewire::test(StockList::class)
            ->call('openAiModal')
            ->set('aiPrompt', 'Error test')
            ->call('generateWithAi')
            ->assertSet('aiErrorMessage', 'API quota exceeded')
            ->assertSet('aiIsGenerating', false);
    }

    public function test_close_modals(): void
    {
        $user = User::factory()->member()->create();
        $this->actingAs($user);

        Livewire::test(StockList::class)
            ->call('openCreateModal')
            ->call('closeFormModal')
            ->assertSet('showFormModal', false)
            ->call('openImportModal')
            ->call('closeImportModal')
            ->assertSet('showImportModal', false)
            ->call('openAiModal')
            ->call('closeAiModal')
            ->assertSet('showAiModal', false);
    }
}
