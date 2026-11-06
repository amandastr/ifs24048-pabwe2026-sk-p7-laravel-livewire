<?php

namespace App\Features\Stock\Livewire;

use App\Features\Ai\Contracts\AiClientInterface;
use App\Features\Stock\Enums\StockStatus;
use App\Features\Stock\Models\Stock;
use App\Features\Stock\Services\StockExcelService;
use App\Features\Stock\Services\StockService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('components.layouts.app')]
#[Title('Stok Saya - StockApp')]
class StockList extends Component
{
    use WithPagination, WithFileUploads;

    // Filters
    public string $search = '';
    public string $statusFilter = '';

    // Create / Edit Modal State
    public bool $showFormModal = false;
    public ?int $editingStockId = null;
    public string $formName = '';
    public ?string $formSku = null;
    public ?string $formDescription = null;
    public mixed $formQuantity = 0;
    public string $formUnit = 'pcs';
    public ?string $formPrice = null;
    public mixed $formMinStock = 5;

    // Delete Confirmation Modal State
    public ?int $confirmingStockDeletionId = null;

    // Excel Import State
    public bool $showImportModal = false;
    public mixed $importFile = null;

    // AI Generator State
    public bool $showAiModal = false;
    public string $aiPrompt = '';
    public int $aiCount = 3;
    public array $aiGeneratedItems = [];
    public array $aiSelectedIndices = [];
    public bool $aiIsGenerating = false;
    public ?string $aiErrorMessage = null;

    protected $queryString = [
        'search' => ['except' => ''],
        'statusFilter' => ['except' => ''],
    ];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    // --- CRUD Operations ---

    public function openCreateModal(): void
    {
        $this->resetForm();
        $this->showFormModal = true;
    }

    public function openEditModal(int $id): void
    {
        $stock = Stock::findOrFail($id);
        Gate::authorize('update', $stock);

        $this->resetErrorBag();
        $this->editingStockId = $stock->id;
        $this->formName = $stock->name;
        $this->formSku = $stock->sku;
        $this->formDescription = $stock->description;
        $this->formQuantity = $stock->quantity;
        $this->formUnit = $stock->unit;
        $this->formPrice = $stock->price !== null ? (string) $stock->price : null;
        $this->formMinStock = $stock->min_stock;
        $this->showFormModal = true;
    }

    public function closeFormModal(): void
    {
        // Tutup dulu tanpa reset berat agar response terasa instan.
        $this->showFormModal = false;
    }

    public function resetForm(): void
    {
        $this->reset(['editingStockId', 'formName', 'formSku', 'formDescription', 'formPrice']);
        $this->formQuantity = 0;
        $this->formUnit = 'pcs';
        $this->formMinStock = 5;
        $this->resetErrorBag();
    }

    public function saveStock(StockService $stockService): void
    {
        $this->validate([
            'formName' => ['required', 'string', 'max:255'],
            'formSku' => ['nullable', 'string', 'max:100'],
            'formDescription' => ['nullable', 'string'],
            'formQuantity' => ['required', 'integer', 'min:0'],
            'formUnit' => ['required', 'string', 'max:50'],
            'formPrice' => ['nullable', 'numeric', 'min:0'],
            'formMinStock' => ['required', 'integer', 'min:0'],
        ]);

        $data = [
            'name' => $this->formName,
            'sku' => $this->formSku ?: null,
            'description' => $this->formDescription,
            'quantity' => (int) $this->formQuantity,
            'unit' => $this->formUnit,
            'price' => $this->formPrice === null || $this->formPrice === '' ? null : $this->formPrice,
            'min_stock' => (int) $this->formMinStock,
        ];

        if ($this->editingStockId) {
            $stock = Stock::findOrFail($this->editingStockId);
            Gate::authorize('update', $stock);
            $stockService->updateStock($stock, $data);
            session()->flash('success', 'Stok berhasil diperbarui.');
        } else {
            $stockService->createStock(Auth::id(), $data);
            session()->flash('success', 'Stok baru berhasil ditambahkan.');
        }

        $this->closeFormModal();
    }

