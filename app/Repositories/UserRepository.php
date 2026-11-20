<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\User;
use Illuminate\Support\Collection;

class UserRepository
{
    public function findById(int $id): ?User
    {
        return User::query()->find($id);
    }

    public function findByEmail(string $email): ?User
    {
        return User::query()->where('email', $email)->first();
    }

    public function lockById(int $id): User
    {
        return User::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, User>
     */
    public function getByIds(array $ids): Collection
    {
        return User::query()->whereIn('id', $ids)->get(['id', 'name', 'email'])->keyBy('id');
    }

    public function create(string $name, string $email, string $password, bool $verified = false): User
    {
        $user = new User;
        $user->name = $name;
        $user->email = $email;
        $user->password = $password;
        $user->email_verified_at = $verified ? now() : null;
        $user->save();

        return $user;
    }

    public function updateProfile(User $user, string $name, string $email): User
    {
        $user->name = $name;
        if ($user->email !== $email) {
            $user->email = $email;
            $user->email_verified_at = null;
        }
        $user->save();

        return $user;
    }

    public function updatePassword(User $user, string $password): void
    {
        $user->password = $password;
        $user->save();
    }

    public function updateBalance(User $user, int $balanceNanos): void
    {
        $user->balance_nanos = $balanceNanos;
        $user->save();
    }

    public function setAdmin(User $user, bool $isAdmin): void
    {
        $user->is_admin = $isAdmin;
        $user->save();
    }

    public function delete(User $user): void
    {
        $user->delete();
    }
}
