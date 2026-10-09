# Aplikasi di server lab (Proxmox), NPM di VPS

Alur: pengunjung → DNS (A record ke IP VPS, tanpa proxy Cloudflare) → **Nginx Proxy Manager (NPM)** di VPS port 443 → **tunnel WireGuard** → Nginx portal (container) di VM server lab → PHP-FPM → MySQL lab.

Berbeda dengan [15_VPS_NPM.md](15_VPS_NPM.md) yang menaruh NPM dan aplikasi di VPS yang sama: di sini NPM tidak berbagi network Docker dengan portal, jadi **`compose.npm.yaml` tidak dipakai**. Server lab yang membuka koneksi keluar ke VPS, sehingga tidak perlu port forwarding atau IP publik di jaringan kampus.

Semua perintah dijalankan operator sendiri. Jangan menampilkan `.env` atau private key WireGuard di layar bersama/log.

Contoh alamat di dokumen ini (ganti bila bentrok dengan jaringan kampus/VPS):

| Mesin | IP tunnel |
|---|---|
| VPS (NPM) | `10.8.0.1` |
| VM portal di server lab | `10.8.0.2` |

## 0. Kapan memilih skema ini

- Cocok bila listrik dan internet lab cukup stabil, dan server lab punya **UPS**. Bila lab sering padam/putus saat praktikum, pakai deployment penuh di VPS ([15_VPS_NPM.md](15_VPS_NPM.md)).
- Kapasitas: uji beban QA 4 core melayani 300 pengguna serentak (p95 ±1,5 detik). Xeon E3-1220 v2/v3 (4 core/4 thread, 3,1–3,5 GHz) sedikit lebih lambat per core tetapi tetap jauh di atas kebutuhan satu lab.
- Upload praktikan dari jaringan lab berjalan lab → VPS → tunnel → lab, jadi memakai uplink kampus dua kali. Untuk tugas ±10 MB ini wajar; perhatikan bila banyak praktikan mengunggah bersamaan pada koneksi kampus yang kecil.

## 1. VM di Proxmox

Buat satu VM (Debian 12 atau Ubuntu 24.04) untuk portal + MySQL. Pengaturan yang sering menjadi sumber "delay":

| Pengaturan | Nilai |
|---|---|
| Processor → Type | **`host`** (bukan `kvm64`/`x86-64-v2`; CPU lama butuh AES-NI/AVX untuk HTTPS, enkripsi session, bcrypt) |
| Processor → Cores | 4 (semua core bila tidak ada VM berat lain) |
| Memory | 8 GB tetap, **Ballooning dimatikan** |
| Hard Disk | di **SSD**, Bus **SCSI** dengan controller **VirtIO SCSI single**, centang **IO thread** dan **Discard**, ±64 GB |
| Network | Model **VirtIO** |
| Options → QEMU Guest Agent | Enabled, lalu `apt install qemu-guest-agent` di VM |

Cek seri CPU dari shell Proxmox: `lscpu | grep "Model name"`.

Di dalam VM pasang Docker + Compose v2, lalu MySQL. Bila MySQL lab sudah ada, pakai yang itu (docs/08 bagian jaringan). Bila belum, ikuti [15_VPS_NPM.md](15_VPS_NPM.md) langkah 0, dengan tambahan buffer pool agar RAM VM terpakai:

```bash
docker run -d --name mysql-lab --restart unless-stopped --network lab-external --network-alias mysql-lab \
  --env-file /root/mysql-lab.env -v mysql-lab-data:/var/lib/mysql mysql:8.0 --innodb-buffer-pool-size=2G
```

`FPM_MAX_CHILDREN` dibiarkan default 16 (±1 GB); pada VM ini batasnya CPU, bukan RAM.

## 2. Tunnel WireGuard

Pasang di VPS dan VM portal: `apt install wireguard`. Buat kunci di masing-masing mesin:

```bash
cd /etc/wireguard && umask 077
wg genkey | tee privatekey | wg pubkey > publickey
cat publickey      # salin ke mesin pasangannya; privatekey tidak pernah dipindahkan
```

**VPS** — `/etc/wireguard/wg0.conf`:

```ini
[Interface]
Address = 10.8.0.1/24
ListenPort = 51820
PrivateKey = <isi privatekey VPS>

[Peer]
# VM portal di server lab
PublicKey = <publickey VM lab>
AllowedIPs = 10.8.0.2/32
```

**VM portal (lab)** — `/etc/wireguard/wg0.conf`:

```ini
[Interface]
Address = 10.8.0.2/24
PrivateKey = <isi privatekey VM lab>

[Peer]
# VPS
PublicKey = <publickey VPS>
Endpoint = <IP-publik-VPS>:51820
AllowedIPs = 10.8.0.1/32
# Menjaga lubang NAT kampus tetap terbuka.
PersistentKeepalive = 25
```

Aktifkan di kedua mesin dan buka port UDP di VPS:

```bash
systemctl enable --now wg-quick@wg0
ufw allow 51820/udp            # hanya di VPS
wg show                        # "latest handshake" harus muncul
ping -c3 10.8.0.2              # dari VPS
```

`AllowedIPs` sengaja `/32`: VPS hanya bisa menjangkau VM portal, bukan seluruh jaringan lab.

Bila upload besar macet di tengah jalan sementara halaman biasa lancar, turunkan MTU di kedua `[Interface]` (`MTU = 1380`), lalu `systemctl restart wg-quick@wg0`. Koneksi kampus PPPoE sering membutuhkannya.

### Docker harus menunggu IP tunnel
