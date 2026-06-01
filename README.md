# AI Study Helper - Sistem Tanya Jawab & Belajar

Aplikasi web untuk mahasiswa. Tanya apa saja ke AI, atau upload materi untuk fitur lanjutan.

## Fitur Utama

### 1. Tanya Jawab (Tanpa Materi)
Tanya apa saja langsung ke AI — matematika, logika, konsep pemrograman, dll.
**Tidak perlu upload materi.**

### 2. Ringkasan (Butuh Materi)
Upload PDF/TXT atau paste teks, AI buat ringkasan poin-poin penting.

### 3. Soal Latihan (Butuh Materi)
Generate 10 soal pilihan ganda dengan jawaban & penjelasan.

### 4. Flashcard (Butuh Materi)
Buat 15 kartu hafalan untuk belajar cepat.

## Format File yang Didukung

- **PDF** (.pdf) — File PDF dengan teks asli
- **TXT** (.txt) — File teks biasa
- **Paste Teks** — Copy-paste langsung ke text area

## Struktur File

```
ai-study-helper/
├── index.html          # Frontend (UI lengkap)
├── api.php             # Backend API
├── config.php          # Konfigurasi & fungsi Gemini API
├── prompts.php         # Template prompt untuk AI
├── composer.json       # Dependensi PHP (valid JSON)
├── .env.example        # Template konfigurasi
└── README.md           # Dokumentasi
```

## Cara Install

### 1. Persyaratan
- PHP >= 7.4
- Composer
- Web server (Apache/Nginx) atau PHP built-in server

### 2. Install Dependensi

```bash
cd ai-study-helper
composer install
```

### 3. Konfigurasi API Key

File `.env` sudah terisi dengan API key Gemini milikmu. Kalau mau ganti:

```bash
cp .env.example .env
# Edit .env kalau perlu
```

### 4. Jalankan Server

```bash
php -S localhost:8000
```

Buka browser: `http://localhost:8000`

## Cara Penggunaan

### Tanya Jawab (Fitur Utama)
1. Tulis pertanyaan di kotak "Tanya Jawab"
2. Klik **"Tanya Sekarang"**
3. AI akan menjawab langsung

**Contoh pertanyaan:**
- `1 + 1 = ?`
- `Apa yang dimaksud dengan inheritance dalam OOP?`
- `Jelaskan proses fotosintesis`
- `Bagaimana cara kerja quick sort?`

### Fitur Lanjutan (Butuh Materi)
1. Klik **"+ Tambah Materi"**
2. Upload PDF/TXT atau paste teks materi kuliah
3. Pilih fitur: Ringkasan / Soal / Flashcard
4. Klik **"Proses Materi"**

## Troubleshooting

| Masalah | Solusi |
|---------|--------|
| "composer.json tidak valid" | Pastikan file tidak corrupt. Coba download ulang. |
| "Class not found" | Jalankan `composer install` di folder project. |
| "Gagal membaca PDF" | PDF harus berisi teks, bukan gambar scan. |
| "API Error" | Periksa API key di `.env`. Pastikan valid. |
| "File too large" | Ubah `upload_max_filesize` di `php.ini` |
| Frontend tidak responsif | Cek browser console (F12) untuk error. |

## Keamanan

- Jangan commit file `.env` ke repository publik
- API key sudah di-hardcode di `.env.example` untuk kemudahan, tapi untuk production sebaiknya dipisah

## Lisensi

MIT License
