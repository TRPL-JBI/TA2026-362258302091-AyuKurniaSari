<?php
namespace local_akademikmonitor\service;

defined('MOODLE_INTERNAL') || die();

class kktp_service {

// Mengisi dropdown jurusan.
    public static function get_jurusan_options(): array {
        global $DB;

        // Ambil seluruh data jurusan dari tabel jurusan
        $rows = $DB->get_records('jurusan', null, 'nama_jurusan ASC');
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int)$r->id,
                'name' => (string)$r->nama_jurusan
            ];
        }
        return $out;
    }

    public static function get_tingkat_options(): array {
        return [
            ['value' => 'X', 'label' => 'X'],
            ['value' => 'XI', 'label' => 'XI'],
            ['value' => 'XII', 'label' => 'XII'],
        ];
    }

    /**
     * List KKTP untuk jurusan + tingkat.
     * Tanpa SELECT manual:
     * - Ambil kurikulum_jurusan (id yang terkait jurusan)
     * - Ambil kurikulum_mapel berdasarkan id_kurikulum_jurusan IN (...) dan tingkat
     * - Ambil mata_pelajaran (batch)
     * - Gabung di PHP, lalu sort by nama mapel
     */
    public static function list_kktp(int $jurusanid, string $tingkat): array {
        global $DB;

        if ($jurusanid <= 0) {
            return [];
        }

        // Nama jurusan (biar kolom jurusan tetap terisi).
        $jurusan = $DB->get_record('jurusan', ['id' => $jurusanid], 'id,nama_jurusan', IGNORE_MISSING);
        $jurusanname = $jurusan ? (string)$jurusan->nama_jurusan : '';

        // 1) Ambil semua kurikulum_jurusan milik jurusan ini.
        $kjs = $DB->get_records('kurikulum_jurusan', ['id_jurusan' => $jurusanid], 'id ASC', 'id');
        if (!$kjs) {
            return [];
        }

        $kjids = array_map('intval', array_keys($kjs));
        list($insql, $params) = $DB->get_in_or_equal($kjids, SQL_PARAMS_NAMED);
        $params['tingkat'] = $tingkat;

        // 2) Ambil kurikulum_mapel untuk tingkat tertentu.
        $select = "id_kurikulum_jurusan $insql AND tingkat_kelas = :tingkat";
        $kms = $DB->get_records_select(
            'kurikulum_mapel',
            $select,
            $params,
            'id ASC',
            'id, id_mapel, tingkat_kelas, kktp'
        );

        if (!$kms) {
            return [];
        }

        // 3) Ambil mata_pelajaran (batch) untuk nama mapel.
        $mapelids = [];
        foreach ($kms as $km) {
            if (!empty($km->id_mapel)) {
                $mapelids[] = (int)$km->id_mapel;
            }
        }
        $mapelids = array_values(array_unique($mapelids));

        $mapelsbyid = [];
        if ($mapelids) {
            // Penting: pilih "id" dulu biar key unik (dan aman untuk fullname-like warnings).
            $mapelsbyid = $DB->get_records_list('mata_pelajaran', 'id', $mapelids, '', 'id,nama_mapel');
        }

        // 4) Gabungkan jadi rows untuk template.
        $rows = [];
        foreach ($kms as $km) {
            $mapelname = '-';
            $mapelid = (int)($km->id_mapel ?? 0);

            if ($mapelid > 0 && isset($mapelsbyid[$mapelid])) {
                $mapelname = (string)$mapelsbyid[$mapelid]->nama_mapel;
            } else if ($mapelid > 0) {
                $mapelname = 'Mapel ID: ' . $mapelid;
            }

            $rows[] = [
                'kmid' => (int)$km->id,
                'mapel' => $mapelname,
                'jurusan' => $jurusanname,
                'tingkat' => (string)$km->tingkat_kelas,
                'kktp' => is_null($km->kktp) ? 0 : (int)$km->kktp,
            ];
        }

        // Sort mapel ASC (tanpa ORDER BY SQL).
        usort($rows, fn($a, $b) => strcasecmp($a['mapel'], $b['mapel']));

        return $rows;
    }

    /**
     * Simpan KKTP banyak item.
     * Dibuat simpel dan tidak butuh SELECT manual:
     * - langsung set_field per id (tanpa get_record dulu)
     *
     * Catatan:
     * - Ini masih 1 update per baris (wajar untuk tanpa SQL custom).
     * - Kalau nanti mau super cepat, baru kita bikin bulk update CASE (itu butuh SQL manual UPDATE).
     */
    public static function update_bulk(array $kktpbyid): void {
        global $DB;

        foreach ($kktpbyid as $kmid => $val) {
            $kmid = (int)$kmid;
            $val = max(0, min(100, (int)$val));

            if ($kmid <= 0) {
                continue;
            }

            // Tidak perlu get_record dulu.
            $DB->set_field('kurikulum_mapel', 'kktp', $val, ['id' => $kmid]);
        }
    }

    // membuat opsi dropdown KKTP dari 0 sampai 100.
    public static function build_kktp_options(int $selected): array {
        
// Membuat array kosong.
// Array ini akan digunakan untuk menampung
// seluruh pilihan dropdown.
        $opts = [];

// Mulai dari angka 0.
// Selama angka masih <= 100,
// lakukan perulangan.
// Setiap selesai 1 putaran,
// angka bertambah 1.
        for ($i = 0; $i <= 100; $i++) {
            $opts[] = [
                'value' => $i,
                'label' => (string)$i,
                'selected' => ($i === $selected),
            ];
        }
        return $opts;
    }
}