# Deploy cPanel via arsip bertahap (GEMMA449)

Dokumen ini adalah runbook pemasangan **sekali saja** di server cPanel, lalu
deploy berjalan otomatis dari GitHub setiap CI `main` sukses.

## Kenapa arsip bertahap, bukan FTP per file

Backend Laravel punya ±16.000 file (mayoritas `vendor/`). Mengunggahnya satu per
satu lewat FTPS butuh jam dan mudah terputus. Satu ZIP ±21 MiB cukup dikirim
dalam belasan potongan kecil lewat HTTPS.

## Tata letak server

```
/home/permatat/followtheline.id/gemma449/          ← DOCROOT web (SPA)
├── .htaccess            ← routing SPA + rewrite /api
├── index.html, assets/, sw.js, ...
├── css, js, fonts -> api/public/*   ← aset panel admin (Filament)
├── storage -> api/public/storage
└── api/                 ← app root Laravel
    ├── .env
    ├── app/, vendor/, artisan, routes/
    ├── bootstrap/cache/
    ├── storage/         ← TIDAK ikut arsip; dibuat manual (langkah 2)
    │   ├── app/public/
    │   ├── framework/{cache/data,sessions,views}
    │   ├── logs/
    │   └── cpanel-deploy/
    └── public/          ← direktori biasa, BUKAN docroot
        ├── index.php, .htaccess, build/, css/, js/, fonts/
        ├── cpanel-deploy.php   ← shim endpoint (dipasang sekali)
        └── storage -> ../../storage/app/public
```

Pemetaan arsip saat deploy:

| Arsip | Target | Mendarat di |
|---|---|---|
| `backend/` | `backend` | `gemma449/api/` (`public/*` → `api/public/`) |
| `frontend/dist/` | `web` | docroot `gemma449/` |

Tanpa pemisahan target, arsip SPA akan masuk ke `api/` dan tidak pernah
dilayani karena `.htaccess` me-rewrite semua `/api/...` ke front controller.

## 1. Siapkan subdomain (cPanel)

1. **Domains → Create A New Domain** → `gemma449.followtheline.id`,
   docroot `/home/permatat/followtheline.id/gemma449` (tanpa `public_html`).
2. **MultiPHP Manager** → set domain ke **PHP 8.4**.
3. **MySQL Databases** → buat database + user, catat nama/host/user/password.

## 2. Siapkan folder & symlink

Arsip deploy **sengaja mengecualikan** `storage/` dan `.env` agar deploy ulang
tidak menghapus data. Konsekuensinya folder itu harus dibuat manual di sini —
tanpa `storage/framework/*` Laravel langsung 500 (`Please provide a valid cache
path`).

```bash
DOC=~/followtheline.id/gemma449

# struktur yang tidak pernah ikut arsip
mkdir -p "$DOC/api/public" \
         "$DOC/api/bootstrap/cache" \
         "$DOC/api/storage/app/public" \
         "$DOC/api/storage/framework/cache/data" \
         "$DOC/api/storage/framework/sessions" \
         "$DOC/api/storage/framework/views" \
         "$DOC/api/storage/logs" \
         "$DOC/api/storage/cpanel-deploy"
chmod -R 775 "$DOC/api/storage" "$DOC/api/bootstrap/cache"

# symlink storage
ln -sfn ../storage/app/public "$DOC/api/public/storage"
ln -sfn api/public/storage    "$DOC/storage"

# aset panel admin (Filament) dilayani dari docroot: /css, /js, /fonts
for d in css js fonts; do
  ln -sfn "api/public/$d" "$DOC/$d"
done
```

## 3. Pasang handler deploy (sekali)

Unggah dari repo ke `api/storage/cpanel-deploy/`:

- `backend/deploy-cpanel/cpanel-deploy-handler.php`
- `backend/deploy-cpanel/deploy-lib.php`

Lalu buat secret HMAC:

```bash
php ~/followtheline.id/gemma449/api/storage/cpanel-deploy/cpanel-deploy-handler.php --generate-secret
```

Perintah mencetak 64 hex **sekali**. Simpan sebagai secret GitHub
`CPANEL_DEPLOY_SECRET`. Nilai yang sama tersimpan di
`api/storage/cpanel-deploy/deploy-secret.txt` (0600, di luar docroot).

## 4. Pasang shim di `api/public/`

```bash
cp backend/deploy-cpanel/cpanel-deploy.php ~/followtheline.id/gemma449/api/public/cpanel-deploy.php
```

Endpoint publik:

```
https://gemma449.followtheline.id/api/public/cpanel-deploy.php
```

Handler asli sengaja berada di `storage/` (di luar docroot). Shim hanya memuat
handler. Mitigasi: (1) handler di luar docroot, (2) HMAC + nonce + jendela 600
detik, (3) `GET` ditolak `405`.

## 5. Pasang `.htaccess` root

Salin `deploy/cpanel/htaccess-root.txt` ke
`~/followtheline.id/gemma449/.htaccess` (sesuaikan `error_log` bila user cPanel
bukan `permatat`), lalu verifikasi segera:

```bash
for p in / /up /api/price-categories /admin/login; do
  printf '%-24s %s\n' "$p" "$(curl -s -o /dev/null -w '%{http_code}' https://gemma449.followtheline.id$p)"
done
```

Sebelum deploy pertama, `/up` dan `/api/...` masih 404/500 — itu normal karena
`api/public/index.php` baru ada setelah arsip backend masuk. Uji ulang setelah
langkah 6–8.

## 6. Isi `.env` backend

Salin `backend/deploy/cpanel/env.example` → `~/followtheline.id/gemma449/api/.env`
lalu isi minimal:

