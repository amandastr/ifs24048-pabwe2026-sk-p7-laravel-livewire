<?php

namespace Tests\Unit\Services;

use App\Features\Stock\Models\Stock;
use App\Features\Stock\Repositories\StockRepository;
use App\Features\Stock\Services\StockExcelService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class StockExcelServiceTest extends TestCase
{
    use RefreshDatabase;

    protected StockExcelService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StockExcelService(new StockRepository());
    }

    public function test_build_spreadsheet_and_export_response(): void
    {
        $user = User::factory()->create();
        $stocks = Stock::factory()->count(2)->create(['user_id' => $user->id]);

        $spreadsheet = $this->service->buildSpreadsheet($stocks, 'Custom Stocks');
        $this->assertInstanceOf(Spreadsheet::class, $spreadsheet);
        $this->assertEquals('Custom Stocks', $spreadsheet->getActiveSheet()->getTitle());

        $response = $this->service->exportToResponse($stocks, 'test.xlsx');
        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        ob_start();
        $response->sendContent();
        $outputContent = ob_get_clean();
        $this->assertNotEmpty($outputContent);
    }

    public function test_export_contains_headers_and_stock_values(): void
    {
        $user = User::factory()->create();
        $withValues = Stock::factory()->create([
            'user_id' => $user->id,
            'name' => 'Kertas HVS',
            'sku' => 'ATK-001',
            'description' => 'Kertas A4',
            'quantity' => 12,
            'unit' => 'rim',
            'price' => 52000,
            'min_stock' => 20,
            'status' => 'low_stock',
        ]);
        $blank = Stock::factory()->create([
            'user_id' => $user->id,
            'name' => 'Tanpa Detail',
            'sku' => null,
            'description' => null,
            'price' => null,
            'quantity' => 0,
            'status' => 'out_of_stock',
        ]);

        $spreadsheet = $this->service->buildSpreadsheet(Stock::whereIn('id', [$withValues->id, $blank->id])->orderBy('id')->get());
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertEquals(
            ['No', 'Name', 'SKU', 'Description', 'Qty', 'Unit', 'Price', 'Min Stock', 'Status', 'Created At'],
            [$sheet->getCell('A1')->getValue(), $sheet->getCell('B1')->getValue(), $sheet->getCell('C1')->getValue(),
                $sheet->getCell('D1')->getValue(), $sheet->getCell('E1')->getValue(), $sheet->getCell('F1')->getValue(),
                $sheet->getCell('G1')->getValue(), $sheet->getCell('H1')->getValue(), $sheet->getCell('I1')->getValue(),
                $sheet->getCell('J1')->getValue()]
        );

        $this->assertEquals('Kertas HVS', $sheet->getCell('B2')->getValue());
        $this->assertEquals('ATK-001', $sheet->getCell('C2')->getValue());
        $this->assertEquals(12, $sheet->getCell('E2')->getValue());
        $this->assertEquals('rim', $sheet->getCell('F2')->getValue());
        $this->assertEquals(52000, $sheet->getCell('G2')->getValue());
        $this->assertEquals(20, $sheet->getCell('H2')->getValue());
        $this->assertEquals('Stok Menipis', $sheet->getCell('I2')->getValue());

        $this->assertEquals('-', $sheet->getCell('C3')->getValue());
        $this->assertEquals('-', $sheet->getCell('D3')->getValue());
        $this->assertEquals('-', $sheet->getCell('G3')->getValue());
        $this->assertEquals('Stok Habis', $sheet->getCell('I3')->getValue());
    }

    public function test_export_handles_empty_collection(): void
    {
        $spreadsheet = $this->service->buildSpreadsheet(new \Illuminate\Database\Eloquent\Collection());

        $this->assertEquals('Name', $spreadsheet->getActiveSheet()->getCell('B1')->getValue());
        $this->assertNull($spreadsheet->getActiveSheet()->getCell('B2')->getValue());
    }

    public function test_export_shows_dash_when_created_at_is_missing(): void
    {
        $stock = new Stock(['name' => 'Tanpa Tanggal', 'quantity' => 1, 'unit' => 'pcs', 'min_stock' => 5, 'status' => 'low_stock']);

        $spreadsheet = $this->service->buildSpreadsheet(new \Illuminate\Database\Eloquent\Collection([$stock]));

        $this->assertEquals('-', $spreadsheet->getActiveSheet()->getCell('J2')->getValue());
    }

    public function test_import_spreadsheet(): void
    {
        $user = User::factory()->create();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Headers
        foreach (['No', 'Name', 'SKU', 'Description', 'Qty', 'Unit', 'Price', 'Min Stock', 'Status'] as $i => $header) {
            $sheet->setCellValue(chr(65 + $i).'1', $header);
        }

        // Row 2: complete row, plenty of stock -> available (status column in file is ignored)
        $sheet->fromArray([1, 'Kertas HVS', 'ATK-001', 'Kertas A4', 100, 'rim', 52000, 20, 'out_of_stock'], null, 'A2');

        // Row 3: dash placeholders, quantity below min stock -> low_stock
        $sheet->fromArray([2, 'Spidol', '-', '-', 4, 'pcs', '-', 10, 'available'], null, 'A3');

        // Row 4: empty quantity / unit / min stock fallbacks -> out_of_stock
        $sheet->setCellValue('A4', 3);
        $sheet->setCellValue('B4', 'Toner');
        $sheet->setCellValue('G4', 385000.756);

        // Row 5: negative numbers are clamped to zero
        $sheet->fromArray([4, 'Stapler', '', '', -7, 'pcs', -1, -3], null, 'A5');

        // Row 6: blank name -> skipped with an error message
        $sheet->fromArray([5, '', 'SKIP-1', 'Tanpa nama', 9], null, 'A6');

        $tempPath = tempnam(sys_get_temp_dir(), 'test_excel_').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        $result = $this->service->import($tempPath, $user->id);
        @unlink($tempPath);

        $this->assertEquals(4, $result['imported']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('Baris 6', $result['errors'][0]);

        $this->assertDatabaseHas('stocks', [
            'user_id' => $user->id,
            'name' => 'Kertas HVS',
            'sku' => 'ATK-001',
            'description' => 'Kertas A4',
            'quantity' => 100,
            'unit' => 'rim',
            'price' => 52000,
            'min_stock' => 20,
            'status' => 'available',
        ]);
        $this->assertDatabaseHas('stocks', [
            'user_id' => $user->id,
            'name' => 'Spidol',
            'sku' => null,
            'description' => null,
            'quantity' => 4,
            'price' => null,
            'min_stock' => 10,
            'status' => 'low_stock',
        ]);
        $this->assertDatabaseHas('stocks', [
            'user_id' => $user->id,
            'name' => 'Toner',
            'quantity' => 0,
            'unit' => 'pcs',
            'price' => 385000.76,
            'min_stock' => 5,
            'status' => 'out_of_stock',
        ]);
        $this->assertDatabaseHas('stocks', [
            'user_id' => $user->id,
            'name' => 'Stapler',
            'quantity' => 0,
            'price' => 0,
            'min_stock' => 0,
            'status' => 'out_of_stock',
        ]);
    }

    public function test_import_with_only_header_imports_nothing(): void
    {
        $user = User::factory()->create();

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->setCellValue('B1', 'Name');

        $tempPath = tempnam(sys_get_temp_dir(), 'test_excel_').'.xlsx';
        (new Xlsx($spreadsheet))->save($tempPath);

        $result = $this->service->import($tempPath, $user->id);
        @unlink($tempPath);

        $this->assertSame(0, $result['imported']);
        $this->assertSame([], $result['errors']);
        $this->assertDatabaseCount('stocks', 0);
    }
}
