<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (!env('ADMIN_EMAIL') || !env('ADMIN_PASSWORD')) {
            return;
        }

        User::query()->updateOrCreate([
            'email' => env('ADMIN_EMAIL'),
        ], [
            'name' => env('ADMIN_NAME', 'Narabox Admin'),
            'password' => Hash::make((string) env('ADMIN_PASSWORD')),
            'is_admin' => true,
        ]);
    }
}
