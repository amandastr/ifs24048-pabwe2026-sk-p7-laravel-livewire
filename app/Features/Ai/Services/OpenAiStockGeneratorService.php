<?php

namespace App\Features\Ai\Services;

use App\Features\Ai\Contracts\AiClientInterface;
use App\Features\Ai\DTO\GeneratedStockItem;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiStockGeneratorService implements AiClientInterface
{
    protected string $endpoint;
    protected string $apiKey;
    protected string $model;

    public function __construct(
        ?string $endpoint = null,
        ?string $apiKey = null,
        ?string $model = null,
    ) {
        $resolvedEndpoint = (string) ($endpoint ?? config('openai.endpoint', 'https://api.openai.com/v1'));
        $resolvedKey = (string) ($apiKey ?? config('openai.key', ''));

        // Toleransi jika nilai OPENAI_ENDPOINT dan OPENAI_KEY tertukar di .env
        if ((str_starts_with($resolvedKey, 'http://') || str_starts_with($resolvedKey, 'https://')) &&
            (!str_starts_with($resolvedEndpoint, 'http://') && !str_starts_with($resolvedEndpoint, 'https://'))) {
            $temp = $resolvedEndpoint;
            $resolvedEndpoint = $resolvedKey;
            $resolvedKey = $temp;
        }

        // Pastikan scheme http:// atau https:// selalu ada
        if (!empty($resolvedEndpoint) && !str_starts_with($resolvedEndpoint, 'http://') && !str_starts_with($resolvedEndpoint, 'https://')) {
            $resolvedEndpoint = 'https://' . $resolvedEndpoint;
        }

        $this->endpoint = rtrim($resolvedEndpoint, '/');
        $this->apiKey = $resolvedKey;
        $this->model = $model ?? (string) config('openai.model', 'gpt-4o-mini');
    }

    /**
     * Generate multiple structured inventory items based on a user prompt.
     *
     * @return array<int, GeneratedStockItem>
     *
     * @throws RuntimeException
     */
    public function generateStocks(string $prompt, int $count = 3): array
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new RuntimeException('Prompt tidak boleh kosong.');
        }

        $count = max(1, $count);

        $systemPrompt = <<<SYS
You are an expert inventory management assistant. Your task is to generate exactly {$count} realistic and structured inventory (stock) items based on the user's request.

You MUST respond strictly with a valid JSON object matching this schema:
{
  "items": [
    {
      "name": "Clear concise item name (max 100 chars)",
      "sku": "Short unique SKU code such as ATK-001 or null",
      "description": "Brief helpful details about the item (Markdown allowed)",
      "quantity": 0,
      "unit": "pcs",
      "price": 0,
      "min_stock": 5
    }
  ]
}

"quantity" and "min_stock" are non-negative integers, "price" is a number in Indonesian Rupiah or null.
Do not include any conversational text, introductory words, or explanations outside the JSON.
SYS;

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json',
        ])->timeout(30)->post("{$this->endpoint}/chat/completions", [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.7,
        ]);

        if (!$response->successful()) {
            $errorMessage = $response->json('error.message') ?? "Request failed with status code {$response->status()}";
            throw new RuntimeException("OpenAI API Error: {$errorMessage}");
        }

        $rawContent = (string) $response->json('choices.0.message.content');
        return $this->parseContent($rawContent);
    }

    /**
     * Parse raw string content into an array of GeneratedStockItem.
     *
     * @return array<int, GeneratedStockItem>
     */
    public function parseContent(string $rawContent): array
    {
        $cleanContent = trim($rawContent);

        // Remove Markdown code fences if present
        if (str_starts_with($cleanContent, '```')) {
            $cleanContent = preg_replace('/^```(?:json)?\s*/i', '', $cleanContent);
            $cleanContent = preg_replace('/\s*```$/', '', $cleanContent);
            $cleanContent = trim((string) $cleanContent);
        }

        $decoded = json_decode($cleanContent, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('AI mengembalikan format respon yang tidak valid.');
        }

        // Support both {"items": [...]} and [...]
        $items = isset($decoded['items']) && is_array($decoded['items'])
            ? $decoded['items']
            : (array_is_list($decoded) ? $decoded : []);

        if (empty($items)) {
            throw new RuntimeException('Tidak ada item stok yang berhasil di-generate dari AI.');
        }

        $results = [];
        foreach ($items as $item) {
            if (!is_array($item) || empty($item['name'])) {
                continue;
            }

            $sku = isset($item['sku']) && trim((string) $item['sku']) !== ''
                ? trim((string) $item['sku'])
                : null;
            $description = isset($item['description']) && trim((string) $item['description']) !== ''
                ? trim((string) $item['description'])
                : null;
            $unit = isset($item['unit']) && trim((string) $item['unit']) !== ''
                ? trim((string) $item['unit'])
                : 'pcs';

            $results[] = new GeneratedStockItem(
                name: trim((string) $item['name']),
                sku: $sku,
                description: $description,
                quantity: is_numeric($item['quantity'] ?? null) ? max(0, (int) $item['quantity']) : 0,
                unit: $unit,
                price: is_numeric($item['price'] ?? null) ? max(0.0, (float) $item['price']) : null,
                minStock: is_numeric($item['min_stock'] ?? null) ? max(0, (int) $item['min_stock']) : 5,
            );
        }

        if (empty($results)) {
            throw new RuntimeException('Format item stok yang dikembalikan AI tidak sesuai spesifikasi.');
        }

        return $results;
    }
}
