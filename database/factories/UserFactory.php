<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state. The password is "correct-horse" unless a test says otherwise.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => fake()->unique()->userName(),
            'name' => fake()->name(),
            'password' => 'correct-horse',
            'active' => true,
            'auth_source' => User::AUTH_LOCAL,
            'role' => 'admin',   // most tests need to do everything
            'must_change_password' => false,
        ];
    }

    public function viewer(): static
    {
        return $this->state(fn () => ['role' => 'viewer']);
    }

    public function editor(): static
    {
        return $this->state(fn () => ['role' => 'editor']);
    }

    public function mustChangePassword(): static
    {
        return $this->state(fn () => ['must_change_password' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
