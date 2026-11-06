<?php

namespace Tests\Feature;

use App\Models\CareMembership;
use App\Models\Elder;
use App\Models\Professional;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class MySqlConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase()
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Concorrência exige MySQL real.');
        }
    }

    public function test_concurrent_visits_for_same_professional_in_different_families_cannot_overlap(): void
    {
        $admin = User::factory()->create(['role' => 'ideale_admin']);
        $professionalUser = User::factory()->create(['role' => 'ideale_professional']);
        $p = Professional::create(['user_id' => $professionalUser->id, 'specialty' => 'Fisioterapia']);
        $elders = [];
        for ($i = 0; $i < 2; $i++) {
            $family = User::factory()->create();
            $e = Elder::create(['name' => 'Concorrência '.$i, 'birth_date' => '1940-01-01', 'timezone' => 'America/Cuiaba', 'created_by' => $family->id]);
            foreach ([$admin, $professionalUser] as $u) {
                CareMembership::create(['elder_id' => $e->id, 'user_id' => $u->id, 'role' => 'team']);
            }
            $elders[] = $e;
        }
        $barrier = storage_path('framework/testing-'.bin2hex(random_bytes(8)));
        $processes = [];
        $pipes = [];
        try {
            foreach ($elders as $i => $e) {
                $processes[$i] = proc_open([PHP_BINARY, base_path('tests/Support/reserve-visit.php'), (string) $admin->id, (string) $e->id, (string) $p->id, $barrier, (string) $i], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], base_path());
                $this->assertIsResource($processes[$i]);
            }
            $deadline = microtime(true) + 20;
            while ((! is_file($barrier.'.0.ready') || ! is_file($barrier.'.1.ready')) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($barrier.'.0.ready');
            $this->assertFileExists($barrier.'.1.ready');
            touch($barrier);
            $results = [];
            foreach ($processes as $i => $process) {
                $results[] = trim(stream_get_contents($pipes[$i][1]));
                $errors = stream_get_contents($pipes[$i][2]);
                fclose($pipes[$i][1]);
                fclose($pipes[$i][2]);
                $this->assertSame(0, proc_close($process), $errors);
                unset($processes[$i]);
            }
            sort($results);
            $this->assertSame(['201', '409'], $results);
            $this->assertSame(1, Visit::count());
        } finally {
            foreach ($processes as $process) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
            foreach ([$barrier, $barrier.'.0.ready', $barrier.'.1.ready'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
