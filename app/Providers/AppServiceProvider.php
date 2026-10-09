<?php

namespace App\Providers;

use App\View\Composers\ManagerShell;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Paginator::useBootstrapFive();
        View::composer('layouts.app', ManagerShell::class);
        // Campus networks share one public IP, so the per-IP allowance is generous; the per-identity limit is strict.
        RateLimiter::for('public-submit', fn (Request $r) => [
            Limit::perMinute(config('portal.rate_limits.submit_per_ip'))->by('public-submit|'.$r->ip()),
            Limit::perMinute(config('portal.rate_limits.submit_per_identity'))->by('public-submit-id|'.hash('sha256', mb_strtolower(trim((string) ($r->input('nbi') ?? $r->input('token') ?? $r->ip()))))),
        ]);
        RateLimiter::for('public-status', fn (Request $r) => Limit::perMinute(config('portal.rate_limits.status_per_ip'))->by('public-status|'.$r->ip()));
        RateLimiter::for('public-download', fn (Request $r) => Limit::perMinute(config('portal.rate_limits.download_per_ip'))->by('public-download|'.$r->ip()));
    }
}
