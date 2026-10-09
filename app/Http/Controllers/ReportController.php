<?php

namespace App\Http\Controllers;

use App\Services\Audit;
use App\Services\Reports;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/** Rekap & Ekspor: one data source for the page, print view, CSV and XLSX. */
class ReportController
{
    public function index(Request $r, int $offering, Reports $reports)
    {
        $sessions = $reports->sessions($r->user(), $offering);
        $d = $r->validate(['type' => ['nullable', Rule::in(array_keys(Reports::TYPES))], 'session_id' => 'nullable|integer', 'meeting_id' => 'nullable|integer', 'format' => 'nullable|in:html,print,csv,xlsx']);
        $type = $d['type'] ?? 'presensi';
        $session = isset($d['session_id']) ? (int) $d['session_id'] : null;
        abort_if($session && ! $sessions->contains('id', $session), 403);
        $meeting = isset($d['meeting_id']) && DB::table('meetings')->where('offering_id', $offering)->where('id', $d['meeting_id'])->exists() ? (int) $d['meeting_id'] : null;
        $data = $reports->build($r->user(), $offering, $type, $session, $meeting);
        $o = $reports->roster->offering($offering);
        $format = $d['format'] ?? 'html';
        if (in_array($format, ['csv', 'xlsx'], true)) {
            Audit::record('report', $offering, 'report.exported', null, ['type' => $type, 'format' => $format, 'session_id' => $session, 'meeting_id' => $meeting, 'rows' => count($data['rows'])], null, $offering);

            return $this->export($data, $format, 'rekap-'.$type.'-'.now('Asia/Jakarta')->format('Ymd-Hi'));
        }

        return view($format === 'print' ? 'reports.print' : 'reports.index', [
            'o' => $o, 'type' => $type, 'data' => $data, 'sessions' => $sessions, 'session' => $session, 'meeting' => $meeting,
            'meetings' => DB::table('meetings')->where('offering_id', $offering)->orderBy('number')->get(['id', 'number', 'title']),
        ]);
    }

    /** Values starting with = + - @ are prefixed so spreadsheets never evaluate them as formulas. */
    private function safe(string $v): string
    {
        return preg_match('/^[\s\x00-\x1f]*[=+\-@]/u', $v) ? "'".$v : $v;
    }

    private function export(array $data, string $format, string $name)
    {
        if ($format === 'xlsx') {
            return response()->streamDownload(function () use ($data) {
                $file = tempnam(sys_get_temp_dir(), 'portal-report-');
                $writer = new Writer;
                try {
                    $writer->openToFile($file);
                    foreach (array_merge([$data['headers']], $data['rows']) as $row) {
                        $writer->addRow(new Row(array_map(fn ($v) => new StringCell($this->safe((string) $v)), $row)));
                    }
                    $writer->close();
                    readfile($file);
                } finally {
                    if (file_exists($file)) {
                        unlink($file);
                    }
                }
            }, $name.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'no-store']);
        }

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach (array_merge([$data['headers']], $data['rows']) as $row) {
                fputcsv($out, array_map(fn ($v) => $this->safe((string) $v), $row), ',', '"', '');
            }
            fclose($out);
        }, $name.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
