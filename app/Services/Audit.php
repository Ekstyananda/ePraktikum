<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Audit
{
    public static function record(string $entity, int $id, string $action, ?array $before, ?array $after, ?string $reason = null, ?int $offering = null, ?string $batch = null): void
    {
        DB::table('activity_logs')->insert(['actor_id' => auth()->id(), 'offering_id' => $offering, 'entity_type' => $entity, 'entity_id' => $id, 'action' => $action, 'before_json' => $before === null ? null : json_encode($before), 'after_json' => $after === null ? null : json_encode($after), 'reason' => $reason, 'request_id' => $batch ?? (string) Str::uuid(), 'created_at' => now()]);
    }
}
