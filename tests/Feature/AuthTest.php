<?php

namespace Tests\Feature;

use App\Jobs\PruneRefreshTokensJob;
use App\Models\RefreshToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-password';

    private function register(string $email = 'new@example.com'): TestResponse
    {
        return $this->postJson('/api/auth/register', [
            'name' => 'New User',
            'email' => $email,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ]);
    }

    private function login(): TestResponse
    {
        return $this->postJson('/api/auth/login', ['email' => 'user@example.com', 'password' => self::PASSWORD]);
    }

    private function refreshCookieOf(TestResponse $response): string
    {
        return $response->getCookie(config('jwt.refresh_cookie.name'), false)->getValue();
    }

    private function refreshWith(string $cookie): TestResponse
    {
        // withCredentials: JSON test requests leave cookies out otherwise
        return $this->withCredentials()->withUnencryptedCookie(config('jwt.refresh_cookie.name'), $cookie)->postJson('/api/auth/refresh');
    }

    public function test_register_returns_a_session_and_never_the_password()
    {
        $response = $this->register();

        $response->assertOk()
            ->assertJsonPath('data.user.email', 'new@example.com')
            ->assertJsonPath('data.expires_in', config('jwt.access_ttl_seconds'))
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.refresh_token');

        $cookie = $response->getCookie(config('jwt.refresh_cookie.name'), false);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('/api/auth', $cookie->getPath());
        $this->assertSame('strict', $cookie->getSameSite());
    }

    public function test_register_validates_and_rejects_a_taken_email_in_any_case()
    {
        $this->postJson('/api/auth/register', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password']);

        $this->register('taken@example.com')->assertOk();
        $this->register('TAKEN@example.com')->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_login_with_wrong_credentials_gives_the_same_answer_for_any_email()
    {
        $this->createUser();

        $wrongPassword = $this->postJson('/api/auth/login', ['email' => 'user@example.com', 'password' => 'wrong-password']);
        $unknownEmail = $this->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password']);

        $wrongPassword->assertStatus(401);
        $unknownEmail->assertStatus(401);
        $this->assertSame($wrongPassword->json('error'), $unknownEmail->json('error'));
    }

    public function test_protected_routes_need_a_valid_token()
    {
        $this->getJson('/api/auth/me')->assertStatus(401)->assertJsonStructure(['error']);
        $this->getJson('/api/auth/me', ['Authorization' => 'Bearer not-a-token'])->assertStatus(401);
    }

    public function test_login_gives_access_to_me()
    {
        $this->createUser();

        $token = $this->login()->assertOk()->json('data.access_token');

        $this->getJson('/api/auth/me', ['Authorization' => 'Bearer ' . $token])
            ->assertOk()
            ->assertJsonPath('data.email', 'user@example.com');
    }

    public function test_refresh_rotates_the_token()
    {
        $this->createUser();
        $first = $this->refreshCookieOf($this->login());

        $response = $this->refreshWith($first)->assertOk()->assertJsonStructure(['data' => ['user', 'access_token', 'expires_in']]);

        $this->assertNotSame($first, $this->refreshCookieOf($response));
        $this->assertSame(2, RefreshToken::count());
    }

    public function test_refresh_without_a_cookie_or_with_a_bad_one_is_rejected()
    {
        $this->postJson('/api/auth/refresh')->assertStatus(401);
        $this->refreshWith('not-a-token')->assertStatus(401);
    }

    public function test_an_expired_refresh_token_is_rejected()
    {
        $this->createUser();
        $cookie = $this->refreshCookieOf($this->login());

        $this->travel(config('jwt.refresh_ttl_days') + 1)->days();

        $this->refreshWith($cookie)->assertStatus(401);
    }

    public function test_two_tabs_refreshing_at_once_both_keep_the_session()
    {
        $this->createUser();
        $first = $this->refreshCookieOf($this->login());

        $this->refreshWith($first)->assertOk();

        // The second tab still sent the old cookie, within the grace time: an access token, the cookie stays as it is
        $second = $this->refreshWith($first)->assertOk();
        $this->assertNull($second->getCookie(config('jwt.refresh_cookie.name'), false));
        $this->assertSame(2, RefreshToken::count());
    }

    public function test_reusing_a_rotated_token_after_the_grace_time_ends_the_session()
    {
        $this->createUser();
        $first = $this->refreshCookieOf($this->login());
        $second = $this->refreshCookieOf($this->refreshWith($first));

        $this->travel(config('jwt.refresh_reuse_grace_seconds') + 1)->seconds();

        $this->refreshWith($first)->assertStatus(401);
        // The newest token of that session stops working too: whoever holds it may be the thief
        $this->refreshWith($second)->assertStatus(401);
    }

    public function test_logout_ends_the_access_token_and_the_refresh_token()
    {
        $this->createUser();
        $login = $this->login();
        $headers = ['Authorization' => 'Bearer ' . $login->json('data.access_token')];
        $cookie = $this->refreshCookieOf($login);

        $this->withCredentials()->withUnencryptedCookie(config('jwt.refresh_cookie.name'), $cookie)
            ->postJson('/api/auth/logout', [], $headers)
            ->assertOk();

        $this->getJson('/api/auth/me', $headers)->assertStatus(401);
        $this->refreshWith($cookie)->assertStatus(401);
    }

    public function test_the_auth_endpoints_are_rate_limited()
    {
        config(['rate_limits.auth_per_minute' => 2]);

        $this->postJson('/api/auth/login', [])->assertStatus(422);
        $this->postJson('/api/auth/login', [])->assertStatus(422);
        $this->postJson('/api/auth/login', [])->assertStatus(429)->assertJsonStructure(['error']);
    }

    public function test_unexpected_errors_never_show_technical_text()
    {
        $this->createUser();
        config(['jwt.secret' => 'too-short']);

        $this->login()->assertStatus(500)
            ->assertExactJson(['error' => 'Something went wrong on our side. Please try again in a few minutes.']);
    }

    public function test_the_cleanup_job_deletes_only_tokens_that_expired_a_while_ago()
    {
        $this->createUser();
        $this->login();

        $this->travel(config('jwt.refresh_ttl_days') + config('jwt.prune_after_days') + 1)->days();
        $this->login();

        (new PruneRefreshTokensJob())->handle(app(\App\Repository\RefreshTokenRepository::class));

        $this->assertSame(1, RefreshToken::count());
    }

    public function test_the_spa_shell_answers_deep_links_but_not_unknown_api_paths()
    {
        $this->withoutVite();

        $this->get('/any/deep/link')->assertOk()->assertViewIs('welcome');
        $this->getJson('/api/nothing-here')->assertStatus(404);
    }
}
