# Akademik Monitor

![Moodle](https://img.shields.io/badge/Moodle-5.1.3-orange)
![PHP](https://img.shields.io/badge/PHP-8.2+-blue)
![License](https://img.shields.io/badge/License-GPL%20v3-green)

## Tentang Proyek

**Plugin AkademikMonitor** merupakan plugin **Local Plugin** yang dikembangkan untuk platform **Moodle 5.1.3**. Plugin ini bertujuan membantu sekolah dalam mengatur dan memantau perkembangan akademik siswa secara terintegrasi melalui Moodle, mulai dari pembentukan tahun ajaran, kurikulum, mata pelajaran, jurusan, kelas, pemilihan guru, generate course, pemetaan capaian pembelajaran dan tujuan pembelajaran, pengatuaran kktp, monitoring nilai, notifikasi telegram, presensi, hingga penyusunan rapor.

Plugin ini dikembangkan sebagai bagian dari penelitian **Tugas Akhir Program Studi Teknologi Rekayasa Perangkat Lunak**.

---

# Fitur

Plugin menyediakan berbagai fitur untuk mendukung pengelolaan akademik sekolah.

### Dashboard 
- Ringkasan informasi menu

### Master Data
- Tahun Ajaran
- Jurusan
- Kelas
- Mata Pelajaran
- Kurikulum
- Mitra
- Ekskul

### Pembelajaran
- Generate Course Moodle
- Mapping Course
- Capaian Pembelajaran (CP)
- Tujuan Pembelajaran (TP)
- KKTP
- Kartu Ujian

### Monitoring
- Notifikasi Otomatis Telegram
- Pengingat Ujian dan Deadline
- Monitoring Nilai
- Monitoring Presensi Siswa
- Monitoring Presensi Guru

### Wali Kelas
- Dashboard Wali Kelas
- Monitoring Akademik
- Monitoring Presensi
- PKL (Untuk Kelas 12)
- Ekstrakurikuler
- Rapor

### Guru
- Mengelola Tujuan Pembelajaran

### Semua User
- Menghubungkan Akun Telegram

### Integrasi
- Moodle Gradebook
- Moodle Attendance
- Telegram Bot Notification

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

- Moodle **5.1.3**
- PHP **8.2** atau lebih baru
- MySQL / MariaDB
- Web Server Apache atau Nginx

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
- download repository bentuk zip
- buka moodle admin
- masuk ke site administrator -> plugin -> install plugin
- masukkan zip plugin
- klik install

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

Plugin mendukung pengiriman notifikasi menggunakan Telegram Bot.

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
| Manager | Monitoring |
| Teacher | Input nilai dan presensi |
| Editing Teacher | Mengelola course |
| Wali Kelas | Monitoring kelas dan rapor |
| Student | Melihat perkembangan akademik |

---

# Cara Menggunakan

### Administrator

- Mengelola data master
- Mengatur kurikulum
- Generate Course
- Mapping Course
- Mengatur KKTP
- Mengelola Telegram

### Guru

- Mengelola aktivitas pembelajaran
- Menginput nilai
- Menginput presensi

### Wali Kelas

- Monitoring akademik siswa
- Monitoring presensi
- Mengelola rapor
- Monitoring PKL
- Monitoring ekstrakurikuler

### Siswa

- Melihat perkembangan nilai
- Melihat presensi
- Melihat status ketuntasan

---

# Kontribusi

Kontribusi dalam bentuk laporan bug, usulan fitur, sangat terbuka untuk pengembangan lebih lanjut.

---

# Lisensi

Plugin ini dikembangkan untuk keperluan penelitian dan pengembangan sistem monitoring akademik berbasis Moodle.

Menggunakan lisensi **GNU General Public License v3.0 (GPL-3.0)** sesuai dengan lisensi Moodle.

---

# Pengembang

**Ayu Kurnia Sari dan Tri Eva Yunia**

Program Studi Teknologi Rekayasa Perangkat Lunak

Jurusan Bisnis dan Informatika

Politeknik Negeri Banyuwangi

2026