- `APP_KEY` — biarkan kosong, diisi di langkah 8 dengan `php artisan key:generate --force`
- `APP_URL=https://gemma449.followtheline.id` — **tanpa** akhiran `/api`.
  Bila memakai `/api`, `asset()` menghasilkan `/api/css/...` yang ditelan
  rewrite `^api` sehingga panel admin tampil tanpa CSS/JS
- `CORS_ALLOWED_ORIGINS=https://gemma449.followtheline.id`
- `DB_HOST=localhost`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` — nama
  lengkap dari cPanel (biasanya berawalan nama user, mis. `permatat_gemma449`);
  user DB harus ditempel ke database itu dengan **ALL PRIVILEGES**
- `ADMIN_EMAIL`, `ADMIN_PASSWORD` (min 12 karakter)
- Midtrans: `MIDTRANS_NOTIFICATION_URL` dan `MIDTRANS_FINISH_REDIRECT_URL` sudah
  terisi default domain ini — **jangan dikosongkan**, tanpa itu webhook jatuh ke
  endpoint default Midtrans dan status pembayaran tidak pernah berubah
- WhatsApp bila dipakai (`WHATSAPP_NOTIFICATIONS_ENABLED`)

`storage/` dan `.env` **tidak pernah** dikirim lewat arsip, jadi aman.

## 7. Aktifkan deploy di GitHub

`imamkoo/gemma449` → Settings → Secrets and variables → Actions:

- Secret: `CPANEL_DEPLOY_SECRET`
- Variables: `CPANEL_DEPLOY_READY=true`
- Opsional (override default): `CPANEL_DEPLOY_ENDPOINT`, `CPANEL_API_BASE_URL`,
  `CPANEL_PUBLIC_URL`

Setelah variabel diisi, jalankan **Actions → Deploy (cPanel via arsip
bertahap) → Run workflow** (`workflow_dispatch`) sekali untuk deploy pertama.

> **Deploy pertama bisa gagal di smoke test** bila database belum dimigrasi:
> file sudah ter-deploy, tetapi `/api/price-categories` masih 500. Jalankan
> langkah 8 (migrate + seeder), lalu **Run workflow** sekali lagi — run kedua
> harus hijau. Jangan nonaktifkan smoke test untuk "melewati" masalah ini.

## 8. Migrasi & seeder (pertama kali)

Setelah deploy pertama sukses:

```bash
cd ~/followtheline.id/gemma449/api
PHP="$(command -v ea-php84 || echo /opt/cpanel/ea-php84/root/usr/bin/php)"
"$PHP" artisan key:generate --force
"$PHP" artisan migrate --force
"$PHP" artisan db:seed --force
"$PHP" artisan storage:link   # boleh "already exists" — symlink dibuat di langkah 2
"$PHP" artisan optimize
```

## Verifikasi

```bash
curl -s -o /dev/null -w 'GET  -> %{http_code}\n' https://gemma449.followtheline.id/api/public/cpanel-deploy.php   # harap 405
curl -s -o /dev/null -w 'POST -> %{http_code}\n' -X POST https://gemma449.followtheline.id/api/public/cpanel-deploy.php   # harap 403
```

## Katalog masjid

GEMMA449 **tidak** membawa snapshot DMI Jakarta milik jejaka500. Migrasi
`import_dmi_mosques_dki` melewati impor bila snapshot tidak ada, sehingga
deploy tetap sukses. Isi katalog masjid lewat menu **Masjid** di panel admin,
atau jalankan `php artisan mosque:import --province=9 --dry-run` lebih dulu
(perintah ini menarik seluruh Jawa Barat, jadi periksa hasil dry-run sebelum
menulis).

## Penyelesaian masalah

| Gejala | Arti | Tindakan |
|---|---|---|
| `405 POST required` | endpoint hidup, request GET | normal untuk probe |
| `403 Forbidden` | tanda tangan ditolak | cek `[cpanel-deploy] auth ditolak: …` di error log PHP |
| `413` pada potongan | potongan melebihi `upload_max_filesize` | naikkan batas atau turunkan `DEFAULT_CHUNK_BYTES` |
| `422 Unsafe archive entry` | entri arsip berbahaya/tidak dikenal | periksa isi arsip — jangan diteruskan |
| `422 Invalid deploy target` | header `X-Deploy-Target` bukan `backend`/`web` | periksa manifest dari `build_archive.py` |
| `422 Archive checksum mismatch` | potongan tidak utuh | ulangi deploy |
| `500` di endpoint | handler fatal | cek `~/logs/php.error.log` |
| `500` di `/up` dan `/admin/login` setelah deploy | `.env`/`APP_KEY` belum ada, atau folder `storage/framework` belum dibuat | jalankan langkah 8; pastikan langkah 2 dijalankan |
| `Please provide a valid cache path` | `storage/framework/views` tidak ada | ulangi `mkdir -p` langkah 2, lalu `chmod -R 775 storage` |
| Panel admin tampil tanpa CSS/JS | `APP_URL` memakai akhiran `/api`, atau symlink `css`/`js`/`fonts` belum ada | betulkan `APP_URL` (tanpa `/api`), ulangi symlink langkah 2, lalu `php artisan optimize` |
| `/storage/...` mengembalikan HTML SPA | symlink `storage` atau `api/public/storage` rusak | ulangi symlink langkah 2; pastikan `storage/app/public` ada |

## Menaikkan batas upload (opsional)

cPanel → **MultiPHP INI Editor** → domain `gemma449.followtheline.id`:

```
upload_max_filesize = 8M
post_max_size       = 16M
```
