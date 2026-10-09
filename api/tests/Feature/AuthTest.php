<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The panel is a stateful SPA: real requests always carry the panel
     * Origin, which is what enables session handling.
     */
    private function stateful(): static
    {
        return $this->withHeader('Origin', 'http://localhost:5174');
    }

    public function test_login_returns_the_user_and_session_works(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'secret-password-123',
        ]);

        $this->stateful()->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'secret-password-123',
        ])
            ->assertOk()
            ->assertJsonPath('user.email', 'admin@example.com')
            ->assertJsonMissing(['password']);

        $this->assertAuthenticatedAs($user);

        $this->stateful()->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'admin@example.com');

        $this->stateful()->postJson('/api/v1/auth/logout')->assertOk();

        // O app de teste é reaproveitado entre requisições; limpa os guards
        // em cache para simular uma requisição HTTP totalmente nova.
        \Illuminate\Support\Facades\Auth::forgetGuards();

        $this->stateful()->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'secret-password-123',
        ]);

        $this->stateful()->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_without_a_session_is_rejected_clearly(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'secret-password-123',
        ])->assertStatus(419);
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'secret-password-123',
        ]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->stateful()->postJson('/api/v1/auth/login', [
                'email' => 'admin@example.com',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->stateful()->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_private_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
        $this->getJson('/api/v1/projects')->assertUnauthorized();
        $this->getJson('/api/v1/builds')->assertUnauthorized();
        $this->getJson('/api/v1/runners')->assertUnauthorized();
    }
}
