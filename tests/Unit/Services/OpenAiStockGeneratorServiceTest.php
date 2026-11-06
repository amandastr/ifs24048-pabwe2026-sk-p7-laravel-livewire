<?php

namespace Tests\Unit\Services;

use App\Features\Ai\Services\OpenAiStockGeneratorService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class OpenAiStockGeneratorServiceTest extends TestCase
{
    private function service(): OpenAiStockGeneratorService
    {
        return new OpenAiStockGeneratorService('https://api.openai.com/v1', 'mock-key', 'gpt-4o-mini');
    }

    public function test_empty_prompt_throws_exception(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Prompt tidak boleh kosong.');
        $this->service()->generateStocks('   ');
    }

    public function test_successful_generation(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'items' => [
                                    [
                                        'name' => 'Kertas HVS A4',
                                        'sku' => 'ATK-001',
                                        'description' => 'Kertas 80gsm',
                                        'quantity' => 40,
                                        'unit' => 'rim',
                                        'price' => 52000,
                                        'min_stock' => 10,
                                    ],
                                    [
                                        'name' => 'Spidol',
                                        'sku' => '',
                                        'description' => null,
                                        'quantity' => 'banyak',
                                        'unit' => '',
                                        'price' => 'gratis',
                                        'min_stock' => 'x',
                                    ],
                                    [
                                        'name' => 'Penghapus',
                                        'quantity' => -3,
                                        'price' => -10,
                                        'min_stock' => -2,
                                    ],
                                ],
                            ]),
                        ],
                    ],
                ],
            ], 200),
        ]);

        $results = $this->service()->generateStocks('Buatkan 3 item ATK', 3);

        $this->assertCount(3, $results);

        $this->assertEquals('Kertas HVS A4', $results[0]->name);
        $this->assertEquals('ATK-001', $results[0]->sku);
        $this->assertEquals('Kertas 80gsm', $results[0]->description);
        $this->assertSame(40, $results[0]->quantity);
        $this->assertEquals('rim', $results[0]->unit);
        $this->assertSame(52000.0, $results[0]->price);
        $this->assertSame(10, $results[0]->minStock);

        // Invalid / missing values fall back to safe defaults
        $this->assertEquals('Spidol', $results[1]->name);
        $this->assertNull($results[1]->sku);
        $this->assertNull($results[1]->description);
        $this->assertSame(0, $results[1]->quantity);
        $this->assertEquals('pcs', $results[1]->unit);
        $this->assertNull($results[1]->price);
        $this->assertSame(5, $results[1]->minStock);

        // Negative numbers are clamped to zero
        $this->assertSame(0, $results[2]->quantity);
        $this->assertSame(0.0, $results[2]->price);
        $this->assertSame(0, $results[2]->minStock);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($body['messages'][0]['content'], 'inventory')
                && $body['messages'][1]['content'] === 'Buatkan 3 item ATK'
                && $request->hasHeader('Authorization', 'Bearer mock-key');
        });
    }

    public function test_api_failure_throws_exception(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'error' => [
                    'message' => 'Invalid API Key provided',
                ],
            ], 401),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAI API Error: Invalid API Key provided');
        $this->service()->generateStocks('Daftar barang gudang');
    }

    public function test_api_failure_without_error_message_uses_status_code(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response('Server error', 500),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenAI API Error: Request failed with status code 500');
        $this->service()->generateStocks('Daftar barang gudang');
    }

    public function test_parse_content_with_markdown_fences(): void
    {
        $raw = "```json\n[{\"name\": \"Code Fence Item\", \"description\": \"Test desc\"}]\n```";

        $results = $this->service()->parseContent($raw);
        $this->assertCount(1, $results);
        $this->assertEquals('Code Fence Item', $results[0]->name);
        $this->assertEquals('Test desc', $results[0]->description);
    }

    public function test_parse_content_invalid_json(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AI mengembalikan format respon yang tidak valid.');
        $this->service()->parseContent('Not valid JSON');
    }

    public function test_parse_content_empty_items(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tidak ada item stok yang berhasil di-generate dari AI.');
        $this->service()->parseContent('{"items": []}');
    }

    public function test_parse_content_without_items_key_or_list(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tidak ada item stok yang berhasil di-generate dari AI.');
        $this->service()->parseContent('{"foo": "bar"}');
    }

    public function test_parse_content_no_valid_items(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Format item stok yang dikembalikan AI tidak sesuai spesifikasi.');
        $this->service()->parseContent('{"items": [{"bad_key": "val"}, "text"]}');
    }

    public function test_constructor_handles_swapped_endpoint_and_key(): void
    {
        Http::fake([
            'https://ai.example.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '{"items":[{"name":"Barang"}]}']]],
            ]),
        ]);

        // Key given as URL, endpoint given as secret
        $service = new OpenAiStockGeneratorService('sk-secret-key', 'https://ai.example.com/v1');
        $items = $service->generateStocks('Daftar barang');
        $this->assertCount(1, $items);
    }

    public function test_constructor_auto_prefixes_https(): void
    {
        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '{"items":[{"name":"Barang"}]}']]],
            ]),
        ]);

        $service = new OpenAiStockGeneratorService('api.openai.com/v1', 'mock-key');
        $items = $service->generateStocks('Daftar barang');
        $this->assertCount(1, $items);
    }
}
