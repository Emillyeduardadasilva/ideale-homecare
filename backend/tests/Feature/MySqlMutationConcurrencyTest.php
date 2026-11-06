<?php

namespace Tests\Feature;

use App\Models\CareMembership;
use App\Models\Elder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class MySqlMutationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase()
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Concorrência exige MySQL real.');
        }
    }

    public function test_two_family_members_complete_the_same_task_only_once(): void
    {
        $users = [User::factory()->create(), User::factory()->create()];
        $elder = Elder::create(['name' => 'Pessoa', 'birth_date' => '1940-01-01', 'created_by' => $users[0]->id]);
        foreach ($users as $index => $user) {
            CareMembership::create(['elder_id' => $elder->id, 'user_id' => $user->id, 'role' => $index ? 'relative' : 'manager']);
        }
        $task = Task::create(['elder_id' => $elder->id, 'created_by' => $users[0]->id, 'title' => 'Atividade compartilhada', 'due_at' => now()]);
        $task->assignees()->sync(array_map(fn ($user) => $user->id, $users));

        $this->assertSame(['200', '200'], $this->concurrent($users, "/api/v1/elders/$elder->id/tasks/$task->id/complete", []));
        $this->assertDatabaseCount('task_completions', 1);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'completed', 'version' => 2]);
    }

    public function test_concurrent_push_subscription_cannot_transfer_device_ownership(): void
    {
        $users = [User::factory()->create(), User::factory()->create()];
        $body = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/concurrent-synthetic-device', 'keys' => ['p256dh' => str_repeat('a', 87), 'auth' => str_repeat('b', 22)]];

        $this->assertSame(['201', '409'], $this->concurrent($users, '/api/v1/push/subscriptions', $body));
        $this->assertDatabaseCount('push_subscriptions', 1);
    }

    private function concurrent(array $users, string $path, array $body): array
    {
        $barrier = storage_path('framework/mutation-'.bin2hex(random_bytes(8)));
        $processes = [];
        $pipes = [];
        try {
            foreach ($users as $index => $user) {
                $processes[$index] = proc_open([PHP_BINARY, base_path('tests/Support/api-mutation.php'), (string) $user->id, $path, base64_encode(json_encode($body, JSON_THROW_ON_ERROR)), $barrier, (string) $index], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$index], base_path());
                $this->assertIsResource($processes[$index]);
            }
            $deadline = microtime(true) + 20;
            while ((! is_file($barrier.'.0.ready') || ! is_file($barrier.'.1.ready')) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($barrier.'.0.ready');
            $this->assertFileExists($barrier.'.1.ready');
            touch($barrier);
            $results = [];
            foreach ($processes as $index => $process) {
                $results[] = trim(stream_get_contents($pipes[$index][1]));
                $errors = stream_get_contents($pipes[$index][2]);
                fclose($pipes[$index][1]);
                fclose($pipes[$index][2]);
                $this->assertSame(0, proc_close($process), $errors);
                unset($processes[$index]);
            }
            sort($results);

            return $results;
        } finally {
            foreach ($processes as $process) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
            foreach ([$barrier, $barrier.'.0.ready', $barrier.'.1.ready'] as $pathToRemove) {
                if (is_file($pathToRemove)) {
                    unlink($pathToRemove);
                }
            }
        }
    }
}
