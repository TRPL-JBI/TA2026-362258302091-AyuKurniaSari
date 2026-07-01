<?php
namespace local_akademikmonitor\service;

defined('MOODLE_INTERNAL') || die();

class ekskul_service {

    /**
     * Ambil daftar ekstrakurikuler beserta nama pembinanya.
     *
     * Konsep:
     * - Tabel ekskul menyimpan id_pembina.
     * - Nama pembina diambil dari tabel user berdasarkan id_pembina.
     * - Field user yang diambil dibuat aman untuk fullname() Moodle 5.x.
     */
    public static function list_ekskul(): array {
        global $DB;

        $ekskuls = $DB->get_records('ekskul', null, 'id ASC');

        if (!$ekskuls) {
            return [];
        }

        $pembinaids = [];

// Memeriksa setiap data ekstrakurikuler.
//
// Tujuannya untuk mengumpulkan
// seluruh ID pembina yang digunakan.
//
// Dengan cara ini sistem dapat
// mengambil data user sekaligus
// dalam satu query.
        foreach ($ekskuls as $ekskul) {
            if (!empty($ekskul->id_pembina)) {
                $pembinaids[] = (int)$ekskul->id_pembina;
            }
        }

        // array_unique(). misal [5,8,5] maka jadi [5,8]. karna pembina bisa lbih dai 1 ekskul. misal pak budi bisa di pramuka dan pencak silat
        $pembinaids = array_values(array_unique(array_filter($pembinaids)));

        $usersbyid = [];

        if ($pembinaids) {
            $namefields = \core_user\fields::for_name()->get_required_fields();

            $fields = array_unique(array_merge(
                ['id', 'username', 'deleted', 'suspended'],
                $namefields
            ));

            $usersbyid = $DB->get_records_list(
                'user',
                'id',
                $pembinaids,
                '',
                implode(',', $fields)
            );
        }

        $out = [];

        foreach ($ekskuls as $ekskul) {
            $pembinaid = (int)($ekskul->id_pembina ?? 0);

            $pembina = '-';

            if ($pembinaid > 0 && isset($usersbyid[$pembinaid])) {
                $user = $usersbyid[$pembinaid];

                if (!empty($user->deleted) || !empty($user->suspended)) {
                    $pembina = 'User ID: ' . $pembinaid;
                } else {
                    $pembina = fullname($user);
                }
            } else if ($pembinaid > 0) {
                $pembina = 'User ID: ' . $pembinaid;
            }

            $isactive = ((string)$ekskul->is_active === '1');

            $out[] = [
                'id' => (int)$ekskul->id,
                'nama' => $ekskul->nama,
                'pembina' => $pembina,
                'id_pembina' => $pembinaid,
                'is_enabled' => $isactive,
                'badge_class' => $isactive ? 'on' : 'off',
                'badge_text' => $isactive ? 'aktif' : 'nonaktif',
                'toggle_text' => $isactive ? 'nonaktif' : 'aktif',
            ];
        }

        return $out;
    }

