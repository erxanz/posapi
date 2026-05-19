# POS API (Laravel)

<div align="center">
  <img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="260" alt="Laravel Logo" />
</div>

<p align="center">
  <b>Backend POS API</b> berbasis <a href="https://laravel.com/" target="_blank">Laravel</a>.
</p>

---

## Stack / Teknologi

- **Language:** PHP
- **Framework:** Laravel
- **Auth:** Laravel Sanctum
- **Authorization:** spatie/laravel-permission
- **Frontend Build:** Vite + Tailwind CSS
- **Real-time/Event:** Laravel Reverb (broadcasting)
- **Payment Gateway:** Midtrans (Snap) via `midtrans/midtrans-php`
- **Reports/Exports:** Maatwebsite Excel + DOMPDF
- **Queue/Background Jobs:** Laravel Queue (queue:listen)

---

## Requirement

- PHP **8.2+**
- Composer
- Node.js & npm
- Database (disarankan MySQL/PostgreSQL; default project menggunakan SQLite jika tidak diubah)

---

## Cara Menjalankan Project

### 1) Install Dependencies

```bash
composer install
npm install
```

### 2) Konfigurasi Environment

1. Copy environment:

```bash
copy .env.example .env
```

2. Generate APP Key:

```bash
php artisan key:generate
```

3. Set konfigurasi database di `.env` (contoh):

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=posapi
DB_USERNAME=root
DB_PASSWORD=
```

> Jika Anda tidak mengubah `DB_CONNECTION`, project dapat berjalan dengan **SQLite** menggunakan file `database/database.sqlite`.

### 3) Database Migration & Seeder

```bash
php artisan migrate --seed
```

### 4) Jalankan Server & Assets

Dalam 1 terminal (mode development):

```bash
npm run dev
```

Perintah `npm run dev` akan menjalankan:
- `php artisan serve`
- `php artisan queue:listen`
- `php artisan pail` (profiling/monitoring)
- `vite dev`

> Jika Anda ingin menjalankan manual, gunakan perintah berikut:

```bash
php artisan serve
npm run dev
php artisan queue:listen --tries=1
```

---

## Endpoint / API Base URL

Base URL mengikuti `APP_URL` pada `.env`.

Contoh:
- `http://localhost:8000`
- `/api/...`

---

## Midtrans Payment (QRIS & Card)

Detail setup Midtrans ada di file: **`MIDTRANS_SETUP.md`**.

Ringkasnya:
- Gunakan environment:
  - `MIDTRANS_SERVER_KEY`
  - `MIDTRANS_IS_PRODUCTION`
- Terdapat webhook callback endpoint:
  - `POST /api/v1/midtrans/callback`

---

## Build untuk Production (Frontend)

```bash
npm run build
```

---

## Struktur Folder (Ringkas)

- `app/` : seluruh logic aplikasi (Controllers, Models, Services, Events)
- `routes/` : route API/webhook
- `resources/` : view/template + assets CSS/JS
- `database/` : migrations & seeders
- `public/` : asset public

---

## Catatan

- Pastikan konfigurasi `REVERB_*` untuk broadcasting real-time jika menggunakan Laravel Reverb.
- Pastikan webhook Midtrans mengarah ke domain yang benar (sesuaikan dengan `APP_URL`).

---

## Kontribusi

Jika ingin berkontribusi, silakan ikuti standar kontribusi pada repo Anda.

---

## Keamanan

Jika menemukan kerentanan keamanan, laporkan melalui kanal yang disepakati tim.

---

## License

Project ini menggunakan Laravel framework yang bersifat open-source di bawah **MIT License**.

