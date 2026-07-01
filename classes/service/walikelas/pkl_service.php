<?php
namespace local_akademikmonitor\service\walikelas;
use local_akademikmonitor\service\period_filter_service;
defined('MOODLE_INTERNAL') || die();

class pkl_service {

    public static function get_pkl_siswa(int $userid, ?int $groupid = null, ?int $semester = null): array {
        global $DB;

        $conditions = ['id_siswa' => $userid];

        if ($groupid !== null && $groupid > 0) {
            $conditions['id_kelas'] = $groupid;
        }

        if ($semester !== null && in_array((int)$semester, [1, 2], true)) {
            $conditions['semester'] = (int)$semester;
        }

        $pkls = $DB->get_records(
            'pkl',
            $conditions,
            'id ASC',
            'id, id_siswa, id_kelas, id_mitra_dudi, semester, waktu_mulai, waktu_selesai, nilai'
        );

        if (!$pkls) {
            return [];
        }

        $mitraids = [];
        foreach ($pkls as $p) {
            if (!empty($p->id_mitra_dudi)) {
                $mitraids[] = (int)$p->id_mitra_dudi;
            }
        }

        $mitraids = array_values(array_unique($mitraids));
        $mitras = [];

        if ($mitraids) {
            $mitras = $DB->get_records_list(
                'mitra_dudi',
                'id',
                $mitraids,
                '',
                'id, nama, alamat, kontak'
            );
        }

        $out = [];
        foreach ($pkls as $p) {
            $mid = (int)($p->id_mitra_dudi ?? 0);
            $m = ($mid > 0 && isset($mitras[$mid])) ? $mitras[$mid] : null;

            $out[] = (object)[
                'id' => (int)$p->id,
                'id_siswa' => (int)$p->id_siswa,
                'id_kelas' => (int)$p->id_kelas,
                'id_mitra_dudi' => $mid,
                'nama' => $m ? (string)$m->nama : '-',
                'alamat' => $m ? (string)$m->alamat : '-',
                'kontak' => $m ? (string)$m->kontak : '-',
                'semester' => (int)($p->semester ?? 0),
                'waktu_mulai' => (string)($p->waktu_mulai ?? ''),
                'waktu_selesai' => (string)($p->waktu_selesai ?? ''),
                'nilai' => (string)($p->nilai ?? ''),
            ];
        }

        return $out;
    }

