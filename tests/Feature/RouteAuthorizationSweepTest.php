<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MeetingRoster;
use App\Services\Roster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Every staff route, called with real ids by a guest and by an aslab of another offering, must deny and change nothing. */
class RouteAuthorizationSweepTest extends TestCase
{
    use RefreshDatabase;

    private function world(): array
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $outsider = User::factory()->create(['role' => 'aslab', 'active' => true]);
        $sem = DB::table('semesters')->insertGetId(['code' => 'SW', 'label' => 'Sweep — Data contoh', 'status' => 'active']);
        $p = DB::table('practicums')->insertGetId(['code' => 'SW', 'name' => 'Sweep — Data contoh']);
        $o = DB::table('practicum_offerings')->insertGetId(['semester_id' => $sem, 'practicum_id' => $p, 'status' => 'active']);
        $p2 = DB::table('practicums')->insertGetId(['code' => 'SW2', 'name' => 'Lain — Data contoh']);
        $other = DB::table('practicum_offerings')->insertGetId(['semester_id' => $sem, 'practicum_id' => $p2, 'status' => 'active']);
        DB::table('staff_assignments')->insert(['user_id' => $outsider->id, 'offering_id' => $other, 'all_sessions' => true]);
        $s = DB::table('practicum_sessions')->insertGetId(['offering_id' => $o, 'label' => 'Sesi Rahasia', 'capacity' => 10]);
        $this->actingAs($admin);
        $e = DB::transaction(fn () => app(Roster::class)->add(['nbi' => '0099001', 'name' => 'Praktikan Rahasia', 'sim_class' => 'A', 'class_category' => 'Pagi', 'session_id' => $s, 'valid_from' => '2026-01-01'], $o));
        $m = DB::table('meetings')->insertGetId(['offering_id' => $o, 'number' => 1, 'title' => 'Pertemuan']);
        $sm = DB::table('session_meetings')->insertGetId(['offering_id' => $o, 'session_id' => $s, 'meeting_id' => $m, 'starts_at' => now()->subDay(), 'ends_at' => now()->subDay()->addHours(2), 'room' => 'Lab']);
        app(MeetingRoster::class)->snapshot($admin, $o, $sm, 1);
        $file = (string) Str::uuid();
        DB::table('files')->insert(['id' => $file, 'storage_path' => 'academic/'.$file, 'original_name' => 'rahasia.pdf', 'mime' => 'application/pdf', 'size' => 4, 'uploaded_by' => $admin->id, 'checksum' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]);
        Storage::disk('local')->put('academic/'.$file, 'data');
        $material = DB::table('materials')->insertGetId(['meeting_id' => $m, 'title' => 'Draf rahasia', 'file_id' => $file]);
        $doc = DB::table('attendance_documents')->insertGetId(['session_meeting_id' => $sm, 'file_id' => $file, 'uploaded_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $a = DB::table('assignments')->insertGetId(['offering_id' => $o, 'origin_meeting_id' => $m, 'type' => 'custom', 'mode' => 'digital', 'title' => 'Tugas']);
        $sc = DB::table('assignment_schedules')->insertGetId(['offering_id' => $o, 'assignment_id' => $a, 'session_id' => $s, 'opens_at' => now()->subDay(), 'due_at' => now()->addDay(), 'closes_at' => now()->addDays(2)]);
        $sub = DB::table('submissions')->insertGetId(['offering_id' => $o, 'assignment_id' => $a, 'enrollment_id' => $e, 'schedule_id' => $sc, 'mode' => 'digital', 'status' => 'submitted', 'received_at' => now(), 'recorded_at' => now(), 'receiver_id' => $admin->id]);
        $version = DB::table('submission_versions')->insertGetId(['submission_id' => $sub, 'version_number' => 1, 'file_id' => $file, 'submitted_at' => now(), 'recorded_at' => now(), 'recorded_by' => $admin->id]);
        $rule = DB::table('grading_rules')->insertGetId(['offering_id' => $o, 'version' => 1, 'config_json' => json_encode(['components' => []]), 'status' => 'draft', 'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $request = DB::table('academic_requests')->insertGetId(['offering_id' => $o, 'enrollment_id' => $e, 'type' => 'izin', 'source_session_id' => $s, 'source_execution_id' => $sm, 'reason' => 'Rahasia', 'token_hash' => str_repeat('d', 64), 'evidence_file_id' => $file]);
        $delivery = DB::table('public_deliveries')->insertGetId(['offering_id' => $o, 'enrollment_id' => $e, 'assignment_id' => $a, 'schedule_id' => $sc, 'file_id' => $file, 'token_hash' => str_repeat('e', 64), 'received_at' => now()]);
        $preview = (string) Str::uuid();
        auth()->logout();
        $participant = DB::table('meeting_participants')->where('session_meeting_id', $sm)->value('id');

        return ['outsider' => $outsider, 'offering' => $o, 'session' => $s, 'id' => null, 'enrollment' => $e, 'meeting' => $m, 'execution' => $sm, 'material' => $material, 'document' => $doc, 'assignment' => $a, 'version' => $version, 'rule' => $rule, 'participant' => $participant, 'token' => $preview,
            'ids' => ['sesi' => $s, 'praktikan' => $e, 'pertemuan' => $m, 'pelaksanaan' => $sm, 'tugas' => $a, 'pengajuan' => $request, 'kiriman-digital' => $delivery]];
    }

    private function snapshotCounts(): array
    {
        $counts = [];
        foreach (Schema::getTableListing() as $table) {
            $name = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
            if (! in_array($name, ['sessions', 'cache', 'cache_locks', 'migrations'], true)) {
                $counts[$name] = DB::table($name)->count().'|'.md5(json_encode(DB::table($name)->get()));
            }
        }

        return $counts;
    }

    private function urls(array $w): array
    {
        $out = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'praktikum/{offering}')) {
                continue;
            }
            $url = preg_replace_callback('/\{(\w+)\}/', function ($m) use ($w, $uri) {
                if ($m[1] === 'id') {
                    $segment = explode('/', $uri)[2];

                    return $w['ids'][$segment] ?? 1;
                }

                return $w[$m[1]];
            }, $uri);
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $out[] = [$method, '/'.$url];
            }
        }

