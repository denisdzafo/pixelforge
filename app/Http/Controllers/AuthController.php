<?php

namespace App\Http\Controllers;

use App\Http\Requests\AuthenticatedRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Services\Auth\AuthService;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService)
    {
    }

    public function register(RegisterRequest $request)
    {
        try {
            $session = $this->authService->register($request->all());
        } catch (\Exception $e) {
            return $this->exceptionResponse($e);
        }

        return $this->sessionResponse($session);
    }

    public function login(LoginRequest $request)
    {
        try {
            $session = $this->authService->login($request->all());
        } catch (\Exception $e) {
            return $this->exceptionResponse($e);
        }

        return $this->sessionResponse($session);
    }

    // No Form Request: the only input is the cookie
    public function refresh(Request $request)
    {
        try {
            $session = $this->authService->refresh($request->cookie(config('jwt.refresh_cookie.name')));
        } catch (\Exception $e) {
            return $this->exceptionResponse($e)->withCookie($this->refreshCookie('', -1));
        }

        return $this->sessionResponse($session);
    }

    public function me(AuthenticatedRequest $request)
    {
        return response()->json(['data' => $request->getAuthUser()]);
    }

    public function logout(AuthenticatedRequest $request)
    {
        try {
            $this->authService->logout($request->cookie(config('jwt.refresh_cookie.name')), $request->getAccessTokenClaims());
        } catch (\Exception $e) {
            return $this->exceptionResponse($e);
        }

        return response()->json(['data' => true])->withCookie($this->refreshCookie('', -1));
    }

    private function sessionResponse($session)
    {
        $response = response()->json([
            'data' => [
                'user' => $session['user'],
                'access_token' => $session['access_token'],
                'expires_in' => $session['expires_in'],
            ],
        ]);

        if ($session['refresh_token']) {
            $response->withCookie($this->refreshCookie($session['refresh_token'], config('jwt.refresh_ttl_days') * 1440));
        }

        return $response;
    }

    private function refreshCookie($value, $minutes)
    {
        $cookie = config('jwt.refresh_cookie');

        return cookie($cookie['name'], $value, $minutes, $cookie['path'], $cookie['domain'], $cookie['secure'], true, false, $cookie['same_site']);
    }
}
