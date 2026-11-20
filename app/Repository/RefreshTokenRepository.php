<?php

namespace App\Repository;

use App\Models\RefreshToken;

class RefreshTokenRepository
{

    public function createRefreshToken($data)
    {
        $refreshToken = new RefreshToken();
        $refreshToken->user_id = $data['user_id'];
        $refreshToken->family = $data['family'];
        $refreshToken->token_hash = $data['token_hash'];
        $refreshToken->expires_at = $data['expires_at'];
        $refreshToken->save();

        return $refreshToken;
    }

    public function findByHash($tokenHash)
    {
        return RefreshToken::where('token_hash', $tokenHash)->first();
    }

    /**
     * Marks the token as rotated, only if nothing else did it first: true = this request owns the rotation
     */
    public function claimForRotation($refreshToken)
    {
        return RefreshToken::where('id', $refreshToken->id)
            ->whereNull('replaced_at')
            ->whereNull('revoked_at')
            ->update(['replaced_at' => now()]) === 1;
    }

    public function revokeFamily($family)
    {
        RefreshToken::where('family', $family)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function deleteExpiredBefore($date)
    {
        return RefreshToken::where('expires_at', '<', $date)->delete();
    }

}
