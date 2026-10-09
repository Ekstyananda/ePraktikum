<?php

namespace Tests\Concerns;

use App\Services\PracticumPortal;
use Illuminate\Support\Facades\DB;

/** Puts a test offering on the public portal the same way production does: active offering + registered slug. */
trait MakesPortalPublic
{
    protected function makePublic(int $offering): string
    {
        $o = DB::table('practicum_offerings')->where('id', $offering)->first();
        DB::table('practicum_offerings')->where('id', $offering)->update(['status' => 'active']);
        DB::table('semesters')->where('id', $o->semester_id)->where('status', 'draft')->update(['status' => 'active']);
        $p = DB::table('practicums')->where('id', $o->practicum_id)->first();
        if (! $p->slug) {
            app(PracticumPortal::class)->registerInitial($p->id, $p->code);
        }

        return DB::table('practicums')->where('id', $p->id)->value('slug');
    }
}
