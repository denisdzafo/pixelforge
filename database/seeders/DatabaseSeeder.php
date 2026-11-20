<?php

namespace Database\Seeders;

use App\Repositories\UserRepository;
use App\Services\Credits\CreditLedger;
use App\Services\Users\UserService;
use App\Support\Money;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(UserRepository $repository, UserService $users, CreditLedger $ledger): void
    {
        if (app()->isProduction()) {
            return;
        }

        $demo = $repository->findByEmail('demo@example.com')
            ?? $users->register('Demo User', 'demo@example.com', 'password', verified: true);
        $ledger->purchase($demo, Money::fromUsd('10.00'), 'seed');

        $admin = $repository->findByEmail('admin@example.com')
            ?? $users->register('Admin', 'admin@example.com', 'password', verified: true);
        $users->makeAdmin($admin->email);
        $ledger->purchase($admin, Money::fromUsd('25.00'), 'seed');
    }
}
