<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * A sign-in for local development only: never created outside APP_ENV=local.
     */
    public function run(): void
    {
        if (!app()->isLocal()) {
            return;
        }

        User::firstOrCreate(['email' => 'demo@example.com'], ['name' => 'Demo User', 'password' => 'password']);
    }
}
