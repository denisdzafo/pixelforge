<?php

namespace App\Services\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Access tokens: short-lived HS256 JWTs (firebase/php-jwt), verified without a database table. Logging out denies the
 * token's `jti` in the cache until it would have expired anyway.
 */
class JwtService
{
    private const ALGORITHM = 'HS256';

    /**
     * {token, expires_in (seconds)}
     */
    public function issueAccessToken($user)
    {
        $now = time();
        $ttl = config('jwt.access_ttl_seconds');

        $token = JWT::encode([
            'iss' => config('jwt.issuer'),
            'aud' => config('jwt.audience'),
            'sub' => (string) $user->id,
            'jti' => (string) Str::uuid(),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
        ], config('jwt.secret'), self::ALGORITHM);

        return ['token' => $token, 'expires_in' => $ttl];
    }

    /**
     * The verified claims. Throws on a bad signature, an expired token or any wrong claim; the reason is for the log only.
     */
    public function decodeAccessToken($token)
    {
        JWT::$leeway = config('jwt.leeway_seconds');

        $claims = JWT::decode($token, new Key(config('jwt.secret'), self::ALGORITHM));

        foreach (['iss', 'aud', 'sub', 'jti', 'exp'] as $claim) {
            if (!isset($claims->$claim)) {
                throw new \UnexpectedValueException('Missing claim: ' . $claim);
            }
        }

        if ($claims->iss !== config('jwt.issuer') || $claims->aud !== config('jwt.audience')) {
            throw new \UnexpectedValueException('Token was issued for another app');
        }

        if (Cache::has($this->denyKey($claims->jti))) {
            throw new \UnexpectedValueException('Token was revoked');
        }

        return $claims;
    }

    /**
     * Logout: this access token stops working now instead of when it expires
     */
    public function denyAccessToken($claims)
    {
        $seconds = $claims->exp + config('jwt.leeway_seconds') - time();

        if ($seconds > 0) {
            Cache::put($this->denyKey($claims->jti), true, $seconds);
        }
    }

    private function denyKey($jti)
    {
        return "jwt_denied_{$jti}";
    }
}
