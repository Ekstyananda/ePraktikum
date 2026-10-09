@extends('layouts.app')
@section('title','Detail Aslab — Portal Praktikum')
@section('content')
<x-page-header :title="$aslab->name" :subtitle="$aslab->email" :crumbs="['Pengaturan Aslab' => route('aslab.index'), $aslab->name => null]">
    <x-slot:actions><span class="status-badge {{ $aslab->active?'status-ok':'status-neutral' }}">{{ $aslab->active?'Aktif':'Nonaktif' }}</span></x-slot:actions>
</x-page-header>
@php
$values=[];
foreach($assignments as $oid=>$a){$grant=$permissions->get($a->id,collect())->pluck('allowed','permission_key');$values[$oid]=['offering_id'=>$oid,'all_sessions'=>$a->all_sessions,'sessions'=>$scopes->get($a->id,collect())->pluck('session_id')->all(),'permissions'=>collect(\App\Services\StaffAccess::PERMISSIONS)->keys()->filter(fn($k)=>$grant->has($k)?$grant[$k]:in_array($k,\App\Services\StaffAccess::STANDARD,true))->all()];}
if(session()->hasOldInput()) $values=collect(old('assignments',[]))->keyBy('offering_id')->all();
@endphp
<div class="card">
    <div class="card-body">
        <form method="post" action="{{ route('aslab.update',$aslab) }}" data-dirty-form data-confirm="Simpan profil dan perubahan akses aslab ini? Izin berlaku langsung." novalidate>@csrf @method('PUT')<input type="hidden" name="version" value="{{ old('version',$aslab->version) }}">
            <ul class="nav nav-tabs" role="tablist">@foreach(['profil'=>'Profil','praktikum'=>'Praktikum','sesi'=>'Sesi','hak'=>'Hak Akses'] as $key=>$label)<li class="nav-item" role="presentation"><button type="button" class="nav-link {{ $loop->first?'active':'' }}" id="tab-{{ $key }}" data-bs-toggle="tab" data-bs-target="#pane-{{ $key }}" role="tab" aria-controls="pane-{{ $key }}" aria-selected="{{ $loop->first?'true':'false' }}">{{ $label }}</button></li>@endforeach</ul>
            <div class="tab-content">
                <div class="tab-pane active" id="pane-profil" role="tabpanel" aria-labelledby="tab-profil">
                    <div class="row g-3">
                        <div class="col-md-6"><label for="name" class="form-label">Nama</label><input id="name" name="name" class="form-control" value="{{ old('name',$aslab->name) }}" maxlength="150" required></div>
                        <div class="col-md-6"><label for="email" class="form-label">Email</label><input id="email" name="email" type="email" class="form-control" value="{{ old('email',$aslab->email) }}" required></div>
                        <div class="col-12"><input type="hidden" name="active" value="0">
                            <div class="form-check form-switch"><input id="active" name="active" value="1" type="checkbox" class="form-check-input" @checked(old('active',$aslab->active))><label for="active" class="form-check-label">Akun aktif</label></div>
                        </div>
                        <div class="col-md-6"><label for="password" class="form-label">Reset kata sandi (opsional)</label><input id="password" name="password" type="password" class="form-control" autocomplete="new-password">
                            <div class="form-text">Kosongkan jika tetap. Minimal 12 karakter, huruf besar/kecil dan angka. Reset mengakhiri sesi login aslab.</div>
                        </div>
                        <div class="col-md-6"><label for="password_confirmation" class="form-label">Konfirmasi kata sandi baru</label><input id="password_confirmation" name="password_confirmation" type="password" class="form-control" autocomplete="new-password"></div>
                    </div>
                </div>
                <div class="tab-pane" id="pane-praktikum" role="tabpanel" aria-labelledby="tab-praktikum">
                    <h2>Penugasan praktikum</h2>
                    <p class="text-secondary">Aktifkan praktikum, lalu atur lingkup sesi dan izin pada tab berikutnya.</p>
                    @forelse($offerings as $o)
                    @php $v=$values[$o->id]??null; @endphp
                    <section class="assignment offering-group" data-offering="{{ $o->id }}">
                        <div class="form-check"><input type="checkbox" class="form-check-input offering-enabled" id="offering-{{ $o->id }}" @checked($v)><label class="form-check-label fw-semibold" for="offering-{{ $o->id }}">{{ $o->name }} · {{ $o->semester }}</label></div>
                        <fieldset @disabled(!$v)>
                            <legend class="visually-hidden">Penugasan {{ $o->name }}</legend><input type="hidden" name="assignments[{{ $o->id }}][offering_id]" value="{{ $o->id }}">
                        </fieldset>
                    </section>
                    @empty<div class="alert alert-info">Belum ada praktikum/semester. Penugasan dapat disimpan setelah semester dan praktikum tersedia. Akun tetap dapat dibuat dan digunakan untuk login.</div>
                    @endforelse
                </div>
                <div class="tab-pane" id="pane-sesi" role="tabpanel" aria-labelledby="tab-sesi">
                    <h2>Lingkup sesi</h2>
                    <p class="text-secondary">Default mencakup semua sesi dalam praktikum yang ditugaskan, termasuk sesi baru. Nonaktifkan untuk memilih sesi tertentu.</p>
                    @forelse($offerings as $o)
                    @php $v=$values[$o->id]??null; @endphp
                    <section class="assignment offering-group" data-offering="{{ $o->id }}">
                        <h3 class="h6">{{ $o->name }} · {{ $o->semester }}</h3>
                        <fieldset @disabled(!$v)>
                            <legend class="visually-hidden">Lingkup sesi {{ $o->name }}</legend><input type="hidden" name="assignments[{{ $o->id }}][all_sessions]" value="0">
                            <div class="form-check"><input class="form-check-input all-sessions" type="checkbox" id="all-{{ $o->id }}" name="assignments[{{ $o->id }}][all_sessions]" value="1" @checked($v['all_sessions']??true)><label class="form-check-label" for="all-{{ $o->id }}">Semua sesi (default)</label></div>
                            <div class="d-flex gap-3 flex-wrap mt-3">@forelse($sessions->get($o->id,collect()) as $session)<div class="form-check"><input class="form-check-input session-check" type="checkbox" id="scope-{{ $session->id }}" name="assignments[{{ $o->id }}][sessions][]" value="{{ $session->id }}" @checked(in_array($session->id,$v['sessions']??[]))><label class="form-check-label" for="scope-{{ $session->id }}">{{ $session->label }}</label></div>@empty<p class="small text-secondary">Belum ada sesi.</p>
                                @endforelse</div>
                            <p class="form-text mb-0">Tanpa semua sesi atau pilihan sesi, akses operasional ditolak. Aktifkan penugasan pada tab Praktikum untuk mengubah isian.</p>
                        </fieldset>
                    </section>
                    @empty<p class="text-secondary">Belum ada praktikum tersedia.</p>
                    @endforelse
                </div>
                <div class="tab-pane" id="pane-hak" role="tabpanel" aria-labelledby="tab-hak">
                    <h2>Hak akses individual</h2>
                    <p class="text-secondary">Izin berlaku per praktikum. Izin yang tidak dicentang ditolak. Kelola akun selalu khusus admin.</p>
                    @forelse($offerings as $o)
                    @php $v=$values[$o->id]??null; @endphp
                    <section class="assignment offering-group" data-offering="{{ $o->id }}">
                        <fieldset @disabled(!$v)>
                            <legend class="visually-hidden">Hak akses {{ $o->name }}</legend>
                            <div class="d-flex justify-content-between align-items-center mb-3 gap-2">
                                <h3 class="h6 mb-0">{{ $o->name }} · {{ $o->semester }}</h3><button type="button" class="btn btn-outline-primary btn-sm preset">Preset standar</button>
                            </div>
                            <div class="permission-grid">@foreach(\App\Services\StaffAccess::PERMISSIONS as $key=>$label)<div class="form-check"><input type="checkbox" class="form-check-input permission-check" data-standard="{{ in_array($key,\App\Services\StaffAccess::STANDARD,true)?'1':'0' }}" id="permission-{{ $o->id }}-{{ str_replace('.','-',$key) }}" name="assignments[{{ $o->id }}][permissions][]" value="{{ $key }}" @checked(in_array($key,$v['permissions']??\App\Services\StaffAccess::STANDARD,true))><label class="form-check-label" for="permission-{{ $o->id }}-{{ str_replace('.','-',$key) }}">{{ $label }}</label></div>
                                @endforeach</div>
                            <p class="form-text mt-3 mb-0">Ubah bobot/kelulusan dan backup nonaktif pada preset standar. Izin disimpan untuk fitur operasional dan backup yang sedang disiapkan.</p>
                        </fieldset>
                    </section>
                    @empty<p class="text-secondary">Belum ada praktikum tersedia.</p>
                    @endforelse
                </div>
            </div>
            <hr class="mt-4"><label class="form-label" for="reason">Alasan perubahan</label><textarea id="reason" name="reason" class="form-control" required minlength="5" maxlength="1000" rows="2">{{ old('reason') }}</textarea>
            <div class="form-text">Tercatat bersama profil dan perubahan akses pada audit.</div>
            <div class="d-flex gap-2 mt-4"><button type="submit" class="btn btn-primary">Simpan perubahan</button><a href="{{ route('aslab.index') }}" class="btn btn-outline-secondary">Batal</a></div>
        </form>
    </div>
</div>
@endsection