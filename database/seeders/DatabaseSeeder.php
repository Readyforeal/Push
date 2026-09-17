<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'email_verified_at' => now(),
                'is_admin' => true,
                'password' => 'password',
            ],
        );

        User::query()->firstOrCreate(
            ['email' => 'partner@example.com'],
            [
                'name' => 'Taylor Partner',
                'email_verified_at' => now(),
                'password' => 'password',
            ],
        );

        $this->call(PromptTemplateSeeder::class);
    }
}
