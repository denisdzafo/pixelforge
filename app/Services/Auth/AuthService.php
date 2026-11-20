<?php

namespace App\Services\Auth;

use App\Repository\RefreshTokenRepository;
use App\Repository\UserRepository;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Register, login, refresh, logout. Every method that starts or continues a session returns
 * {user, access_token, expires_in, refresh_token}; refresh_token is null when the cookie must stay as it is.
 */
class AuthService
{
    public function __construct(private readonly UserRepository $userRepository,
                                private readonly RefreshTokenRepository $refreshTokenRepository,
                                private readonly JwtService $jwtService)
    {
    }

    public function register($data)
    {
        $email = $this->normalizeEmail($data['email']);

        if ($this->userRepository->findByEmail($email)) {
            throw ValidationException::withMessages(['email' => 'This email address is already registered.']);
        }

        $user = $this->userRepository->createUser([
            'name' => $data['name'],
            'email' => $email,
            'password' => $data['password'],
        ]);

        Log::info('User registered', ['user_id' => $user->id]);

        return $this->buildSession($user, $this->issueRefreshToken($user->id, (string) Str::uuid()));
    }

    public function login($data)
    {
        $user = $this->userRepository->findByEmail($this->normalizeEmail($data['email']));

        if (!$user) {
            // Same work as a real check, so the response time doesn't reveal which emails exist
            Hash::make($data['password']);
            throw new \Exception('The email or password is incorrect.', 401);
        }

        if (!Hash::check($data['password'], $user->password)) {
            throw new \Exception('The email or password is incorrect.', 401);
        }

        return $this->buildSession($user, $this->issueRefreshToken($user->id, (string) Str::uuid()));
    }

    /**
     * A new access token and a rotated refresh token. A token that was already rotated is either a race between two tabs
     * (within the grace time: an access token, the browser already has the new cookie) or a stolen token (the session ends).
     */
    public function refresh($plainToken)
    {
        $refreshToken = $plainToken ? $this->refreshTokenRepository->findByHash($this->hashToken($plainToken)) : null;

        if (!$refreshToken || $refreshToken->revoked_at || $refreshToken->expires_at->isPast()) {
            throw new \Exception('Your session has expired. Please sign in again.', 401);
        }

        $user = $this->userRepository->getUserById($refreshToken->user_id);

        if (!$user) {
            throw new \Exception('Your session has expired. Please sign in again.', 401);
        }

        if ($this->refreshTokenRepository->claimForRotation($refreshToken)) {
            return $this->buildSession($user, $this->issueRefreshToken($user->id, $refreshToken->family));
        }

        // Rotated meanwhile: replaced_at is only on the row, not on the model read before the claim
        $replacedAt = $this->refreshTokenRepository->findByHash($refreshToken->token_hash)->replaced_at;

        if ($replacedAt && $replacedAt->diffInSeconds(now()) <= config('jwt.refresh_reuse_grace_seconds')) {
            return $this->buildSession($user, null);
        }

        $this->refreshTokenRepository->revokeFamily($refreshToken->family);
        Log::warning('Refresh token reused, session revoked', ['user_id' => $user->id, 'family' => $refreshToken->family]);

        throw new \Exception('Your session has expired. Please sign in again.', 401);
    }

    public function logout($plainToken, $claims)
    {
        $this->jwtService->denyAccessToken($claims);

        $refreshToken = $plainToken ? $this->refreshTokenRepository->findByHash($this->hashToken($plainToken)) : null;

        if ($refreshToken) {
            $this->refreshTokenRepository->revokeFamily($refreshToken->family);
        }
    }

    private function buildSession($user, $plainRefreshToken)
    {
        $accessToken = $this->jwtService->issueAccessToken($user);

        return [
            'user' => $user,
            'access_token' => $accessToken['token'],
            'expires_in' => $accessToken['expires_in'],
            'refresh_token' => $plainRefreshToken,
        ];
    }

    private function issueRefreshToken($userId, $family)
    {
        $plainToken = Str::random(64);

        $this->refreshTokenRepository->createRefreshToken([
            'user_id' => $userId,
            'family' => $family,
            'token_hash' => $this->hashToken($plainToken),
            'expires_at' => now()->addDays(config('jwt.refresh_ttl_days')),
        ]);

        return $plainToken;
    }

    private function hashToken($plainToken)
    {
        return hash('sha256', $plainToken);
    }

    private function normalizeEmail($email)
    {
        return Str::lower(trim($email));
    }
}
