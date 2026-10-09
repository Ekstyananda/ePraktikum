<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StaffAccess;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'aslab', bool $active = true): User
    {
        return User::factory()->create(['role' => $role, 'active' => $active, 'password' => 'SecurePassword123!'])->fresh();
    }

    private function offering(string $code = 'SBD'): int
    {
        $semester = DB::table('semesters')->insertGetId(['code' => $code, 'label' => 'Data contoh — semester pengujian']);
        $p = DB::table('practicums')->insertGetId(['code' => $code, 'name' => 'Data contoh — '.$code]);

        return DB::table('practicum_offerings')->insertGetId(['semester_id' => $semester, 'practicum_id' => $p]);
    }

    private function makeSession(int $offering, string $label = 'Sesi 1'): int
    {
        return DB::table('practicum_sessions')->insertGetId(['offering_id' => $offering, 'label' => $label]);
    }

    private function assign(User $u, int $o, bool $all = true): int
    {
        return DB::table('staff_assignments')->insertGetId(['user_id' => $u->id, 'offering_id' => $o, 'all_sessions' => $all]);
    }

    private function payload(User $u, array $assignments = []): array
    {
        return ['name' => $u->name, 'email' => $u->email, 'active' => 1, 'version' => $u->version, 'reason' => 'Penugasan praktikum baru', 'assignments' => $assignments];
    }

    public function test_public_has_no_student_login_or_roster(): void
    {
        $this->get('/')->assertOk()->assertSee('Portal praktikan tanpa akun')->assertDontSee('password');
        $this->get('/register')->assertNotFound();
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_admin_and_aslab_can_login_and_logout(): void
    {
        foreach (['admin', 'aslab'] as $role) {
            $u = $this->user($role);
            $this->post('/login', ['email' => $u->email, 'password' => 'SecurePassword123!'])->assertRedirect('/dashboard');
            $this->assertAuthenticatedAs($u);
            $this->get('/dashboard')->assertOk();
            $this->post('/logout')->assertRedirect('/login');
            $this->assertGuest();
        }
    }

    public function test_inactive_login_and_wrong_password_share_generic_error(): void
    {
        $u = $this->user(active: false);
        $this->post('/login', ['email' => $u->email, 'password' => 'SecurePassword123!'])->assertSessionHasErrors(['email' => 'Email atau kata sandi tidak sesuai.']);
        $this->assertGuest();
        $this->post('/login', ['email' => 'unknown@example.test', 'password' => 'Wrong123'])->assertSessionHasErrors(['email' => 'Email atau kata sandi tidak sesuai.']);
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'limited@example.test', 'password' => 'wrong'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => 'limited@example.test', 'password' => 'wrong'])->assertSessionHasErrors(['email' => 'Terlalu banyak percobaan. Coba kembali setelah satu menit.']);
    }

    public function test_inactive_authenticated_account_is_rejected(): void
    {
        $u = $this->user();
        $this->actingAs($u);
        $u->update(['active' => false]);
        $this->get('/dashboard')->assertForbidden();
        $this->assertGuest();
    }

    public function test_all_account_management_routes_are_admin_only(): void
    {
        $u = $this->user();
        $other = $this->user();
        $this->actingAs($u);
        foreach (['/pengaturan/aslab', '/pengaturan/aslab/baru', '/pengaturan/aslab/'.$other->id] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/pengaturan/aslab', ['name' => 'Illegal'])->assertForbidden();
        $this->put('/pengaturan/aslab/'.$other->id, $this->payload($other))->assertForbidden();
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_admin_creates_and_edits_aslab_via_clickable_name(): void
    {
        $admin = $this->user('admin');
        $this->offering();
        $this->actingAs($admin)->post('/pengaturan/aslab', ['name' => 'Aslab Uji', 'email' => 'uji@example.test', 'password' => 'SecurePassword123!', 'password_confirmation' => 'SecurePassword123!'])->assertRedirect();
        $u = User::where('email', 'uji@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('SecurePassword123!', $u->password));
        $this->get('/pengaturan/aslab')->assertSee('/pengaturan/aslab/'.$u->id)->assertSee('Aslab Uji');
        $this->get('/pengaturan/aslab/'.$u->id)->assertOk()->assertSee('Semua sesi', false);
        $this->assertDatabaseHas('activity_logs', ['action' => 'account.created']);
    }

    public function test_standard_grants_all_current_and_future_sessions_in_assigned_offering(): void
    {
        $u = $this->user();
        $o = $this->offering();
        $s = $this->makeSession($o);
        $this->assign($u, $o);
        $access = app(StaffAccess::class);
        $this->assertTrue($access->allowed($u, $o, 'attendance.manage', $s));
        $this->assertFalse($access->allowed($u, $o, 'grading_rules.manage', $s));
        $this->assertFalse($access->allowed($u, $o, 'backup.run', $s));
        $new = $this->makeSession($o, 'Sesi baru');
        $this->assertTrue($access->allowed($u, $o, 'sessions.manage', $new));
        $this->actingAs($u)->get("/praktikum/$o/sesi/$new")->assertOk();
        $other = $this->offering('OTHER');
        $otherSession = $this->makeSession($other);
        $this->get("/praktikum/$other")->assertForbidden();
        $this->get("/praktikum/$other/sesi/$otherSession")->assertForbidden();
        $this->get("/praktikum/$o/sesi/$otherSession")->assertForbidden();
        $this->get('/dashboard')->assertDontSee('Data contoh — OTHER');
    }

    public function test_optional_session_scope_is_enforced_in_direct_requests(): void
    {
        $u = $this->user();
        $o = $this->offering();
        $s = $this->makeSession($o);
        $blocked = $this->makeSession($o, 'Sesi 2');
        $a = $this->assign($u, $o, false);
        DB::table('staff_session_scopes')->insert(['assignment_id' => $a, 'offering_id' => $o, 'session_id' => $s]);
        $this->actingAs($u)->get("/praktikum/$o/sesi/$s")->assertOk();
        $this->get("/praktikum/$o/sesi/$blocked")->assertForbidden();
        $this->get("/praktikum/$o")->assertOk()->assertSee('Sesi 1')->assertDontSee('Sesi 2');
        $this->assertFalse(app(StaffAccess::class)->allowed($u, $o, 'attendance.manage'));
    }

    public function test_explicit_deny_and_grant_apply_immediately(): void
    {
        $u = $this->user();
        $o = $this->offering();
        $s = $this->makeSession($o);
        $a = $this->assign($u, $o);
        $access = app(StaffAccess::class);
        $this->assertTrue($access->allowed($u, $o, 'sessions.manage', $s));
        DB::table('user_permissions')->insert(['assignment_id' => $a, 'permission_key' => 'sessions.manage', 'allowed' => false]);
        $this->assertFalse($access->allowed($u, $o, 'sessions.manage', $s));
        $this->actingAs($u)->get("/praktikum/$o/sesi/$s")->assertForbidden();
        DB::table('user_permissions')->insert(['assignment_id' => $a, 'permission_key' => 'grading_rules.manage', 'allowed' => true]);
        $this->assertTrue($access->allowed($u, $o, 'grading_rules.manage', $s));
        $this->assertFalse($access->allowed($u, $o, 'accounts.manage', $s));
    }

    public function test_admin_saves_scopes_permissions_and_atomic_audit(): void
    {
        $admin = $this->user('admin');
        $u = $this->user();
        $o = $this->offering();
        $s = $this->makeSession($o);
        $this->actingAs($admin)->put('/pengaturan/aslab/'.$u->id, $this->payload($u, [['offering_id' => $o, 'all_sessions' => false, 'sessions' => [$s], 'permissions' => ['attendance.manage']]]))->assertRedirect();
        $a = DB::table('staff_assignments')->where('user_id', $u->id)->first();
        $this->assertDatabaseHas('staff_session_scopes', ['assignment_id' => $a->id, 'session_id' => $s]);
        $access = app(StaffAccess::class);
        $this->assertTrue($access->allowed($u, $o, 'attendance.manage', $s));
        $this->assertFalse($access->allowed($u, $o, 'sessions.manage', $s));
        $log = DB::table('activity_logs')->first();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertNotNull($log->before_json);
        $this->assertStringNotContainsString('SecurePassword', $log->after_json);
        $this->assertSame(2, $u->fresh()->version);
    }

    public function test_cross_offering_scope_and_unknown_permission_reject_entire_update(): void
    {
        $admin = $this->user('admin');
        $u = $this->user();
        $o = $this->offering();
        $other = $this->offering('OTHER');
        $s = $this->makeSession($other);
        $payload = $this->payload($u, [['offering_id' => $o, 'all_sessions' => false, 'sessions' => [$s], 'permissions' => ['attendance.manage']]]);
        $payload['name'] = 'Must not save';
        $this->actingAs($admin)->put('/pengaturan/aslab/'.$u->id, $payload)->assertSessionHasErrors();
        $this->assertSame($u->name, $u->fresh()->name);
        $this->assertDatabaseCount('staff_assignments', 0);
        $this->assertDatabaseCount('activity_logs', 0);
        $payload['assignments'][0]['sessions'] = [];
        $payload['assignments'][0]['permissions'] = ['accounts.manage'];
        $this->put('/pengaturan/aslab/'.$u->id, $payload)->assertSessionHasErrors();
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_stale_edit_is_a_visible_conflict_and_never_overwrites(): void
    {
        $admin = $this->user('admin');
        $u = $this->user();
        $payload = $this->payload($u);
        $this->actingAs($admin)->put('/pengaturan/aslab/'.$u->id, $payload)->assertRedirect();
        $payload['name'] = 'Stale edit';
        $this->put('/pengaturan/aslab/'.$u->id, $payload)->assertStatus(409);
        $this->assertSame($u->name, $u->fresh()->name);
        $this->assertDatabaseCount('activity_logs', 1);
    }

    public function test_password_reset_and_deactivation_revoke_database_sessions(): void
    {
        $admin = $this->user('admin');
        $u = $this->user();
        DB::table('sessions')->insert(['id' => 'test-session', 'user_id' => $u->id, 'payload' => '', 'last_activity' => time()]);
        $payload = $this->payload($u);
        $payload['password'] = 'NewPassword12345!';
        $payload['password_confirmation'] = $payload['password'];
        $this->actingAs($admin)->put('/pengaturan/aslab/'.$u->id, $payload)->assertRedirect();
        $this->assertDatabaseMissing('sessions', ['user_id' => $u->id]);
        $this->assertTrue(Hash::check('NewPassword12345!', $u->fresh()->password));
        $log = DB::table('activity_logs')->first();
        $this->assertStringNotContainsString('NewPassword', $log->after_json);
        $payload = $this->payload($u->fresh());
        $payload['active'] = 0;
        $this->put('/pengaturan/aslab/'.$u->id, $payload)->assertRedirect();
        $this->assertFalse($u->fresh()->active);
    }

    public function test_removing_assignment_revokes_access_and_cascades_only_grants(): void
    {
        $admin = $this->user('admin');
        $u = $this->user();
        $o = $this->offering();
        $s = $this->makeSession($o);
        $a = $this->assign($u, $o);
        DB::table('user_permissions')->insert(['assignment_id' => $a, 'permission_key' => 'sessions.manage', 'allowed' => true]);
        $this->actingAs($admin)->put('/pengaturan/aslab/'.$u->id, $this->payload($u))->assertRedirect();
        $this->assertFalse(app(StaffAccess::class)->allowed($u, $o, 'sessions.manage', $s));
        $this->assertDatabaseCount('user_permissions', 0);
        $this->assertDatabaseCount('practicum_sessions', 1);
    }

    public function test_admin_cannot_mutate_another_admin_through_aslab_routes(): void
    {
        $admin = $this->user('admin');
        $other = $this->user('admin');
        $this->actingAs($admin)->get('/pengaturan/aslab/'.$other->id)->assertNotFound();
        $this->put('/pengaturan/aslab/'.$other->id, $this->payload($other))->assertNotFound();
    }

    public function test_invalid_password_and_missing_reason_are_rejected(): void
    {
        $admin = $this->user('admin');
        $u = $this->user();
        $payload = $this->payload($u);
        unset($payload['reason']);
        $payload['password'] = 'weak';
        $payload['password_confirmation'] = 'different';
        $this->actingAs($admin)->put('/pengaturan/aslab/'.$u->id, $payload)->assertSessionHasErrors(['reason', 'password']);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_database_foreign_key_rejects_cross_offering_scope(): void
    {
        $u = $this->user();
        $o = $this->offering();
        $a = $this->assign($u, $o, false);
        $other = $this->offering('OTHER');
        $s = $this->makeSession($other);
        $this->expectException(QueryException::class);
        DB::table('staff_session_scopes')->insert(['assignment_id' => $a, 'offering_id' => $o, 'session_id' => $s]);
    }

    public function test_empty_restricted_scope_denies_all_sessions(): void
    {
        $u = $this->user();
        $o = $this->offering();
        $s = $this->makeSession($o);
        $this->assign($u, $o, false);
        $this->actingAs($u)->get("/praktikum/$o/sesi/$s")->assertForbidden();
    }

    public function test_bootstrap_admin_has_no_default_password_and_refuses_second_admin(): void
    {
        $this->artisan('portal:admin', ['email' => 'admin@example.test'])->expectsQuestion('Kata sandi (minimal 12 karakter, huruf besar/kecil dan angka)', 'StrongPassword123!')->expectsQuestion('Konfirmasi kata sandi', 'StrongPassword123!')->expectsOutput('Admin dibuat.')->assertSuccessful();
        $this->assertDatabaseHas('users', ['email' => 'admin@example.test', 'role' => 'admin']);
        $this->artisan('portal:admin', ['email' => 'other@example.test'])->expectsOutput('Admin sudah tersedia. Perintah bootstrap dinonaktifkan.')->assertFailed();
    }

    public function test_public_layout_stays_public_when_manager_logged_in(): void
    {
        $this->actingAs($this->user('admin'))->get('/')->assertOk()->assertSee('public-header')->assertDontSee('id="sidebar"', false);
    }

    public function test_readiness_checks_real_mysql_without_exposing_config(): void
    {
        $this->get('/health/ready')->assertOk()->assertExactJson(['status' => 'ready']);
    }

    public function test_client_cannot_promote_aslab_or_override_assignment_owner(): void
    {
        $admin = $this->user('admin');
        $u = $this->user();
        $payload = $this->payload($u);
        $payload['role'] = 'admin';
        $this->actingAs($admin)->put('/pengaturan/aslab/'.$u->id, $payload)->assertRedirect();
        $this->assertSame('aslab', $u->fresh()->role);
        $o = $this->offering();
        $payload = $this->payload($u->fresh(), [['offering_id' => $o, 'all_sessions' => true, 'permissions' => [], 'user_id' => $admin->id]]);
        $this->put('/pengaturan/aslab/'.$u->id, $payload)->assertSessionHasErrors();
        $this->assertDatabaseCount('staff_assignments', 0);
    }
}
