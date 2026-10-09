{{-- Reissue a lost token: identity check first, crucial reason required. --}}
<form method="post" action="{{ $action }}" class="card" data-dirty-form data-confirm="Terbitkan token baru? Token lama langsung tidak berlaku.">@csrf<div class="card-body">
    <h2>Terbitkan token baru</h2>
    <div class="alert alert-warning small" role="note"><i class="bi bi-shield-exclamation" aria-hidden="true"></i> Hanya untuk praktikan yang kehilangan token. <strong>Cocokkan dulu identitasnya dengan kiriman ini</strong> secara tatap muka atau lewat kanal pribadi yang Anda kenal (bukan nomor/akun baru). Berikan token hanya kepada pemiliknya. Token lama langsung tidak berlaku.</div>
    <span class="form-label d-block">Cara verifikasi</span>
    <label class="d-block"><input type="radio" name="method" value="in_person" required @checked(old('method') === 'in_person')> Tatap muka (kartu/identitas dicocokkan)</label>
    <label class="d-block mb-3"><input type="radio" name="method" value="private_channel" @checked(old('method') === 'private_channel')> Kanal pribadi yang sudah dikenal</label>
    <label for="reissue-reason" class="form-label">Alasan (wajib)</label>
    <textarea id="reissue-reason" name="reason" class="form-control mb-3" rows="2" required minlength="10" maxlength="1000" placeholder="Mis. HP praktikan hilang, identitas dicocokkan di lab">{{ old('reason') }}</textarea>
    <label class="d-block mb-3"><input type="checkbox" name="verified" value="1" required> Identitas praktikan sudah saya cocokkan dengan kiriman ini. Token hanya diberikan kepada pemiliknya.</label>
    <button type="submit" class="btn btn-outline-danger"><i class="bi bi-key" aria-hidden="true"></i> Terbitkan token baru</button>
</div></form>
