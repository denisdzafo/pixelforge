<?php

namespace App\Http\Middleware;

use App\Repository\UserRepository;
use App\Services\Auth\JwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Endpoints of a signed-in user: a valid access token (Authorization: Bearer) of a user that still exists, 401 otherwise.
 * The model is put on the request (`userModel`, read with BaseFormRequest::getAuthUser()), so controllers don't look it up
 * again. The frontend answers a 401 by refreshing the session once (httpCommon.ts).
 */
class JwtAuthMiddleware
{
    public function __construct(private readonly JwtService $jwtService,
                                private readonly UserRepository $userRepository)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (!$token) {
            return $this->unauthorized();
        }

        try {
            $claims = $this->jwtService->decodeAccessToken($token);
        } catch (\Exception $e) {
            // The reason (expired, bad signature, ...) goes to the log only
            Log::info('Invalid access token', ['path' => $request->path(), 'error' => $e->getMessage()]);

            return $this->unauthorized();
        }

        $user = $this->userRepository->getUserById($claims->sub);

        if (!$user) {
            return $this->unauthorized();
        }

        $request->attributes->set('userModel', $user);
        $request->attributes->set('accessTokenClaims', $claims);

        return $next($request);
    }

    private function unauthorized()
    {
        return response()->json(['error' => 'Your session has expired. Please sign in again.'], 401);
    }
}
