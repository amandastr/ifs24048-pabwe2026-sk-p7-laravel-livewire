<?php

namespace App\Features\Stock\Services;

use App\Features\Stock\Contracts\StockRepositoryInterface;
use App\Features\Stock\Enums\StockStatus;
use App\Features\Stock\Models\Stock;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockExcelService
{
    public function __construct(
        protected StockRepositoryInterface $stockRepository
    ) {}

    /**
     * Build a Spreadsheet instance containing stocks data.
     *
     * @param Collection<int, Stock> $stocks
     */
    public function buildSpreadsheet(Collection $stocks, string $sheetTitle = 'Stocks'): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr($sheetTitle, 0, 31));

        $headers = ['No', 'Name', 'SKU', 'Description', 'Qty', 'Unit', 'Price', 'Min Stock', 'Status', 'Created At'];
        $columns = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];

        foreach ($headers as $index => $header) {
            $sheet->setCellValue("{$columns[$index]}1", $header);
        }

        // Header Styling
        $headerRange = 'A1:J1';
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2563EB');
        $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $rowNumber = 2;
        foreach ($stocks as $idx => $stock) {
            $sheet->setCellValue("A{$rowNumber}", $idx + 1);
            $sheet->setCellValue("B{$rowNumber}", $stock->name);
            $sheet->setCellValue("C{$rowNumber}", $stock->sku ?? '-');
            $sheet->setCellValue("D{$rowNumber}", $stock->description ?? '-');
            $sheet->setCellValue("E{$rowNumber}", $stock->quantity);
            $sheet->setCellValue("F{$rowNumber}", $stock->unit);
            $sheet->setCellValue("G{$rowNumber}", $stock->price !== null ? (float) $stock->price : '-');
            $sheet->setCellValue("H{$rowNumber}", $stock->min_stock);
            $sheet->setCellValue("I{$rowNumber}", StockStatus::from($stock->status)->label());
            $sheet->setCellValue("J{$rowNumber}", $stock->created_at ? $stock->created_at->format('Y-m-d H:i') : '-');
            $rowNumber++;
        }

        $lastRow = max(2, $rowNumber - 1);
        $sheet->getStyle("A1:J{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        foreach ($columns as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return $spreadsheet;
    }

    /**
     * Export stocks as a downloadable streamed response.
     *
     * @param Collection<int, Stock> $stocks
     */
    public function exportToResponse(Collection $stocks, string $filename = 'stok-barang.xlsx'): StreamedResponse
    {
        $spreadsheet = $this->buildSpreadsheet($stocks);

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    /**
     * Import stocks from an uploaded spreadsheet file path.
     *
     * Columns: A No, B Name, C SKU, D Description, E Qty, F Unit, G Price, H Min Stock, I Status.
     * The status column is informative only: status is always recalculated from quantity vs min stock.
     *
     * @return array{imported: int, errors: array<int, string>}
     */
    public function import(string $filePath, int $userId): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);

        $errors = [];
        $recordsToInsert = [];
        $now = Carbon::now();

        foreach ($rows as $rowNumber => $row) {
            // Skip header row
            if ($rowNumber === 1) {
                continue;
            }

            $name = trim((string) ($row['B'] ?? ''));
            if ($name === '') {
                $errors[] = "Baris {$rowNumber}: nama barang kosong, dilewati.";
                continue;
            }

            $skuRaw = trim((string) ($row['C'] ?? ''));
            $descriptionRaw = trim((string) ($row['D'] ?? ''));
            $unitRaw = trim((string) ($row['F'] ?? ''));
            $quantityRaw = $row['E'] ?? null;
            $priceRaw = $row['G'] ?? null;
            $minStockRaw = $row['H'] ?? null;

            $quantity = is_numeric($quantityRaw) ? max(0, (int) $quantityRaw) : 0;
            $minStock = is_numeric($minStockRaw) ? max(0, (int) $minStockRaw) : 5;

            $recordsToInsert[] = [
                'user_id' => $userId,
                'name' => substr($name, 0, 255),
                'sku' => ($skuRaw === '' || $skuRaw === '-') ? null : substr($skuRaw, 0, 255),
                'description' => ($descriptionRaw === '' || $descriptionRaw === '-') ? null : $descriptionRaw,
                'quantity' => $quantity,
                'unit' => $unitRaw === '' ? 'pcs' : substr($unitRaw, 0, 255),
                'price' => is_numeric($priceRaw) ? round(max(0.0, (float) $priceRaw), 2) : null,
                'min_stock' => $minStock,
                'status' => StockStatus::fromQuantity($quantity, $minStock)->value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (!empty($recordsToInsert)) {
            $this->stockRepository->insertMany($recordsToInsert);
        }

        return [
            'imported' => count($recordsToInsert),
            'errors' => $errors,
        ];
    }
}
