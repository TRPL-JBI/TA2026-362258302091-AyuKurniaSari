<?php
namespace local_akademikmonitor\service\walikelas;

use local_akademikmonitor\service\period_filter_service;

defined('MOODLE_INTERNAL') || die();

class ekskul_service {

    public static function get_ekskul_siswa(int $userid, ?int $groupid = null, ?int $semester = null): array {
        global $DB;

        $conditions = ['id_siswa' => $userid];

        if ($groupid !== null && $groupid > 0) {
            $conditions['id_kelas'] = $groupid;
        }

        if ($semester !== null && in_array((int)$semester, [1, 2], true)) {
            $conditions['semester'] = (int)$semester;
        }

        $rapor = $DB->get_records(
            'ekskul_rapor',
            $conditions,
            'id ASC',
            'id, id_siswa, id_kelas, id_ekskul, semester, predikat'
        );

        if (!$rapor) {
            return [];
        }

        $ekskulids = [];

        foreach ($rapor as $r) {
            if (!empty($r->id_ekskul)) {
                $ekskulids[] = (int)$r->id_ekskul;
            }
        }

        $ekskulids = array_values(array_unique($ekskulids));
        $ekskuls = [];

        if ($ekskulids) {
            $ekskuls = $DB->get_records_list(
                'ekskul',
                'id',
                $ekskulids,
                '',
                'id, nama'
            );
        }

        $out = [];

        foreach ($rapor as $r) {
            $eid = (int)($r->id_ekskul ?? 0);

            $out[] = (object)[
                'id' => (int)$r->id,
                'id_ekskul' => $eid,
                'id_kelas' => (int)($r->id_kelas ?? 0),
                'semester' => (int)($r->semester ?? 0),
                'nama' => ($eid > 0 && isset($ekskuls[$eid])) ? (string)$ekskuls[$eid]->nama : '-',
                'predikat' => (string)($r->predikat ?? ''),
            ];
        }

        return $out;
    }

    public static function get_keterangan_predikat(string $predikat): string {
        switch (strtoupper(trim($predikat))) {
            case 'A':
                return 'Mengikuti kegiatan ekstrakurikuler dengan sangat baik';
            case 'B':
                return 'Mengikuti kegiatan ekstrakurikuler dengan baik';
            case 'C':
                return 'Mengikuti kegiatan ekstrakurikuler dengan cukup baik';
            case 'D':
                return 'Mengikuti kegiatan ekstrakurikuler dengan kurang baik';
            default:
                return '-';
        }
    }