    /**
     * Increase or decrease the quantity directly from the list (status follows automatically).
     */
    public function adjustQuantity(int $id, int $delta, StockService $stockService): void
    {
        $stock = Stock::findOrFail($id);
        Gate::authorize('update', $stock);

        $stockService->adjustQuantity($stock, $delta);
    }

    public function confirmDelete(int $id): void
    {
        $this->confirmingStockDeletionId = $id;
    }

    public function cancelDelete(): void
    {
        $this->confirmingStockDeletionId = null;
    }

    public function deleteStock(?int $id = null, ?StockService $stockService = null): void
    {
        $stockService = $stockService ?? app(StockService::class);
        $targetId = $id ?? $this->confirmingStockDeletionId;

        if ($targetId) {
            $stock = Stock::findOrFail($targetId);
            Gate::authorize('delete', $stock);

            $stockService->deleteStock($stock);
            $this->confirmingStockDeletionId = null;
            session()->flash('success', 'Stok berhasil dihapus.');
        }
    }

    // --- Excel Export & Import ---

    public function exportExcel(StockExcelService $excelService): StreamedResponse
    {
        $stocks = Auth::user()->stocks()->latest('id')->get();

        return $excelService->exportToResponse($stocks, 'stok-barang-'.now()->format('Ymd-His').'.xlsx');
    }

    public function openImportModal(): void
    {
        $this->reset(['importFile']);
        $this->showImportModal = true;
    }

    public function closeImportModal(): void
    {
        $this->showImportModal = false;
    }

    public function importExcel(StockExcelService $excelService): void
    {
        $this->validate([
            'importFile' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        $path = $this->importFile->getRealPath();
        $result = $excelService->import($path, Auth::id());

        $this->closeImportModal();
        session()->flash('success', "Berhasil mengimpor {$result['imported']} item stok.");
    }

    // --- AI Stock Generator ---

    public function openAiModal(): void
    {
        $this->reset(['aiPrompt', 'aiGeneratedItems', 'aiSelectedIndices', 'aiErrorMessage', 'aiIsGenerating']);
        $this->aiCount = 3;
        $this->showAiModal = true;
    }

    public function closeAiModal(): void
    {
        $this->showAiModal = false;
    }

    public function generateWithAi(AiClientInterface $aiClient): void
    {
        $this->validate([
            'aiPrompt' => ['required', 'string', 'min:3', 'max:500'],
            'aiCount' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        $this->aiIsGenerating = true;
        $this->aiErrorMessage = null;

        try {
            $items = $aiClient->generateStocks($this->aiPrompt, $this->aiCount);
            $this->aiGeneratedItems = array_map(fn ($item) => $item->toArray(), $items);
            $this->aiSelectedIndices = array_keys($this->aiGeneratedItems);
        } catch (\Throwable $e) {
            $this->aiErrorMessage = $e->getMessage();
        } finally {
            $this->aiIsGenerating = false;
        }
    }

    public function saveAiGenerated(StockService $stockService): void
    {
        if (empty($this->aiSelectedIndices) || empty($this->aiGeneratedItems)) {
            $this->aiErrorMessage = 'Pilih minimal satu item stok yang ingin disimpan.';
            return;
        }

        $savedCount = 0;
        foreach ($this->aiSelectedIndices as $index) {
            if (isset($this->aiGeneratedItems[$index])) {
                $stockService->createStock(Auth::id(), $this->aiGeneratedItems[$index]);
                $savedCount++;
            }
        }

        $this->closeAiModal();
        session()->flash('success', "Berhasil menambahkan {$savedCount} item stok yang digenerate oleh AI.");
    }

    public function render(StockService $stockService): View
    {
        $stocks = $stockService->getStocksForUser(
            Auth::id(),
            $this->statusFilter ?: null,
            $this->search ?: null,
            8
        );

        return view('livewire.stock.stock-list', [
            'stocks' => $stocks,
            'statuses' => StockStatus::cases(),
            'formStatus' => StockStatus::fromQuantity((int) $this->formQuantity, (int) $this->formMinStock),
        ]);
    }
}
