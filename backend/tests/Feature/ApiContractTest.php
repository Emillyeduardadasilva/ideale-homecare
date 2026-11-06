<?php

namespace Tests\Feature;

use App\Models\CareMembership;
use App\Models\Elder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_errors_expose_a_stable_code_and_request_identifier(): void
    {
        $response = $this->getJson('/api/v1/elders')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
        $this->assertSame($response->headers->get('X-Request-ID'), $response->json('request_id'));
        $this->getJson('/api/v1/nonexistent')->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        $this->actingAs(User::factory()->create())->postJson('/api/v1/elders', [])->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')->assertJsonValidationErrors(['name', 'birth_date', 'timezone']);
    }

    public function test_malformed_json_is_rejected_without_mutation(): void
    {
        $this->actingAs(User::factory()->create());
        $this->call('POST', '/api/v1/elders', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{"name":')
            ->assertStatus(400)->assertJsonPath('error.code', 'BAD_REQUEST');
        $this->assertDatabaseCount('elders', 0);
    }

    public function test_server_errors_never_return_sensitive_exception_details_even_in_debug(): void
    {
        config(['app.debug' => true]);
        Route::get('/api/v1/test-error', fn () => throw new \RuntimeException('PRIVATE_CLINICAL_CONTENT'));
        $response = $this->getJson('/api/v1/test-error')->assertStatus(500)->assertJsonPath('error.code', 'INTERNAL_ERROR');
        $this->assertStringNotContainsString('PRIVATE_CLINICAL_CONTENT', $response->getContent());
        $this->assertArrayNotHasKey('trace', $response->json());
    }

    public function test_authenticated_health_data_responses_cannot_be_cached(): void
    {
        $this->actingAs(User::factory()->create());
        $response = $this->getJson('/api/v1/elders')->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_javascript_iso_timestamps_are_accepted_but_impossible_dates_are_rejected(): void
    {
        $user = User::factory()->create();
        $elder = Elder::create(['name' => 'Pessoa', 'birth_date' => '1940-01-01', 'created_by' => $user->id]);
        CareMembership::create(['elder_id' => $elder->id, 'user_id' => $user->id, 'role' => 'manager']);
        $body = ['title' => 'Atividade', 'due_at' => now()->addHour()->format('Y-m-d\TH:i:s.v\Z'), 'assignees' => [$user->id]];
        $this->actingAs($user)->postJson("/api/v1/elders/$elder->id/tasks", $body)->assertCreated();
        $this->postJson("/api/v1/elders/$elder->id/tasks", [...$body, 'due_at' => '2099-02-30T10:00:00Z'])->assertUnprocessable()->assertJsonValidationErrors('due_at');
        $this->assertDatabaseCount('tasks', 1);
    }
}
