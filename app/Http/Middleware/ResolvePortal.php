<?php

namespace App\Http\Middleware;

use App\Services\PracticumPortal;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves /{slug}/... to a practicum and its single public offering.
 * - Unknown slug or no public offering: 404.
 * - Old slug (alias): GET/HEAD get a 301 to the current slug; POST is processed for the same practicum
 *   so a form opened before the rename does not lose its input.
 * - Disabled service (route default "service"): 404 for every method, so nothing can be stored.
 */
class ResolvePortal
{
    public function __construct(private PracticumPortal $portal) {}

    public function handle(Request $request, Closure $next): Response
    {
        $ctx = $this->portal->resolve((string) $request->route('slug'));
        if (! $ctx) {
            // Known practicum without a public period: explain instead of a bare 404. Nothing is processed.
            $known = DB::table('practicum_slugs as s')->join('practicums as p', 'p.id', '=', 's.practicum_id')->where('s.slug', strtolower((string) $request->route('slug')))->first(['p.name', 'p.display_name']);
            abort_unless($known, 404);

            return response()->view('portal.closed', ['name' => $known->display_name ?: $known->name], 404);
        }
        if ($ctx['alias'] && in_array($request->method(), ['GET', 'HEAD'], true)) {
            $path = preg_replace('#^/[^/]+#', '/'.$ctx['current_slug'], '/'.ltrim($request->path(), '/'));
            $query = $request->getQueryString();

            return redirect($path.($query ? '?'.$query : ''), 301);
        }
        $services = PracticumPortal::services($ctx['practicum']);
        $service = $request->route()->defaults['service'] ?? null;
        abort_if($service && ! ($services[$service] ?? false), 404);
        $ctx['services'] = $services;
        $ctx['palette'] = PracticumPortal::palette($ctx['practicum']);
        $ctx['name'] = PracticumPortal::displayName($ctx['practicum']);
        $request->attributes->set('portal', $ctx);
        View::share('portal', $ctx);

        return $next($request);
    }
}
