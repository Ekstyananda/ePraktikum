<input type="hidden" name="offering_id" value="{{ $o->id }}">
<div class="row g-3 mb-2" id="identity">
    <div class="col-md-4"><label for="nbi" class="form-label">NBI</label><input id="nbi" name="nbi" class="form-control" value="{{ old('nbi') }}" required maxlength="40" autocomplete="off" inputmode="numeric"></div>
    <div class="col-md-4"><label for="name" class="form-label">Nama sesuai pendaftaran</label><input id="name" name="name" class="form-control" value="{{ old('name') }}" required maxlength="150" autocomplete="name"></div>
    <div class="col-md-4"><label for="session_id" class="form-label">Sesi terdaftar saat ini</label><select id="session_id" name="session_id" class="form-select" required><option value="">Pilih sesi</option>@foreach($sessions as $session)<option value="{{ $session->id }}" @selected(old('session_id') == $session->id)>{{ $session->label }}</option>@endforeach</select></div>
</div>
<p class="small text-secondary mb-3"><i class="bi bi-shield-lock" aria-hidden="true"></i> Isian dicocokkan dengan pendaftaran dan diperiksa pengelola. Portal tidak menampilkan nama berdasarkan NBI.</p>
