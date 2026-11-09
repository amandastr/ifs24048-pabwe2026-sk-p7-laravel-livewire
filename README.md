# ifs24048-pabwe2026-sk-p7-laravel-livewire

Aplikasi **Stock Barang** (inventaris) dengan arsitektur **Feature-First**, dibangun menggunakan **Laravel 13**, **Livewire 4**, **MySQL/MariaDB**, **PhpSpreadsheet**, dan integrasi **OpenAI**. Studi kasus PABWE 2026 P7 (domain Todo pada latihan diganti menjadi Stock).

## Peran & Fitur

| Peran | Kemampuan |
| --- | --- |
| Guest | Login dan register. Rute `/` diarahkan ke login. |
| Member | Mengelola stok miliknya sendiri saja (`/stocks`). |
| Admin | Melihat daftar user + jumlah stok (`/admin/users`) dan stok tiap user secara **read-only** (`/admin/users/{user}/stocks`). |

Fitur member:

- Daftar stok dengan pencarian (nama, SKU, deskripsi), filter status, dan pagination.
- CRUD stok lewat modal: nama, SKU, deskripsi Markdown (Toast UI), quantity, unit, price, min_stock.
- **Adjust quantity (+/-)** dari list maupun detail. Status ikut menyesuaikan otomatis:
  - `out_of_stock` jika quantity = 0
  - `low_stock` jika quantity <= min_stock
  - `available` jika quantity > min_stock
- Detail stok (`/stocks/{stock}`): ubah data, cover image, deskripsi Markdown.
- Import / export Excel (PhpSpreadsheet). Kolom: No, Name, SKU, Description, Qty, Unit, Price, Min Stock, Status. Saat import, status selalu dihitung ulang dari Qty dan Min Stock.
- Generate item stok dengan AI, preview, pilih, lalu simpan.
- Profil (data diri, password, foto).

Keamanan: middleware `role` dan `owns.stock`, `StockPolicy`, rate limit `1000-per-second`, opsi `FORCE_HTTPS`.

Form login memakai id `login-username`, `login-password`, `login-submit`.

## Rute

| Method | URI | Keterangan |
| --- | --- | --- |
| GET | `/` | Guest -> login, admin -> users, member -> stocks |
| GET | `/login`, `/register` | Guest |
| POST | `/logout` | Auth |
| GET | `/stocks` | Daftar stok (member, `owns.stock`) |
| GET | `/stocks/{stock}` | Detail stok |
| GET | `/profile` | Profil |
| GET | `/admin/users` | Admin |
| GET | `/admin/users/{user}/stocks` | Admin, read-only |

## Menjalankan

```bash
composer install
cp .env.example .env
php artisan key:generate
# buat database: pabwe_p7_stock, lalu sesuaikan DB_* (dan OPENAI_*) di .env
php artisan migrate:fresh --seed
php artisan storage:link
npm install --ignore-scripts
npm run build
composer run dev
```

Akun seeder: `admin` / `admin` dan `member` / `member` (member memiliki 3 contoh stok).

Variabel penting di `.env`: `DB_DATABASE=pabwe_p7_stock`, `FORCE_HTTPS`, `OPENAI_ENDPOINT`, `OPENAI_KEY`, `OPENAI_MODEL`.

## Pengujian

```bash
composer test     # phpunit --coverage-text (target coverage app/ = 100%)
composer audit    # harus bersih
```

Butuh ekstensi PHP `gd`, `zip`, `mbstring`, `pdo_sqlite` dan driver coverage (`pcov` atau `xdebug` dengan `xdebug.mode=coverage`). Artefak uji (`coverage-*`, `coverage.txt`, `junit.xml`, dll.) sudah diabaikan oleh `.gitignore`. Jangan commit `.env`.

## Struktur

```
app/
├── Features/
│   ├── Admin/   (Livewire: AdminUserStockList, UserList | Services: AdminUserService)
│   ├── Ai/      (Contracts: AiClientInterface | DTO: GeneratedStockItem | Services: OpenAiStockGeneratorService)
│   ├── Auth/    (Actions: LoginUserAction, RegisterUserAction | Livewire: Login, Register)
│   ├── Profile/ (Livewire: Profile | Services: ProfileService)
│   └── Stock/   (Contracts, Enums, Livewire, Models, Policies, Repositories, Services)
├── Http/Middleware/ (EnsureRole, EnsureStockOwnership)
├── Models/User.php
└── Providers/AppServiceProvider.php
```

CI: lihat `Jenkinsfile` (composer audit gagal jika ada kerentanan, coverage gagal jika kurang dari 100%).

## Deploy ke Vercel (container FrankenPHP)

File yang dipakai: `vercel.json`, `Dockerfile.vercel`, `Caddyfile`, `.dockerignore`. Panduan resmi: https://vercel.com/kb/guide/laravel-php-with-docker

1. Siapkan database MySQL/MariaDB yang bisa diakses dari internet, lalu jalankan migrasi dari komputer lokal: `php artisan migrate:fresh --seed --force` (dengan variabel `DB_*` mengarah ke database tersebut).
2. Di Project Settings Vercel: Framework Preset = **Other**, kosongkan Build/Install/Output Command override.
3. Isi Environment Variables: `APP_KEY` (dari `php artisan key:generate --show`), `APP_URL`, `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `MYSQL_ATTR_SSL_CA` (jika penyedia database mewajibkan TLS), `FORCE_HTTPS=true`, `SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=sync`, dan `OPENAI_KEY` (opsional).
4. Redeploy. Catatan: sistem berkas container tidak permanen, sehingga foto profil dan cover yang diunggah tidak tersimpan permanen kecuali disk `public` dialihkan ke object storage.

## Deploy ke Vercel (FrankenPHP) + Aiven MySQL

1. Taruh `docker/aiven-ca.pem` (lihat `docker/README.md`) dan commit.
2. Dari lokal, jalankan migrasi + seeder ke Aiven (sekali):
   `DB_HOST=... DB_PORT=... DB_DATABASE=... DB_USERNAME=... DB_PASSWORD=... MYSQL_ATTR_SSL_CA=docker/aiven-ca.pem php artisan migrate --force && php artisan db:seed --force`
3. Environment Variables di Vercel: `APP_KEY` (`php artisan key:generate --show`), `APP_ENV=production`, `APP_DEBUG=false`,
   `APP_URL`, `FORCE_HTTPS=true`, `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`,
   `MYSQL_ATTR_SSL_CA=/app/docker/aiven-ca.pem`, `SESSION_DRIVER=cookie`, `CACHE_STORE=array`, `LOG_CHANNEL=stderr`,
   serta `OPENAI_KEY` / `OPENAI_ENDPOINT` / `OPENAI_MODEL` bila memakai AI.
4. Settings -> Domains, sesuaikan `APP_URL`, lalu **Redeploy** (perubahan env baru berlaku setelah redeploy).
5. Cek `/up` lalu `/login`.

Catatan: filesystem container tidak permanen, jadi cover stok dan foto profil hasil upload tidak tahan lama di Vercel.
