<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs the portal migration against existing practicums, as production will. DDL commits implicitly in MySQL,
 * so this class migrates explicitly instead of using RefreshDatabase and leaves a fresh schema behind.
 */
class SlugBackfillTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_10_07_000000_add_portal_identity_to_practicums.php';

    public function test_backfill_handles_empty_reserved_long_and_colliding_codes(): void
    {
        Artisan::call('migrate:fresh');
        $migration = require base_path(self::MIGRATION);
        $migration->down();
        $codes = ['SBD', 'SBD!', 'S B D', 's.b.d', '!!!', 'login', str_repeat('x', 60), 'PCD'];
        $ids = [];
        foreach ($codes as $i => $code) {
            $ids[$code] = DB::table('practicums')->insertGetId(['code' => $code, 'name' => 'Praktikum '.$i]);
        }
        $migration->up();
        $slugs = DB::table('practicums')->pluck('slug', 'code')->all();
        $this->assertSame([
            'SBD' => 'sbd', 'SBD!' => 'sbd-2', 'S B D' => 's-b-d', 's.b.d' => 'sbd-3', '!!!' => 'praktikum-'.$ids['!!!'],
            'login' => 'praktikum-'.$ids['login'], str_repeat('x', 60) => 'praktikum-'.$ids[str_repeat('x', 60)], 'PCD' => 'pcd',
        ], $slugs);
        $this->assertSame(count($codes), DB::table('practicum_slugs')->where('is_current', true)->count());
        foreach ($slugs as $code => $slug) {
            $this->assertDatabaseHas('practicum_slugs', ['slug' => $slug, 'practicum_id' => $ids[$code], 'is_current' => 1]);
        }
        // Fresh, empty schema for the classes that follow.
        Artisan::call('migrate:fresh');
        RefreshDatabaseState::$migrated = true;
    }
}