    public static function save(
        int $userid,
        int $groupid,
        int $mitraid,
        int $semester,
        string $waktu_mulai,
        string $waktu_selesai,
        string $nilai,
        int $pklid = 0
    ): void {
        global $DB;

        $userid = (int)$userid;
        // $kelasid = (int)$groupid;
        $mitraid = (int)$mitraid;
        $semester = (int)$semester;
        $pklid = (int)$pklid;
        $waktu_mulai = trim($waktu_mulai);
        $waktu_selesai = trim($waktu_selesai);
        $nilai = trim($nilai);

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

        if (!common_service::is_group_kelas_xii($groupid)) {
            throw new \exception('Fitur PKL hanya tersedia untuk kelas XII');
        }

        if ($mitraid <= 0) {
            throw new \exception('Mitra tidak valid');
        }

        if (!in_array($semester, [1, 2], true)) {
            throw new \exception('Semester harus 1 atau 2');
        }

        if ($waktu_mulai === '' || $waktu_selesai === '') {
            throw new \exception('Tanggal PKL wajib diisi');
        }

        if ($nilai === '') {
            throw new \exception('Nilai PKL wajib diisi');
        }

        // MODE EDIT: update berdasarkan ID record.
        if ($pklid > 0) {
            $existing = $DB->get_record('pkl', ['id' => $pklid], '*', MUST_EXIST);

            $existing->id_siswa = $userid;
            $existing->id_kelas = $groupid;
            $existing->id_mitra_dudi = $mitraid;
            $existing->semester = $semester;
            $existing->waktu_mulai = $waktu_mulai;
            $existing->waktu_selesai = $waktu_selesai;
            $existing->nilai = $nilai;
            $existing->timemodified = time();

            $DB->update_record('pkl', $existing);
            return;
        }

        // MODE TAMBAH:
        // Karena database hanya mengizinkan 1 record per siswa+kelas+semester,
        // maka jika sudah ada, record itu HARUS diupdate, bukan insert baru.
        $sameperiod = $DB->get_record('pkl', [
            'id_siswa' => $userid,
            'id_kelas' => $groupid,
            'semester' => $semester,
        ]);

        if ($sameperiod) {
            $sameperiod->id_mitra_dudi = $mitraid;
            $sameperiod->waktu_mulai = $waktu_mulai;
            $sameperiod->waktu_selesai = $waktu_selesai;
            $sameperiod->nilai = $nilai;
            $sameperiod->timemodified = time();

            $DB->update_record('pkl', $sameperiod);
            return;
        }

        $now = time();

        $DB->insert_record('pkl', (object)[
            'id_siswa' => $userid,
            'id_kelas' => $groupid,
            'id_mitra_dudi' => $mitraid,
            'semester' => $semester,
            'waktu_mulai' => $waktu_mulai,
            'waktu_selesai' => $waktu_selesai,
            'nilai' => $nilai,
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

        $data = common_service::get_sidebar_data('pkl', $userid, $tahunajaranid);
        $data = common_service::get_sidebar_data('pkl', $userid, $tahunajaranid);
        $data['ajaxurl'] = (new \moodle_url('/local/akademikmonitor/pages/walikelas/pkl/ajax.php'))->out(false);
        $data['sesskey'] = sesskey();

        $groups = common_service::filter_groups_kelas_xii(
            common_service::get_group_walikelas($userid),
            $tahunajaranid
        );

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

                $pkls = self::get_pkl_siswa(
                    (int)$siswa->id,
                    (int)$group->id,
                    ($semester > 0 ? $semester : null)
                );

                $pkllist = [];

                if ($pkls) {
                    $rowspan = count($pkls);
                    $i = 0;

                    foreach ($pkls as $pkl) {
                        $pkllist[] = [
                            'siswa_no' => $no,
                            'siswa_nama' => $namasiswa,
                            'siswa_nisn' => $nisn,
                            'userid' => (int)$siswa->id,
                            'kelasid' => (int)$group->id,
                            'semester' => (int)$pkl->semester,
                            'pklid' => (int)$pkl->id,
                            'mitraid' => (int)$pkl->id_mitra_dudi,
                            'nama' => (string)$pkl->nama,
                            'alamat' => (string)$pkl->alamat,
                            'kontak' => (string)$pkl->kontak,
                            'waktu_mulai' => (string)$pkl->waktu_mulai,
                            'waktu_selesai' => (string)$pkl->waktu_selesai,
                            'nilai' => (string)$pkl->nilai,
                            'isfirst' => ($i === 0),
                            'rowspan' => $rowspan,
                        ];
                        $i++;
                    }
                } else {
                    $pkllist[] = [
                        'siswa_no' => $no,
                        'siswa_nama' => $namasiswa,
                        'siswa_nisn' => $nisn,
                        'userid' => (int)$siswa->id,
                        'kelasid' => (int)$group->id,
                        'semester' => ($semester > 0 ? (int)$semester : 0),
                        'pklid' => '',
                        'mitraid' => '',
                        'nama' => '-',
                        'alamat' => '-',
                        'kontak' => '-',
                        'waktu_mulai' => '-',
                        'waktu_selesai' => '-',
                        'nilai' => '-',
                        'isfirst' => true,
                        'rowspan' => 1,
                    ];
                }

                $listsiswa[] = [
                    'no' => $no,
                    'userid' => (int)$siswa->id,
                    'nama' => $namasiswa,
                    'nisn' => $nisn,
                    'pkllist' => $pkllist,
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

        $mitraoptions = [];
        $records = $DB->get_records('mitra_dudi', ['is_active' => 1], 'nama ASC', 'id, nama');
        foreach ($records as $m) {
            $mitraoptions[] = [
                'id' => (int)$m->id,
                'nama' => (string)$m->nama,
            ];
        }

        $data['kelas'] = $kelasdata;
        $data['siswa_options'] = $siswaoptions;
        $data['mitra_options'] = $mitraoptions;
        $data['nokelas'] = empty($kelasdata);
        $data['only_xii_message'] = 'Fitur PKL hanya tersedia untuk kelas XII. Jika kelas yang dipilih adalah kelas X atau XI, menu dan data PKL tidak ditampilkan.';
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

        return $data;
    }
}