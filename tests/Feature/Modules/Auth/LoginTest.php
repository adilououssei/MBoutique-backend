<?php

namespace Tests\Feature\Modules\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_login_with_correct_credentials(): void
    {
        User::factory()->create(['email' => 'aicha@example.com', 'password' => Hash::make('password123')]);

        $response = $this->postJson('/api/auth/connexion', [
            'email' => 'aicha@example.com',
            'mot_de_passe' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('succes', true)
            ->assertJsonStructure(['donnees' => ['utilisateur', 'jeton']]);
    }

    public function test_login_fails_with_a_wrong_password(): void
    {
        User::factory()->create(['email' => 'aicha@example.com', 'password' => Hash::make('password123')]);

        $response = $this->postJson('/api/auth/connexion', [
            'email' => 'aicha@example.com',
            'mot_de_passe' => 'wrong-password',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email', 'erreurs');
    }

    public function test_login_fails_for_an_unknown_email(): void
    {
        $response = $this->postJson('/api/auth/connexion', [
            'email' => 'ghost@example.com',
            'mot_de_passe' => 'password123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email', 'erreurs');
    }

    public function test_an_inactive_user_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'aicha@example.com',
            'password' => Hash::make('password123'),
            'statut' => UserStatus::Inactive,
        ]);

        $response = $this->postJson('/api/auth/connexion', [
            'email' => 'aicha@example.com',
            'mot_de_passe' => 'password123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email', 'erreurs');
    }

    public function test_login_is_rate_limited_after_too_many_attempts(): void
    {
        User::factory()->create(['email' => 'aicha@example.com', 'password' => Hash::make('password123')]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/connexion', [
                'email' => 'aicha@example.com',
                'mot_de_passe' => 'wrong-password',
            ])->assertStatus(422);
        }

        $response = $this->postJson('/api/auth/connexion', [
            'email' => 'aicha@example.com',
            'mot_de_passe' => 'wrong-password',
        ]);

        $response->assertStatus(429)->assertJsonPath('code', 'TROP_DE_REQUETES');
    }
}
