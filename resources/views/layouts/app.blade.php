@php $isManager = auth()->check() && !request()->routeIs('home','login','public.*','portal.*','legacy.*'); @endphp
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title','Portal Praktikum')</title>
    <link href="{{ \App\Support\Asset::url('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ \App\Support\Asset::url('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ \App\Support\Asset::url('assets/portal.css') }}" rel="stylesheet">
</head>

@php $accent = ! $isManager && isset($portal) ? $portal['palette'] : null; @endphp
<body class="{{ $isManager ? 'manager' : 'public' }}" @if($accent) data-portal style="--accent: {{ $accent['accent'] }}; --accent-dark: {{ $accent['dark'] }}; --accent-soft: {{ $accent['soft'] }};" @endif>
    @if($isManager)<script>try{if(localStorage.getItem('portal.sidebar')==='collapsed')document.body.classList.add('sidebar-collapsed')}catch(e){}</script>@endif
    @if($isManager)
    <aside class="sidebar" id="sidebar">
        <div class="brand-bar">
            <a class="brand" href="{{ route('dashboard') }}"><img src="{{ \App\Support\Asset::url('assets/logo-prodi.png') }}" alt="Teknik Informatika Universitas 17 Agustus 1945 Surabaya"></a>
            <button type="button" class="btn btn-icon sidebar-close d-lg-none" id="nav-close"><i class="bi bi-x-lg" aria-hidden="true"></i><span class="visually-hidden">Tutup navigasi</span></button>
        </div>
        <nav aria-label="Navigasi pengelola" class="sidebar-nav">
            @foreach($shell['menu'] as $item)
            @if($item['url'])
            <a class="{{ $item['active'] ? 'selected' : '' }}" href="{{ $item['url'] }}" title="{{ $item['label'] }}" @if($item['active']) aria-current="page" @endif><i class="bi bi-{{ $item['icon'] }}" aria-hidden="true"></i><span>{{ $item['label'] }}</span>@if(!empty($item['badge']))<b class="nav-badge" title="{{ $item['badge'] }} menunggu">{{ $item['badge'] }}</b>@endif</a>
            @else
            <span class="nav-disabled" aria-disabled="true" title="Belum tersedia pada versi ini"><i class="bi bi-{{ $item['icon'] }}" aria-hidden="true"></i><span>{{ $item['label'] }}</span><small>Segera</small></span>
            @endif
            @endforeach
            @if(!$shell['current'])
            <a class="{{ request()->routeIs('dashboard') ? 'selected' : '' }}" href="{{ route('dashboard') }}"><i class="bi bi-house-door" aria-hidden="true"></i><span>Dashboard</span></a>
            @endif
            @if(auth()->user()->role==='admin')
            <div class="sidebar-label">Administrasi</div>
            <a class="{{ request()->routeIs('settings.index') ? 'selected' : '' }}" href="{{ route('settings.index') }}" title="Pengaturan"><i class="bi bi-gear" aria-hidden="true"></i><span>Pengaturan</span></a>
            @foreach(['semester'=>['Semester','calendar3'],'praktikum'=>['Master Praktikum','collection'],'offering'=>['Pelaksanaan Praktikum','diagram-3']] as $type=>[$label,$icon])
            <a href="{{ route('master.index',$type) }}" title="{{ $label }}" class="{{ request()->route('type')===$type?'selected':'' }}"><i class="bi bi-{{ $icon }}" aria-hidden="true"></i><span>{{ $label }}</span></a>
            @endforeach
            <a class="{{ request()->routeIs('aslab.*')?'selected':'' }}" href="{{ route('aslab.index') }}" title="Pengaturan Aslab"><i class="bi bi-person-gear" aria-hidden="true"></i><span>Pengaturan Aslab</span></a>
            <a class="{{ request()->routeIs('logs.index', 'logs.show') ? 'selected' : '' }}" href="{{ route('logs.index') }}" title="Log Aktivitas"><i class="bi bi-journal-text" aria-hidden="true"></i><span>Log Aktivitas</span></a>
            <a class="{{ request()->routeIs('backup.*') ? 'selected' : '' }}" href="{{ route('backup.index') }}" title="Backup"><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i><span>Backup</span></a>
            @elseif(\App\Http\Controllers\BackupController::allowed(auth()->user(), app(\App\Services\StaffAccess::class)))
            <div class="sidebar-label">Administrasi</div>
            <a class="{{ request()->routeIs('backup.*') ? 'selected' : '' }}" href="{{ route('backup.index') }}" title="Backup"><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i><span>Backup</span></a>
            @endif
        </nav>
        <div class="sidebar-foot"><span>Teknik Informatika<br>Untag Surabaya</span></div>
    </aside>
    <div class="sidebar-backdrop d-lg-none" id="nav-backdrop" hidden></div>
    <div class="workspace">
        <header class="topbar">
            <button class="btn btn-icon d-lg-none" type="button" aria-controls="sidebar" aria-expanded="false" id="nav-toggle"><i class="bi bi-list" aria-hidden="true"></i><span class="visually-hidden">Navigasi</span></button>
            <button class="btn btn-icon d-none d-lg-inline-flex" type="button" id="sidebar-collapse" aria-pressed="false" title="Ciutkan / lebarkan sidebar"><i class="bi bi-layout-sidebar" aria-hidden="true"></i><span class="visually-hidden">Ciutkan sidebar</span></button>
            <a class="topbar-logo d-lg-none" href="{{ route('dashboard') }}"><img src="{{ \App\Support\Asset::url('assets/logo-prodi.png') }}" alt="Teknik Informatika Untag Surabaya"></a>
            <div class="topbar-context">
                <strong>Portal Praktikum</strong>
                <div class="small text-secondary">@yield('context', $shell['current'] ? $shell['current']->semester.' · '.$shell['current']->name : 'Belum ada praktikum dalam lingkup akses')</div>
            </div>
            <div class="topbar-actions">
                @if($shell['offerings']->count() > 1)
                <form method="get" action="{{ route('dashboard') }}" class="offering-switch">
                    <label for="topbar-praktikum" class="small text-secondary">Semester · Praktikum</label>
                    <select id="topbar-praktikum" name="praktikum" class="form-select form-select-sm" data-autosubmit>
                        @foreach($shell['offerings'] as $option)
                        <option value="{{ $option->id }}" @selected($shell['current']?->id === $option->id)>{{ $option->semester }} · {{ $option->name }}</option>
                        @endforeach
                    </select>
                    <noscript><button class="btn btn-sm btn-outline-primary">Buka</button></noscript>
                </form>
                @endif
                <div class="dropdown">
                    <button class="user-chip" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="avatar" aria-hidden="true">{{ $shell['initials'] }}</span>
                        <span class="user-meta"><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->role==='admin'?'Administrator':'Asisten Laboratorium' }}</small></span>
                        <i class="bi bi-chevron-down small" aria-hidden="true"></i>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end shadow-sm">
                        <span class="dropdown-item-text small text-secondary">{{ auth()->user()->email }}</span>
                    </div>
                </div>
                <form method="post" action="{{ route('logout') }}">@csrf<button class="btn btn-sm btn-outline-primary"><i class="bi bi-box-arrow-right" aria-hidden="true"></i><span class="btn-label">Keluar</span></button></form>
            </div>
        </header>
        <main class="container-fluid content">
            @else
            @php $pp = $portal ?? null; @endphp
            <header class="public-header">
                <a href="{{ route('home') }}" class="public-brand"><img src="{{ \App\Support\Asset::url('assets/logo-prodi.png') }}" alt="Teknik Informatika Untag Surabaya"></a>
                @if($pp)<a href="{{ route('portal.home', $pp['current_slug']) }}" class="portal-chip" title="{{ $pp['name'] }}"><span class="portal-chip-code">{{ $pp['practicum']->short_name ?: $pp['practicum']->code }}</span><span class="portal-chip-name">{{ $pp['name'] }}</span></a>@endif
                <button class="btn btn-icon public-nav-toggle {{ $pp ? 'd-xl-none' : 'd-lg-none' }}" type="button" data-bs-toggle="collapse" data-bs-target="#public-nav" aria-controls="public-nav" aria-expanded="false"><i class="bi bi-list" aria-hidden="true"></i><span class="visually-hidden">Menu</span></button>
                <nav aria-label="Navigasi publik" class="collapse {{ $pp ? 'd-xl-flex' : 'd-lg-flex' }} public-nav" id="public-nav">
                    @php
                    if ($pp) {
                        $slug = $pp['current_slug'];
                        $items = ['portal.home' => ['Beranda', route('portal.home', $slug)]];
                        foreach (['jadwal' => 'Jadwal', 'modul' => 'Modul', 'pengumpulan' => 'Pengumpulan', 'pengajuan' => 'Pengajuan', 'remidi' => 'Remidi'] as $key => $label) {
                            if ($pp['services'][$key]) {
                                $items[$key === 'modul' ? 'portal.materials' : 'portal.'.$key] = [$label, route($key === 'modul' ? 'portal.materials' : 'portal.'.$key, $slug)];
                            }
                        }
                        $items['portal.announcements'] = ['Pengumuman', route('portal.announcements', $slug)];
                    } else {
                        $items = ['home' => ['Pilih Praktikum', route('home')], 'public.announcements.index' => ['Pengumuman', route('public.announcements.index')]];
                    }
                    $items['public.status'] = ['Cek Status', route('public.status')];
                    $activeFor = ['portal.materials' => ['portal.materials', 'portal.material.download'], 'portal.pengumpulan' => ['portal.pengumpulan', 'portal.submit'], 'portal.pengajuan' => ['portal.pengajuan', 'portal.request'], 'portal.remidi' => ['portal.remidi', 'portal.remedial'], 'portal.announcements' => ['portal.announcements', 'portal.announcement'], 'public.status' => ['public.status', 'public.status.check', 'public.revise'], 'public.announcements.index' => ['public.announcements.index', 'public.announcements.show']];
                    @endphp
                    @foreach($items as $name => [$label, $url])
                    @php $active = request()->routeIs(...($activeFor[$name] ?? [$name])); @endphp
                    <a href="{{ $url }}" class="{{ $active ? 'active' : '' }}" @if($active) aria-current="page" @endif>{{ $label }}</a>
                    @endforeach
                    @if($pp)<a href="{{ route('home') }}" class="portal-switch" title="Ganti praktikum"><i class="bi bi-grid" aria-hidden="true"></i><span class="portal-switch-text"> Ganti praktikum</span></a>@endif
                    <a class="btn btn-primary btn-sm" href="{{ route('login') }}"><i class="bi bi-person" aria-hidden="true"></i> Login Aslab</a>
                </nav>
            </header>
            <main class="container py-4 public-main">
                @endif
                @if(session('success'))<div class="alert alert-success d-flex gap-2" role="status"><i class="bi bi-check-circle-fill" aria-hidden="true"></i><div>{{ session('success') }}</div></div>@endif
                @if($errors->any())<div class="alert alert-danger" role="alert"><strong><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> Periksa isian berikut:</strong>
                    <ul class="mb-0">@foreach($errors->getMessages() as $field=>$messages)<li><a href="#{{ str_replace('.','-',$field) }}">{{ $messages[0] }}</a></li>@endforeach</ul>
                </div>@endif
                @yield('content')
            </main>@if($isManager)
    </div>@endif
    <script type="application/json" id="validation-errors">
        @json($errors->getMessages())
    </script>
    <script src="{{ \App\Support\Asset::url('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ \App\Support\Asset::url('assets/portal.js') }}" defer></script>@stack('scripts')
</body>

</html>
