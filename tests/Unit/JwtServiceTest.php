<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Auth\JwtService;
use Firebase\JWT\JWT;
use Tests\TestCase;

class JwtServiceTest extends TestCase
{
    private function user(): User
    {
        $user = new User();
        $user->id = 7;

        return $user;
    }

    public function test_an_issued_token_decodes_to_its_user()
    {
        $service = new JwtService();

        $claims = $service->decodeAccessToken($service->issueAccessToken($this->user())['token']);

        $this->assertSame('7', $claims->sub);
        $this->assertSame(config('jwt.issuer'), $claims->iss);
    }

    public function test_a_token_signed_with_another_secret_is_rejected()
    {
        $token = JWT::encode(['sub' => '7', 'exp' => time() + 60], 'another-secret-of-at-least-32-bytes-long', 'HS256');

        $this->expectException(\Exception::class);

        (new JwtService())->decodeAccessToken($token);
    }

    public function test_an_expired_token_is_rejected()
    {
        config(['jwt.access_ttl_seconds' => -60]);
        $service = new JwtService();

        $this->expectException(\Exception::class);

        $service->decodeAccessToken($service->issueAccessToken($this->user())['token']);
    }

    public function test_a_token_for_another_audience_is_rejected()
    {
        $service = new JwtService();
        $token = $service->issueAccessToken($this->user())['token'];
        config(['jwt.audience' => 'https://other-app.example']);

        $this->expectException(\UnexpectedValueException::class);

        $service->decodeAccessToken($token);
    }

    public function test_a_denied_token_is_rejected()
    {
        $service = new JwtService();
        $token = $service->issueAccessToken($this->user())['token'];
        $service->denyAccessToken($service->decodeAccessToken($token));

        $this->expectException(\UnexpectedValueException::class);

        $service->decodeAccessToken($token);
    }
}
