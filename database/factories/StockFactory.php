<?php

namespace Database\Factories;

use App\Features\Stock\Models\Stock;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Stock>
 */
class StockFactory extends Factory
{
    protected $model = Stock::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(3, true),
            'sku' => strtoupper(fake()->bothify('SKU-####-??')),
            'description' => fake()->paragraph(),
            'quantity' => 50,
            'unit' => 'pcs',
            'price' => fake()->randomFloat(2, 1000, 500000),
            'min_stock' => 5,
            'status' => 'available',
        ];
    }

    /**
     * Indicate that the stock is running low.
     */
    public function lowStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity' => 3,
            'status' => 'low_stock',
        ]);
    }

    /**
     * Indicate that the stock is out of stock.
     */
    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity' => 0,
            'status' => 'out_of_stock',
        ]);
    }
}