    /**
     * Ambil opsi dropdown pembina.
     *
     * Pembina ekstrakurikuler diambil dari:
     * 1. Semua user dengan custom profile field jenis_pengguna = guru.
     * 2. Semua user yang tercatat sebagai wali kelas di tabel kelas.id_user.
     *
     * Kenapa tidak hanya role teacher/editingteacher?
     * Karena role teacher/editingteacher di Moodle biasanya baru ada
     * kalau user sudah masuk ke course tertentu.
     *
     * Kenapa wali kelas ikut dimasukkan?
     * Karena konsep plugin ini: wali kelas bukan jenis akun terpisah.
     * Wali kelas asalnya tetap guru, hanya diberi tugas tambahan di rombel.
     */
    public static function list_pembina_options(): array {
        global $DB;

        $userids = [];

        // 1. Ambil semua user dengan custom profile field jenis_pengguna = guru.
        $field = $DB->get_record(
            'user_info_field',
            ['shortname' => 'jenis_pengguna'],
            'id',
            IGNORE_MISSING
        );

        if ($field) {
            $profileuserids = $DB->get_fieldset_select(
                'user_info_data',
                'userid',
                'fieldid = :fieldid AND LOWER(TRIM(data)) = :jenisguru',
                [
                    'fieldid' => (int)$field->id,
                    'jenisguru' => 'guru',
                ]
            );

            if ($profileuserids) {
                $userids = array_merge($userids, array_map('intval', $profileuserids));
            }
        }

        // 2. Ambil semua user yang pernah/sedang dipilih sebagai wali kelas.
        $waliuserids = $DB->get_fieldset_select(
            'kelas',
            'id_user',
            'id_user IS NOT NULL AND id_user > 0',
            []
        );

        if ($waliuserids) {
            $userids = array_merge($userids, array_map('intval', $waliuserids));
        }

        $userids = array_values(array_unique(array_filter($userids)));

        if (!$userids) {
            return [];
        }

        $namefields = \core_user\fields::for_name()->get_required_fields();

        $fields = array_unique(array_merge(
            ['id', 'username', 'deleted', 'suspended'],
            $namefields
        ));

        $users = $DB->get_records_list(
            'user',
            'id',
            $userids,
            'firstname ASC, lastname ASC, id ASC',
            implode(',', $fields)
        );

        if (!$users) {
            return [];
        }

        $opts = [];

        foreach ($users as $user) {
            if (!empty($user->deleted) || !empty($user->suspended)) {
                continue;
            }

            $opts[] = [
                'id' => (int)$user->id,
                'label' => fullname($user) . ' (' . ($user->username ?? '-') . ')',
            ];
        }

        usort($opts, static function($a, $b) {
            return strcasecmp($a['label'], $b['label']);
        });

        return $opts;
    }

    /**
     * Membuat data ekstrakurikuler baru.
     */
    public static function create(string $nama, int $pembinaid): int {
        global $DB;

        $now = time();

        return (int)$DB->insert_record('ekskul', (object)[
            'nama' => trim($nama),
            'id_pembina' => $pembinaid,
            'is_active' => '1',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Mengubah data ekstrakurikuler.
     */
    public static function update(int $id, string $nama, int $pembinaid): void {
        global $DB;

        $rec = $DB->get_record('ekskul', ['id' => $id], '*', MUST_EXIST);

        $rec->nama = trim($nama);
        $rec->id_pembina = $pembinaid;
        $rec->timemodified = time();

        $DB->update_record('ekskul', $rec);
    }

    /**
     * Mengaktifkan / menonaktifkan ekstrakurikuler.
     */
    public static function toggle(int $id): void {
        global $DB;

        $current = $DB->get_field('ekskul', 'is_active', ['id' => $id], MUST_EXIST);
        $new = ((string)$current === '1') ? '0' : '1';

        $DB->set_field('ekskul', 'is_active', $new, ['id' => $id]);
        $DB->set_field('ekskul', 'timemodified', time(), ['id' => $id]);
    }

    /**
     * Parse input pembina.
     *
     * Dipertahankan untuk kompatibilitas kalau masih ada AJAX lama
     * yang mengirim pembina sebagai teks.
     *
     * Kalau input angka, dianggap sebagai user id.
     * Kalau input teks, dicari dari username/firstname/lastname.
     */
    public static function parse_pembina_input(string $input): int {
        global $DB;

        $input = trim($input);

        if ($input === '') {
            return 0;
        }

        if (ctype_digit($input)) {
            return (int)$input;
        }

        $like = '%' . $DB->sql_like_escape($input) . '%';

        $select = $DB->sql_like('username', ':q1', false, false) .
            ' OR ' . $DB->sql_like('firstname', ':q2', false, false) .
            ' OR ' . $DB->sql_like('lastname', ':q3', false, false);

        $params = [
            'q1' => $like,
            'q2' => $like,
            'q3' => $like,
        ];

        $records = $DB->get_records_select(
            'user',
            $select,
            $params,
            'id ASC',
            'id',
            0,
            1
        );

        if ($records) {
            $user = reset($records);
            return (int)$user->id;
        }

        return 0;
    }
}