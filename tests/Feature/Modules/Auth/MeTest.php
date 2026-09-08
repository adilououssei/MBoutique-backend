<?php

namespace Tests\Feature\Modules\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MeTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authenticated_user_can_fetch_their_profile(): void
    {
        $user = User::factory()->create(['email' => 'aicha@example.com']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(200)->assertJsonPath('data.email', 'aicha@example.com');
    }

    public function test_a_guest_cannot_fetch_a_profile(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    }
}