    public static function save(int $userid, int $groupid, int $ekskulid, int $semester, string $predikat): void {
        global $DB;

        $userid = (int)$userid;
        $groupid = (int)$groupid;
        $ekskulid = (int)$ekskulid;
        $semester = (int)$semester;
        $predikat = strtoupper(trim($predikat));

        if ($userid <= 0) {
            throw new \exception('User siswa tidak valid');
        }

        if ($groupid <= 0) {
            throw new \exception('Kelas tidak valid');
        }
        if (!$DB->record_exists('groups_members', [
            'groupid' => $groupid,
            'userid' => $userid,
        ])) {
            throw new \moodle_exception(
                'studentnotingroup',
                'local_akademikmonitor'
            );
        }

        if ($ekskulid <= 0) {
            throw new \exception('Ekskul tidak valid');
        }

        if (!in_array($semester, [1, 2], true)) {
            throw new \exception('Semester harus 1 atau 2');
        }

        if (!in_array($predikat, ['A', 'B', 'C', 'D'], true)) {
            throw new \exception('Predikat harus A, B, C, atau D');
        }

        $keterangan = self::get_keterangan_predikat($predikat);

        $existing = $DB->get_record('ekskul_rapor', [
            'id_siswa' => $userid,
            'id_kelas' => $groupid,
            'id_ekskul' => $ekskulid,
            'semester' => $semester,
        ]);

        if ($existing) {
            $existing->predikat = $predikat;
            $existing->keterangan = $keterangan;
            $existing->timemodified = time();
            $DB->update_record('ekskul_rapor', $existing);
            return;
        }

        $now = time();

        $DB->insert_record('ekskul_rapor', (object)[
            'id_siswa' => $userid,
            'id_kelas' => $groupid,
            'id_ekskul' => $ekskulid,
            'semester' => $semester,
            'predikat' => $predikat,
            'keterangan' => $keterangan,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    public static function get_page_data(int $userid, int $semester = 0, int $tahunajaranid = 0): array {
        global $DB;

        if ($semester <= 0) {
            $semester = period_filter_service::get_selected_semester();
        }

        if ($tahunajaranid <= 0) {
            $tahunajaranid = period_filter_service::get_selected_tahunajaranid();
        }

        /*
         * Sidebar wajib dikirim dengan userid dan tahunajaranid.
         * Kalau tidak, show_pkl_menu bisa salah di halaman ekstrakurikuler.
         */
        $data = common_service::get_sidebar_data('ekskul', $userid, $tahunajaranid);

        $data['ajaxurl'] = (new \moodle_url('/local/akademikmonitor/pages/walikelas/ekskul/ajax.php'))->out(false);
        $data['sesskey'] = sesskey();

        $groups = common_service::get_group_walikelas_by_tahunajaran($userid, $tahunajaranid);

        $kelasdata = [];
        $siswaoptions = [];

        foreach ($groups as $group) {
            $siswas = common_service::get_siswa_group((int)$group->id, $userid);

            $userids = array_map('intval', array_keys($siswas));
            $nisnmap = common_service::get_nisn_map_by_userids($userids);

            $listsiswa = [];
            $no = 1;

            foreach ($siswas as $siswa) {
                $namasiswa = fullname($siswa);
                $nisn = !empty($nisnmap[(int)$siswa->id]) ? (string)$nisnmap[(int)$siswa->id] : '-';

                $ekskuls = self::get_ekskul_siswa(
                    (int)$siswa->id,
                    (int)$group->id,
                    $semester
                );

                $ekskullist = [];

                if ($ekskuls) {
                    $rowspan = count($ekskuls);
                    $i = 0;

                    foreach ($ekskuls as $ekskul) {
                        $ekskullist[] = [
                            'siswa_no' => $no,
                            'siswa_nama' => $namasiswa,
                            'siswa_nisn' => $nisn,
                            'userid' => (int)$siswa->id,
                            'kelasid' => (int)$group->id,
                            'semester' => (int)$ekskul->semester,
                            'ekskulid' => (int)$ekskul->id_ekskul,
                            'nama' => (string)$ekskul->nama,
                            'predikat' => (string)$ekskul->predikat,
                            'keterangan' => self::get_keterangan_predikat((string)$ekskul->predikat),
                            'isfirst' => ($i === 0),
                            'rowspan' => $rowspan,
                        ];
                        $i++;
                    }
                } else {
                    $ekskullist[] = [
                        'siswa_no' => $no,
                        'siswa_nama' => $namasiswa,
                        'siswa_nisn' => $nisn,
                        'userid' => (int)$siswa->id,
                        'kelasid' => (int)$group->id,
                        'semester' => (int)$semester,
                        'ekskulid' => '',
                        'nama' => '-',
                        'predikat' => '-',
                        'keterangan' => '-',
                        'isfirst' => true,
                        'rowspan' => 1,
                    ];
                }

                $listsiswa[] = [
                    'no' => $no,
                    'userid' => (int)$siswa->id,
                    'nama' => $namasiswa,
                    'nisn' => $nisn,
                    'ekskullist' => $ekskullist,
                ];

                $siswaoptions[] = [
                    'id' => (int)$siswa->id,
                    'nama' => $namasiswa,
                    'nisn' => $nisn,
                ];

                $no++;
            }

            $kelasdata[] = [
                'kelasid' => (int)$group->id,
                'nama' => (string)$group->name,
                'totalsiswa' => count($siswas),
                'siswa' => $listsiswa,
            ];
        }

        $ekskuloptions = [];
        $records = $DB->get_records('ekskul', null, 'nama ASC', 'id, nama');

        foreach ($records as $e) {
            $ekskuloptions[] = [
                'id' => (int)$e->id,
                'nama' => (string)$e->nama,
            ];
        }

        $data['kelas'] = $kelasdata;
        $data['siswa_options'] = $siswaoptions;
        $data['ekskul_options'] = $ekskuloptions;
        $data['nokelas'] = empty($kelasdata);
        $data['selectedsemester'] = (int)$semester;
        $data['selectedtahunajaranid'] = (int)$tahunajaranid;
        $data['selected_tahunajaranid'] = (int)$tahunajaranid;
        $data['caneditperiod'] = period_filter_service::can_edit_tahunajaran($tahunajaranid);
        $data['readonlyperiod'] = !$data['caneditperiod'];

        if (!empty($kelasdata)) {
            $data['currentkelasid'] = (int)$kelasdata[0]['kelasid'];
        } else {
            $data['currentkelasid'] = 0;
        }

        $data += period_filter_service::build_filter_data();
        $data += period_filter_service::get_filter_ui_data(
            '/local/akademikmonitor/pages/walikelas/ekskul/ekskul.php'
        );

        return $data;
    }

    public static function delete(
        int $userid,
        int $kelasid,
        int $ekskulid,
        int $semester
    ): void {

        global $DB;

        $record = $DB->get_record('ekskul_rapor', [
            'id_siswa' => $userid,
            'id_kelas' => $kelasid,
            'id_ekskul' => $ekskulid,
            'semester' => $semester,
        ]);

        if (!$record) {
            throw new \exception(
                'Data ekstrakurikuler tidak ditemukan.'
            );
        }

        $DB->delete_records(
            'ekskul_rapor',
            ['id' => $record->id]
        );
    }
}