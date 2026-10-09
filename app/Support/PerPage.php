<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Shared page-size choice for server-side paginated lists (docs/04). */
class PerPage
{
    public const OPTIONS = [10, 25, 50, 100];

    public static function from(Request $r, int $default = 25): int
    {
        $r->validate(['per_page' => ['nullable', Rule::in(self::OPTIONS)]]);

        return (int) $r->input('per_page', $default);
    }
}
