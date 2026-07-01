<?php
namespace local_akademikmonitor\service;

defined('MOODLE_INTERNAL') || die();

class seed {
    // Ganti kalau kamu pakai versi upgrade lain.
    private const SEED_VERSION = 2026021600;

    public static function run(): void {
        global $DB;

        $dbman = $DB->get_manager();
        $now = time();

        // (Opsional) Biar seeder tidak jalan berulang-ulang di production.
        // Kalau kamu masih sering butuh seeder jalan terus (buat dev), comment block ini.
        $seededver = (int) get_config('local_akademikmonitor', 'seed_version');
        if ($seededver >= self::SEED_VERSION) {
            return;
        }

        // Helper: insert kalau belum ada (berdasarkan kunci unik/identitas).
        $ensure = function(string $table, array $where, \stdClass $data) use ($DB): void {
            if (!$DB->record_exists($table, $where)) {
                $DB->insert_record($table, $data);
            }
        };

        // 1) Jurusan (cek per jurusan, bukan cek tabel kosong).
        $ensure('jurusan', ['kode_jurusan' => 1], (object)[
            'nama_jurusan' => 'RPL',
            'kode_jurusan' => 1,
        ]);
        $ensure('jurusan', ['kode_jurusan' => 2], (object)[
            'nama_jurusan' => 'TKJ',
            'kode_jurusan' => 2,
        ]);

        // 2) Mapel (cek per nama_mapel).
        $mapel = ['Bahasa Indonesia', 'Bahasa Inggris', 'Agama', 'Matematika', 'Jaringan', 'Pemrograman'];
        foreach ($mapel as $m) {
            $ensure('mata_pelajaran', ['nama_mapel' => $m], (object)['nama_mapel' => $m]);
        }

        // 3) Kurikulum (cek per nama).
        $ensure('kurikulum', ['nama' => 'Kurikulum Merdeka'], (object)[
            'nama' => 'Kurikulum Merdeka',
            'is_active' => '1',
        ]);

        // 4) Tahun ajaran (cek per tahun_ajaran).
        $ensure('tahun_ajaran', ['tahun_ajaran' => '2025/2026'], (object)[
            'tahun_ajaran' => '2025/2026',
        ]);

        // Ambil record utama (pasti ada setelah ensure).
        $kurikulum = $DB->get_record('kurikulum', ['nama' => 'Kurikulum Merdeka'], '*', MUST_EXIST);
        $ta = $DB->get_record('tahun_ajaran', ['tahun_ajaran' => '2025/2026'], '*', MUST_EXIST);

        // 5) Kurikulum_jurusan (cek kombinasi 3 kolom).
        $jurusan = $DB->get_records('jurusan', null, 'id ASC');
        foreach ($jurusan as $j) {
            $ensure('kurikulum_jurusan', [
                'id_jurusan' => $j->id,
                'id_kurikulum' => $kurikulum->id,
                'id_tahun_ajaran' => $ta->id,
            ], (object)[
                'id_jurusan' => $j->id,
                'id_kurikulum' => $kurikulum->id,
                'id_tahun_ajaran' => $ta->id,
            ]);
        }

        // 6) Kurikulum_mapel (default: tingkat X, kktp 70).
        $kjs = $DB->get_records('kurikulum_jurusan', null, 'id ASC');
        $mapelrec = $DB->get_records('mata_pelajaran', null, 'id ASC');
        foreach ($kjs as $kj) {
            foreach ($mapelrec as $mp) {
                $ensure('kurikulum_mapel', [
                    'id_kurikulum_jurusan' => $kj->id,
                    'id_mapel' => $mp->id,
                    'tingkat_kelas' => 'X',
                ], (object)[
                    'id_kurikulum_jurusan' => $kj->id,
                    'id_mapel' => $mp->id,
                    'jam_pelajaran' => '00:00:00',
                    'tingkat_kelas' => 'X',
                    'kktp' => 70,
                ]);
            }
        }

        // 7) Setting telegram (pastikan minimal 1 row).
        if (!$DB->record_exists('setting_telegram', [])) {
            $DB->insert_record('setting_telegram', (object)[
                'bot_token' => '',
                'bot_username' => '',
                'is_enabled' => '0',
                'token_verified_at' => '',
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        // 8) Notif rule dummy (jangan overwrite kalau sudah ada, cukup ensure by rule_kode).
        $ensure('notif_rule', ['rule_kode' => 'pengingat_tugas'], (object)[
            'rule_kode' => 'pengingat_tugas',
            'is_enabled' => '1',
            'offset_days' => '1',
            'send_time' => '07:00:00',
            'recipients' => 'Siswa, Guru',
            'event_keyword' => 'deadline',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $ensure('notif_rule', ['rule_kode' => 'nilai_kktp'], (object)[
            'rule_kode' => 'nilai_kktp',
            'is_enabled' => '0',
            'offset_days' => '30',
            'send_time' => '07:00:00',
            'recipients' => 'Siswa, Guru',
            'event_keyword' => 'uts',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        // 9) Seed mitra_dudi (BARU) - hanya kalau tabelnya sudah ada (artinya upgrade sudah jalan).
        $hasmitra = $dbman->table_exists(new \xmldb_table('mitra_dudi'));
        if ($hasmitra) {
            $ensure('mitra_dudi', ['nama' => 'PT Contoh'], (object)[
                'nama' => 'PT Contoh',
                'alamat' => 'Alamat belum diisi',
                'kontak' => null,
                'is_active' => '1',
                'timecreated' => $now,
                'timemodified' => $now,
            ]);

            $ensure('mitra_dudi', ['nama' => 'CV Sukses'], (object)[
                'nama' => 'CV Sukses',
                'alamat' => 'Alamat belum diisi',
                'kontak' => null,
                'is_active' => '1',
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        // 10) Ekskul dummy
        // Ambil admin sebagai default pembina (lebih aman dari hardcode 1).
        $admin = function_exists('get_admin') ? get_admin() : null;
        $defaultpembinaid = $admin ? (int)$admin->id : 2; // fallback umum: 2

        $ensure('ekskul', ['nama' => 'Pramuka'], (object)[
            'nama' => 'Pramuka',
            'id_pembina' => $defaultpembinaid,
            'is_active' => '1',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $ensure('ekskul', ['nama' => 'Tari'], (object)[
            'nama' => 'Tari',
            'id_pembina' => $defaultpembinaid,
            'is_active' => '0',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        // Set flag seeder sudah jalan untuk versi ini.
        set_config('seed_version', self::SEED_VERSION, 'local_akademikmonitor');
    }
}
