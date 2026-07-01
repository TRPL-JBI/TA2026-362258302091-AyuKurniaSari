# Akademik Monitor

<p align="center">
  <img src="https://upload.wikimedia.org/wikipedia/commons/c/c6/Moodle-logo.svg" width="200">
</p>

<p align="center">
<b>Local Plugin Moodle 5.1.3 untuk Monitoring Akademik Siswa</b><br>
Dikembangkan sebagai bagian dari penelitian Tugas Akhir<br>
Program Studi Teknologi Rekayasa Perangkat Lunak
</p>

---

![Moodle](https://img.shields.io/badge/Moodle-5.1.3-orange)
![PHP](https://img.shields.io/badge/PHP-8.2+-blue)
![Database](https://img.shields.io/badge/Database-MySQL%20%7C%20MariaDB-blue)
![Telegram](https://img.shields.io/badge/Telegram-Bot-26A5E4)
![License](https://img.shields.io/badge/License-GPL%20v3-green)

## Tentang Proyek

**Plugin AkademikMonitor** merupakan **Local Plugin Moodle** yang dikembangkan untuk platform **Moodle 5.1.3**. Plugin ini bertujuan membantu sekolah dalam mengatur dan memantau perkembangan akademik siswa secara terintegrasi melalui Moodle, mulai dari pembentukan tahun ajaran, kurikulum, mata pelajaran, jurusan, kelas, pemilihan guru, generate course, pemetaan capaian pembelajaran dan tujuan pembelajaran, pengatuaran kktp, monitoring nilai, notifikasi telegram, presensi, hingga penyusunan rapor.

Plugin ini dikembangkan sebagai bagian dari penelitian **Tugas Akhir Program Studi Teknologi Rekayasa Perangkat Lunak**.

---

# Fitur

Plugin menyediakan berbagai fitur untuk mendukung pengelolaan akademik sekolah.

### Dashboard 
Berisi Ringkasan informasi menu

### Master Data
1. Tahun Ajaran
2. Jurusan
3. Kelas
4. Mata Pelajaran
5. Kurikulum
6. Mitra
7. Ekskul

### Pembelajaran
1. Generate Course Moodle
2. Mapping Course
3. Capaian Pembelajaran (CP)
4. Tujuan Pembelajaran (TP)
5. KKTP
6. Kartu Ujian

### Monitoring
1. Notifikasi Otomatis Telegram
2. Pengingat Ujian dan Deadline
3. Monitoring Nilai
4. Monitoring Presensi Siswa
5. Monitoring Presensi Guru

### Wali Kelas
1. Dashboard Wali Kelas
2. Monitoring Akademik
3. Monitoring Presensi
4. PKL (Untuk Kelas 12)
5. Ekstrakurikuler
6. Rapor

### Guru
1. Mengelola Tujuan Pembelajaran

### Semua User
1. Menghubungkan Akun Telegram
2. Mendapatkan Notifikasi Otomatis

### Integrasi
1. Moodle Gradebook
2. Moodle Attendance
3. Telegram Bot Notification

---

# Teknologi

| Komponen | Teknologi |
|----------|-----------|
| LMS | Moodle 5.1.3 |
| Bahasa Pemrograman | PHP 8.2+ |
| Database | MariaDB / MySQL |
| Frontend | HTML, CSS, JavaScript|
| Excel | PhpSpreadsheet |
| PDF | Dompdf |
| API | Moodle API |

---

# Persyaratan

1. Moodle **5.1.3**
2. PHP **8.2** atau lebih baru
3. MySQL / MariaDB
4. Web Server Apache atau Nginx

---

# Instalasi

## 1. Clone Repository

```bash
git clone https://github.com/TRPL-JBI/TA2026-362258302091-AyuKurniaSari.git
```

atau download repository dalam bentuk ZIP.

---

## 2. Salin Plugin

Salin folder

```
akademikmonitor
```

ke dalam direktori

```
moodle/local/
```

sehingga menjadi

```
moodle
└── local
    └── akademikmonitor
```

---

## 3. Login sebagai Administrator

Masuk ke Moodle menggunakan akun Administrator.

---

## 4. Upgrade Database

Buka

```
Site administration
→ Notifications
```

Moodle akan mendeteksi plugin baru.

Klik

```
Upgrade Moodle Database
```

hingga proses selesai.

---
# Instalasi cara ke 2
1. download repository bentuk zip
2. buka moodle admin
3. masuk ke site administrator -> plugin -> install plugin
4. masukkan zip plugin
5. klik install

# Konfigurasi Awal

Setelah instalasi selesai, lakukan konfigurasi berikut.
1. Atur Pengaturan Akademik Monitoring
2. Masukkan Informasi Sekolah
3. Tambahkan Tahun Ajaran
4. Tambahkan Jurusan
5. Tambahkan Mata Pelajaran
6. Buat Kurikulum
7. Tambahkan Kelas
8. Tambahkan Peserta Kelas
9. Generate Course
10. Mapping Course
11. Atur KKTP
12. Input CP dan TP

Setelah seluruh konfigurasi selesai, plugin siap digunakan.

---

# Integrasi Telegram
<br><br>
<p align="center">
<img src="https://upload.wikimedia.org/wikipedia/commons/8/82/Telegram_logo.svg" width="150">
<p align="center">
<b>Plugin mendukung integrasi dengan Telegram Bot sebagai media pengiriman notifikasi akademik. Integrasi ini memungkinkan sistem mengirimkan informasi secara otomatis kepada pengguna, seperti pengingat ujian, tenggat waktu, perkembangan nilai, informasi presensi, dan berbagai pemberitahuan akademik lainnya.</b>
</p>


Langkah konfigurasi:

1. Buat Bot melalui BotFather.
2. Salin Token Bot.
3. Masukkan Token pada menu Pengaturan Telegram.
4. Simpan konfigurasi.
5. Uji koneksi Bot.
6. gunakan Cron Server sesuai konfigurasi Moodle.

---


# Hak Akses

Plugin mendukung beberapa peran pengguna.

| Role | Akses |
|------|-------|
| Administrator | Seluruh fitur |
| Teacher | Mengelola Course, Input nilai, Tujuan Pembelajaran, Monitoring Siswa dan presensi |
| Wali Kelas | Monitoring kelas dan penyusuan rapor |
| Student | Melihat perkembangan akademik dan pengingat akademik |

---

# Cara Menggunakan

### Administrator

1. Mengelola data master
2. Mengatur kurikulum
3. Generate Course
4. Mapping Course
5. Mengatur KKTP
6. Mengelola Telegram

### Guru

1. Mengelola aktivitas pembelajaran
2. Menginput nilai
3. Menginput presensi

### Wali Kelas

1. Monitoring akademik siswa
2. Monitoring presensi
3. Mengelola rapor
4. Monitoring PKL
5. Monitoring ekstrakurikuler

### Siswa

1. Melihat perkembangan nilai
2. Melihat presensi
3. Melihat status ketuntasan

### Semua User
Menyambungkan Akun Telegram untuk mendapatkan notifikasi otomatis
---

# Kontribusi

Kontribusi dalam bentuk laporan bug, usulan fitur, sangat terbuka untuk pengembangan lebih lanjut.

---

# Lisensi

Plugin ini dikembangkan untuk keperluan penelitian dan pengembangan sistem monitoring akademik berbasis Moodle.

---

# Pengembang

**Ayu Kurnia Sari dan Tri Eva Yunia**

Program Studi Teknologi Rekayasa Perangkat Lunak

Jurusan Bisnis dan Informatika

Politeknik Negeri Banyuwangi

2026
