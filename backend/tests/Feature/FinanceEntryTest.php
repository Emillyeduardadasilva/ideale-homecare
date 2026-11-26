<?php

namespace Tests\Feature;

use App\Models\FinanceEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_filter_update_and_delete_own_finance_entries(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($owner);

        $response = $this->postJson('/api/v1/finance/entries', [
            'description' => 'Pagamento da cuidadora',
            'amount' => 185.50,
            'type' => 'expense',
            'category' => 'Pagamento de cuidador',
            'due_date' => '2026-11-20',
            'status' => 'pending',
        ])->assertCreated()->assertJsonPath('data.description', 'Pagamento da cuidadora')
            ->assertJsonPath('data.amount', '185.50');

        $entryId = $response->json('data.id');
        FinanceEntry::create([
            'user_id' => $owner->id,
            'description' => 'Receita do atendimento',
            'amount' => 300,
            'type' => 'income',
            'category' => 'Atendimento domiciliar',
            'due_date' => '2026-11-22',
            'status' => 'paid',
        ]);

        $this->getJson('/api/v1/finance/entries?from=2026-11-01&to=2026-11-30&type=expense')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $entryId);

        $this->putJson("/api/v1/finance/entries/$entryId", ['status' => 'paid'])
            ->assertOk()->assertJsonPath('data.status', 'paid');
        $this->deleteJson("/api/v1/finance/entries/$entryId")->assertNoContent();
        $this->assertDatabaseMissing('finance_entries', ['id' => $entryId]);

        $this->actingAs($other)->getJson('/api/v1/finance/entries')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_finance_entries_cannot_be_read_or_changed_by_another_account(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $entry = FinanceEntry::create([
            'user_id' => $owner->id,
            'description' => 'Registro privado',
            'amount' => 99.90,
            'type' => 'expense',
            'category' => 'Outros',
            'status' => 'pending',
        ]);

        $this->actingAs($other)->putJson("/api/v1/finance/entries/$entry->id", ['status' => 'paid'])->assertNotFound();
        $this->deleteJson("/api/v1/finance/entries/$entry->id")->assertNotFound();
        $this->assertDatabaseHas('finance_entries', ['id' => $entry->id, 'user_id' => $owner->id, 'status' => 'pending']);
    }

    public function test_finance_entry_rejects_invalid_values(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/v1/finance/entries', [
                'description' => '',
                'amount' => 0,
                'type' => 'transfer',
                'category' => 'Outros',
                'status' => 'overdue',
            ])->assertUnprocessable()->assertJsonValidationErrors(['description', 'amount', 'type', 'status']);
    }
}
