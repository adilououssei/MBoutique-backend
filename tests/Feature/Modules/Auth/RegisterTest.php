<?php

namespace Tests\Feature\Modules\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_register(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Aïcha Koné',
            'email' => 'aicha@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'aicha@example.com')
            ->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email'], 'token']]);

        $this->assertDatabaseHas('users', ['email' => 'aicha@example.com']);
    }

    public function test_the_response_never_exposes_the_password(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Aïcha Koné',
            'email' => 'aicha@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.remember_token');
    }

    public function test_registration_requires_a_valid_email(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Aïcha Koné',
            'email' => 'not-an-email',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors('email');
    }

    public function test_registration_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'aicha@example.com']);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Aïcha Koné',
            'email' => 'aicha@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }
}
