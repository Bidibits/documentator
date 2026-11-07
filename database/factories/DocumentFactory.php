<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A document row on its own (no version or files): enough to test codes and lists.
 *
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    public function definition(): array
    {
        $user = User::query()->first() ?? User::factory()->create();

        return [
            'code' => fake()->unique()->bothify('DOC-####'),
            'title' => fake()->sentence(3),
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ];
    }
}
