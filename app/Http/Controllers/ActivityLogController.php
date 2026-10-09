<?php

namespace App\Http\Controllers;

use App\Services\Roster;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read-only activity log. Admin sees everything (including account changes without an offering);
 * an aslab needs logs.view for the whole offering. There is no edit or delete route by design.
 */
class ActivityLogController
{
    /** Keys whose values never leave the server, even to admins. */
    public const REDACT = ['password', 'password_hash', 'remember_token', 'token', 'token_hash', 'secret', 'payload', 'api_key', 'two_factor_secret'];

    public static function redact($value)
    {
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = is_string($key) && collect(self::REDACT)->contains(fn ($k) => str_contains(strtolower($key), $k)) ? '••• disamarkan' : self::redact($item);
        }

        return $value;
    }

    private function authorize(Request $r, ?int $offering, Roster $roster): void
    {
        if ($r->user()->role === 'admin') {
            return;
        }
        abort_unless($offering && $roster->access->allowed($r->user(), $offering, 'logs.view'), 403);
    }

    private function query(Request $r, ?int $offering)
    {
        return DB::table('activity_logs as l')->leftJoin('users as u', 'u.id', '=', 'l.actor_id')->leftJoin('practicum_offerings as o', 'o.id', '=', 'l.offering_id')
            ->leftJoin('practicums as p', 'p.id', '=', 'o.practicum_id')->when($offering, fn ($q) => $q->where('l.offering_id', $offering))
            ->select('l.*', 'u.name as actor', 'p.name as practicum');
    }

    public function index(Request $r, Roster $roster, ?int $offering = null)
    {
        $this->authorize($r, $offering, $roster);
        $d = $r->validate(['actor_id' => 'nullable|integer', 'action' => 'nullable|string|max:80', 'entity' => 'nullable|string|max:80', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'session_id' => 'nullable|integer', 'offering_id' => 'nullable|integer', 'per_page' => 'nullable|in:25,50,100']);
        $scope = $offering ?? (isset($d['offering_id']) ? (int) $d['offering_id'] : null);
        $q = $this->query($r, $scope);
        if (! empty($d['actor_id'])) {
            $q->where('l.actor_id', $d['actor_id']);
        }
        if (! empty($d['action'])) {
            $q->where('l.action', 'like', $d['action'].'%');
        }
        if (! empty($d['entity'])) {
            $q->where('l.entity_type', $d['entity']);
        }
        // Dates are entered in WIB and stored in UTC.
        if (! empty($d['from'])) {
            $q->where('l.created_at', '>=', CarbonImmutable::parse($d['from'], 'Asia/Jakarta')->startOfDay()->utc());
        }
        if (! empty($d['to'])) {
            $q->where('l.created_at', '<', CarbonImmutable::parse($d['to'], 'Asia/Jakarta')->startOfDay()->addDay()->utc());
        }
        if (! empty($d['session_id'])) {
            $sid = (int) $d['session_id'];
            $q->where(function ($q) use ($sid) {
                $q->where(fn ($q) => $q->where('l.entity_type', 'session')->where('l.entity_id', $sid));
                foreach (['session_id', 'source_session_id', 'target_session_id'] as $key) {
                    foreach (['before_json', 'after_json'] as $col) {
                        $q->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(l.$col, '$.$key')) = ?", [(string) $sid]);
                    }
                }
            });
        }
        $base = $this->query($r, $scope);

        return view('logs.index', [
            'o' => $offering ? $roster->offering($offering) : null, 'scope' => $scope,
            'rows' => $q->orderByDesc('l.id')->paginate((int) ($d['per_page'] ?? 25))->withQueryString(),
            'actors' => (clone $base)->whereNotNull('l.actor_id')->select('u.id', 'u.name')->distinct()->orderBy('u.name')->get(),
            'entities' => (clone $base)->select('l.entity_type')->distinct()->orderBy('l.entity_type')->pluck('entity_type'),
            'sessions' => $scope ? DB::table('practicum_sessions')->where('offering_id', $scope)->orderBy('label')->get(['id', 'label']) : collect(),
            'offerings' => $offering ? collect() : DB::table('practicum_offerings as o')->join('practicums as p', 'p.id', '=', 'o.practicum_id')->join('semesters as s', 's.id', '=', 'o.semester_id')->orderByDesc('o.id')->get(['o.id', 'p.name', 's.label as semester']),
        ]);
    }

    /** Route parameters are passed by position, so the offering-scoped route needs its own signature. */
    public function showOffering(Request $r, Roster $roster, int $offering, int $id)
    {
        return $this->show($r, $roster, $id, $offering);
    }

    public function show(Request $r, Roster $roster, int $id, ?int $offering = null)
    {
        $this->authorize($r, $offering, $roster);
        $row = $this->query($r, $offering)->where('l.id', $id)->first();
        abort_unless($row, 404);
        $before = self::redact(json_decode($row->before_json ?? 'null', true));
        $after = self::redact(json_decode($row->after_json ?? 'null', true));
        $keys = array_values(array_unique(array_merge(array_keys((array) $before), array_keys((array) $after))));
        $batch = DB::table('activity_logs')->where('request_id', $row->request_id)->where('id', '!=', $row->id)->when($offering, fn ($q) => $q->where('offering_id', $offering))->count();

        return view('logs.show', ['o' => $offering ? $roster->offering($offering) : null, 'row' => $row, 'before' => $before, 'after' => $after, 'keys' => $keys, 'batch' => $batch]);
    }
}
