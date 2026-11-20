<?php

declare(strict_types=1);

namespace App\Services\Users;

use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\Credits\CreditLedger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

class UserService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly CreditLedger $ledger,
    ) {}

    public function register(string $name, string $email, string $password, bool $verified = false): User
    {
        $user = $this->users->create($name, $email, $password, $verified);
        $this->ledger->grantSignupBonus($user);

        Log::info('User registered', ['user_id' => $user->id]);

        return $user->refresh();
    }

    public function updateProfile(User $user, string $name, string $email): User
    {
        return $this->users->updateProfile($user, $name, $email);
    }

    public function changePassword(User $user, string $password): void
    {
        $this->users->updatePassword($user, $password);
    }

    public function deleteAccount(User $user): void
    {
        Log::info('User deleted their account', ['user_id' => $user->id]);
        $this->users->delete($user);
    }

    public function makeAdmin(string $email): User
    {
        $user = $this->users->findByEmail($email)
            ?? throw (new ModelNotFoundException)->setModel(User::class, [$email]);

        $this->users->setAdmin($user, true);
        Log::info('User made admin', ['user_id' => $user->id]);

        return $user;
    }
}
