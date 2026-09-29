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
        $response = $this->postJson('/api/auth/inscription', [
            'nom' => 'Aïcha Koné',
            'email' => 'aicha@example.com',
            'mot_de_passe' => 'password123',
            'mot_de_passe_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('succes', true)
            ->assertJsonPath('donnees.utilisateur.email', 'aicha@example.com')
            ->assertJsonStructure(['donnees' => ['utilisateur' => ['id', 'nom', 'email'], 'jeton']]);

        $this->assertDatabaseHas('utilisateurs', ['email' => 'aicha@example.com']);
    }

    public function test_the_response_never_exposes_the_password(): void
    {
        $response = $this->postJson('/api/auth/inscription', [
            'nom' => 'Aïcha Koné',
            'email' => 'aicha@example.com',
            'mot_de_passe' => 'password123',
            'mot_de_passe_confirmation' => 'password123',
        ]);

        $response->assertJsonMissingPath('donnees.utilisateur.password')
            ->assertJsonMissingPath('donnees.utilisateur.remember_token');
    }

    public function test_registration_requires_a_valid_email(): void
    {
        $response = $this->postJson('/api/auth/inscription', [
            'nom' => 'Aïcha Koné',
            'email' => 'not-an-email',
            'mot_de_passe' => 'password123',
            'mot_de_passe_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('succes', false)
            ->assertJsonPath('code', 'VALIDATION_ECHOUEE')
            ->assertJsonValidationErrors('email', 'erreurs');
    }

    public function test_registration_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'aicha@example.com']);

        $response = $this->postJson('/api/auth/inscription', [
            'nom' => 'Aïcha Koné',
            'email' => 'aicha@example.com',
            'mot_de_passe' => 'password123',
            'mot_de_passe_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email', 'erreurs');
    }
}
