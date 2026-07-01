<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Hook instalasi plugin.
 *
 * Sengaja tidak melakukan insert data awal.
 * Semua data akademik seperti tahun ajaran, kurikulum, jurusan, kelas,
 * mata pelajaran, ekstrakurikuler, mitra, dan aturan notifikasi
 * harus dibuat melalui menu admin plugin.
 */
function xmldb_local_akademikmonitor_install(): void {
    // Tidak ada data dummy/seed.
}