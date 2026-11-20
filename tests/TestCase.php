<?php

namespace Tests;

use App\Models\User;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function createUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => 'correct-password',
        ], $attributes));
    }

    /**
     * Headers of a signed-in request (routes behind jwtAuth)
     */
    protected function authHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer ' . app(JwtService::class)->issueAccessToken($user)['token']];
    }
}