        return $out;
    }

    public function test_guest_and_other_offering_aslab_are_denied_everywhere_without_side_effects(): void
    {
        $w = $this->world();
        $routes = $this->urls($w);
        $this->assertGreaterThan(60, count($routes));
        $before = $this->snapshotCounts();
        $leaks = [];
        foreach ($routes as [$method, $url]) {
            $guest = $this->call($method, $url, ['_token' => csrf_token()]);
            if (! $guest->isRedirect(url('/login'))) {
                $leaks[] = "guest $method $url → ".$guest->getStatusCode();
            }
        }
        $this->actingAs($w['outsider']);
        $payload = ['version' => 1, 'confirmed' => 1, 'reason' => 'Percobaan akses', 'decision' => 'approved', 'selected' => [$w['participant']], 'action' => 'save', 'label' => 'X', 'title' => 'X', 'number' => 9, 'status' => 'received'];
        foreach ($routes as [$method, $url]) {
            $response = $this->call($method, $url, $payload);
            $status = $response->getStatusCode();
            $body = (string) $response->getContent();
            $redirectBack = $status === 302 && ! $response->isRedirect(url('/login'));
            if (! in_array($status, [403, 404], true) || str_contains($body, 'Praktikan Rahasia') || str_contains($body, 'Sesi Rahasia') || str_contains($body, 'rahasia.pdf')) {
                $leaks[] = "outsider $method $url → $status".($redirectBack ? ' (validation ran before authorization)' : '');
            }
        }
        $this->assertSame([], $leaks, implode("\n", $leaks));
        $this->assertSame($before, $this->snapshotCounts(), 'No table may change during denied requests.');
    }
}
