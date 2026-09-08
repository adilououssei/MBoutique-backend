<?php

namespace Tests\Feature\Modules\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/auth/logout');

        $response->assertStatus(200)->assertJsonPath('success', true);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_the_token_is_unusable_after_logout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/auth/logout');

        // The 'sanctum' guard instance memoizes the resolved user for its
        // own lifetime; nothing resets that between two calls within one
        // test method the way two real, separate HTTP requests would.
        // Forgetting the cached guard makes this call re-resolve the
        // (now-deleted) token for real, instead of reusing the cached
        // pre-logout resolution.
        $this->app->make('auth')->forgetGuards();

        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_a_guest_cannot_logout(): void
    {
        $this->postJson('/api/auth/logout')->assertStatus(401);
    }
}
