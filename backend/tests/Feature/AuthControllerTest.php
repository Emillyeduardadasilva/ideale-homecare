<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_update_sends_verification_when_email_changes(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/v1/auth/profile', [
            'name' => 'Nome atualizado',
            'phone' => '+5565999991234',
            'email' => 'novo@example.test',
        ])->assertOk()->assertJsonPath('data.name', 'Nome atualizado')
            ->assertJsonPath('data.email', 'novo@example.test')
            ->assertJsonPath('data.email_verified_at', null);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Nome atualizado',
            'phone' => '+5565999991234',
            'email' => 'novo@example.test',
            'email_verified_at' => null,
        ]);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_password_change_checks_the_current_password(): void
    {
        $user = User::factory()->create();
        $payload = [
            'current_password' => 'wrong-password',
            'password' => 'new-safe-password-9876',
            'password_confirmation' => 'new-safe-password-9876',
        ];

        $this->actingAs($user)->putJson('/api/v1/auth/password', $payload)->assertUnprocessable();
        $payload['current_password'] = 'password';
        $this->putJson('/api/v1/auth/password', $payload)->assertOk();
        $this->assertTrue(Hash::check('new-safe-password-9876', $user->fresh()->password));
    }

    public function test_password_reset_uses_a_single_use_token(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        Notification::assertSentTo($user, ResetPassword::class);
        $token = Notification::sent($user, ResetPassword::class)->first()->token;

        $payload = [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-reset-password-9876',
            'password_confirmation' => 'new-reset-password-9876',
        ];
        $this->postJson('/api/v1/auth/reset-password', $payload)->assertOk();
        $this->postJson('/api/v1/auth/reset-password', $payload)->assertUnprocessable();
        $this->assertTrue(Hash::check('new-reset-password-9876', $user->fresh()->password));
    }
}
