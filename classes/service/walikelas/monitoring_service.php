<?php
namespace local_akademikmonitor\service\walikelas;

use local_akademikmonitor\service\course_period_service;
use local_akademikmonitor\service\period_filter_service;

defined('MOODLE_INTERNAL') || die();

class monitoring_service {

    public static function get_mapel_by_kelas(int $groupid, int $semester = 0): array {
        global $DB;

        if ($groupid <= 0) {
            return [];
        }

        $group = $DB->get_record(
            'groups',
            ['id' => $groupid],
            'id, name, courseid',
            MUST_EXIST
        );

        $kelasid = common_service::get_generated_kelasid_from_group($groupid);

        /*
         * Jalur utama untuk course hasil generate rombel.
         *
         * Plugin kamu punya 2 kemungkinan format idnumber:
         *
         * Format lama:
         * AM-K{id_kelas}-KM{id_kurikulum_mapel}-S{semester}
         * Contoh:
         * AM-K6-KM83-S1
         *
         * Format baru:
         * AM-TA{id_tahun_ajaran}-K{id_kelas}-KM{id_kurikulum_mapel}-S{semester}
         * Contoh:
         * AM-TA2-K6-KM83-S1
         *
         * Kalau hanya membaca format lama, course yang dibuat dengan format baru
         * tidak akan muncul di monitoring.
         */
        if ($kelasid > 0) {
            $tahunajaranid = period_filter_service::get_selected_tahunajaranid();

            $conditions = [];
            $params = [];

            /*
             * Pattern format lama.
             */
            $oldpattern = 'AM-K' . $kelasid . '-KM%-S%';

            if (in_array($semester, [1, 2], true)) {
                $oldpattern = 'AM-K' . $kelasid . '-KM%-S' . $semester;
            }

            $conditions[] = $DB->sql_like('idnumber', ':oldpattern', false);
            $params['oldpattern'] = $oldpattern;

            /*
             * Pattern format baru.
             *
             * Kalau tahun ajaran aktif/terpilih ada, kita pakai yang spesifik:
             * AM-TA2-K6-KM%-S1
             *
             * Kalau tidak ada tahun ajaran, fallback ke:
             * AM-TA%-K6-KM%-S1
             */
            if ($tahunajaranid > 0) {
                $newpattern = 'AM-TA' . $tahunajaranid . '-K' . $kelasid . '-KM%-S%';

                if (in_array($semester, [1, 2], true)) {
                    $newpattern = 'AM-TA' . $tahunajaranid . '-K' . $kelasid . '-KM%-S' . $semester;
                }
            } else {
                $newpattern = 'AM-TA%-K' . $kelasid . '-KM%-S%';

                if (in_array($semester, [1, 2], true)) {
                    $newpattern = 'AM-TA%-K' . $kelasid . '-KM%-S' . $semester;
                }
            }

            $conditions[] = $DB->sql_like('idnumber', ':newpattern', false);
            $params['newpattern'] = $newpattern;

            $courses = $DB->get_records_select(
                'course',
                '(' . implode(' OR ', $conditions) . ')',
                $params,
                'fullname ASC, id ASC',
                'id, fullname, shortname, idnumber'
            );

            if (!$courses) {
                return [];
            }

            $courseids = array_map('intval', array_keys($courses));

            [$courseinsql, $courseparams] = $DB->get_in_or_equal(
                $courseids,
                SQL_PARAMS_NAMED,
                'courseid'
            );

            /*
             * course_mapel di plugin kamu kemungkinan tidak punya field id standar.
             * Maka field pertama sengaja id_course agar hasilnya keyed by id_course.
             */
            $coursemapels = $DB->get_records_select(
                'course_mapel',
                "id_course {$courseinsql}",
                $courseparams,
                '',
                'id_course, id_kurikulum_mapel'
            );

            $kmids = [];
            $kmidbycourse = [];

            foreach ($coursemapels as $cm) {
                $courseid = (int)($cm->id_course ?? 0);
                $kmid = (int)($cm->id_kurikulum_mapel ?? 0);

                if ($courseid <= 0 || $kmid <= 0) {
                    continue;
                }

                $kmidbycourse[$courseid] = $kmid;
                $kmids[$kmid] = $kmid;
            }

            $kurikulummapels = [];
            $matapelajarans = [];

            if ($kmids) {
                $kurikulummapels = $DB->get_records_list(
                    'kurikulum_mapel',
                    'id',
                    array_values($kmids),
                    '',
                    'id, id_mapel, kktp, tingkat_kelas'
                );

                $mapelids = [];

                foreach ($kurikulummapels as $km) {
                    $mapelid = (int)($km->id_mapel ?? 0);

                    if ($mapelid > 0) {
                        $mapelids[$mapelid] = $mapelid;
                    }
                }

                if ($mapelids) {
                    $matapelajarans = $DB->get_records_list(
                        'mata_pelajaran',
                        'id',
                        array_values($mapelids),
                        '',
                        'id, nama_mapel'
                    );
                }
            }

            $out = [];

            foreach ($courses as $course) {
                $courseid = (int)$course->id;
                $kmid = $kmidbycourse[$courseid] ?? 0;
                $km = ($kmid > 0 && isset($kurikulummapels[$kmid])) ? $kurikulummapels[$kmid] : null;

                $mapelname = '';
                $kktp = 0;

                if ($km) {
                    $mapelid = (int)($km->id_mapel ?? 0);
                    $kktp = (int)($km->kktp ?? 0);

                    if ($mapelid > 0 && isset($matapelajarans[$mapelid])) {
                        $mapelname = (string)$matapelajarans[$mapelid]->nama_mapel;
                    }
                }

                if ($mapelname === '') {
                    $mapelname = (string)$course->fullname;
                }

                $out[] = (object)[
                    'id' => $courseid,
                    'fullname' => (string)$course->fullname,
                    'shortname' => (string)($course->shortname ?? ''),
                    'idnumber' => (string)($course->idnumber ?? ''),
                    'nama_mapel' => self::clean_mapel_name($mapelname),
                    'kktp' => $kktp,
                ];
            }

            usort($out, static function($a, $b) {
                return strcasecmp((string)$a->nama_mapel, (string)$b->nama_mapel);
            });

            return array_values($out);
        }

        /*
         * Fallback untuk course manual lama.
         */
        $cleanname = preg_replace('/\s\d+$/', '', (string)$group->name);
        $parts = preg_split('/\s+/', trim($cleanname));

        if (!$parts || count($parts) < 2) {
            return [];
        }

        $tingkat = $parts[0];
        $jurusan = $parts[1];

        $like1 = '%' . $DB->sql_like_escape($tingkat) . '%';
        $like2 = '%' . $DB->sql_like_escape($jurusan) . '%';

        $select = $DB->sql_like('fullname', ':tingkat', false) . ' AND ' .
            $DB->sql_like('fullname', ':jurusan', false);

        $courses = $DB->get_records_select(
            'course',
            $select,
            [
                'tingkat' => $like1,
                'jurusan' => $like2,
            ],
            'fullname ASC',
            'id, fullname, shortname, idnumber'
        );

        $courses = course_period_service::filter_courses_by_semester($courses, $semester);

        $out = [];

        foreach ($courses as $c) {
            $out[] = (object)[
                'id' => (int)$c->id,
                'fullname' => (string)$c->fullname,
                'shortname' => (string)($c->shortname ?? ''),
                'idnumber' => (string)($c->idnumber ?? ''),
                'nama_mapel' => self::clean_mapel_name((string)$c->fullname),
                'kktp' => 0,
            ];
        }

        return array_values($out);
    }

    public static function get_monitoring_nilai(int $groupid, int $courseid, int $waliuserid): array {
        global $DB;

        $students = common_service::get_siswa_group($groupid, $waliuserid);

        if (!$students || $courseid <= 0) {
            return [
                'groups' => [],
                'columns' => [],
                'rows' => [],
            ];
        }

        $userids = array_map('intval', array_keys($students));

        /*
         * Monitoring kelas menampilkan Course Total dari course/mapel yang dipilih.
         * Jadi kalau dropdown ganti mapel, item course total yang dibaca juga ikut ganti.
         */
        $courseitem = $DB->get_record(
            'grade_items',
            [
                'courseid' => $courseid,
                'idnumber' => 'am_nilai_akhir',
            ],
            'id, courseid, itemname',
            IGNORE_MISSING
        );

        $finalbyuser = [];

        if ($courseitem && $userids) {
            [$userinsql, $userparams] = $DB->get_in_or_equal(
                $userids,
                SQL_PARAMS_NAMED,
                'userid'
            );

            $params = ['itemid' => (int)$courseitem->id] + $userparams;

            $grades = $DB->get_records_select(
                'grade_grades',
                "itemid = :itemid AND userid {$userinsql}",
                $params,
                '',
                'userid, finalgrade'
            );

            foreach ($grades as $grade) {
                $finalbyuser[(int)$grade->userid] = $grade->finalgrade !== null
                    ? (float)$grade->finalgrade
                    : null;
            }
        }

        $kktp = self::get_course_kktp($courseid);

        $records = [];

        foreach ($students as $user) {
            $uid = (int)$user->id;

            $records[] = (object)[
                'userid' => $uid,
                'nama' => fullname($user),
                'itemtype' => 'course',
                'finalgrade' => $finalbyuser[$uid] ?? null,
                'nilai_kktp' => $kktp,
            ];
        }

        return self::pivot_nilai($records);
    }

    private static function get_course_kktp(int $courseid): int {
        global $DB;

        if ($courseid <= 0) {
            return 0;
        }

        $cm = $DB->get_record(
            'course_mapel',
            ['id_course' => $courseid],
            'id_course, id_kurikulum_mapel',
            IGNORE_MISSING
        );

        if (!$cm || empty($cm->id_kurikulum_mapel)) {
            return 0;
        }

        $kktp = $DB->get_field(
            'kurikulum_mapel',
            'kktp',
            ['id' => (int)$cm->id_kurikulum_mapel],
            IGNORE_MISSING
        );

        return $kktp !== false ? (int)$kktp : 0;
    }

    private static function pivot_nilai(array $records): array {
        $groups = [];
        $data = [];
        $columns = [];

        foreach ($records as $record) {
            // if ($record->idnumber === 'am_nilai_akhir') {
            //     continue;
            // }

            $groupname = 'Nilai';
            $colname = 'Nilai Akhir';

            $groups[$groupname][$colname] = $colname;
            $columns[$colname] = $colname;

            if (!isset($data[$record->userid])) {
                $data[$record->userid] = [
                    'nama' => $record->nama,
                    'nilai' => [],
                ];
            }

            if ($record->finalgrade === null) {
                $value = [
                    'text' => '-',
                    'isbelowkktp' => false,
                    'isabovekktp' => false,
                ];
            } else {
                $nilai = round((float)$record->finalgrade, 2);
                $kktp = (int)($record->nilai_kktp ?? 0);

                $value = [
                    'text' => $nilai,
                    'isbelowkktp' => ($kktp > 0 && $nilai < $kktp),
                    'isabovekktp' => !($kktp > 0 && $nilai < $kktp),
                ];
            }

            $data[$record->userid]['nilai'][$colname] = $value;
        }

        $columns = array_keys($columns);

        foreach ($data as &$row) {
            $list = [];

            foreach ($columns as $col) {
                $list[] = $row['nilai'][$col] ?? [
                    'text' => '-',
                    'isbelowkktp' => false,
                    'isabovekktp' => false,
                ];
            }

            $row['nilai_list'] = $list;
        }
        unset($row);

        return [
            'groups' => self::format_groups($groups),
            'columns' => $columns,
            'rows' => array_values($data),
        ];
    }

    private static function format_groups(array $groups): array {
        $result = [];

        foreach ($groups as $name => $cols) {
            $result[] = [
                'name' => $name,
                'count' => count($cols),
            ];
        }

        return $result;
    }

    private static function clean_mapel_name(string $name): string {
        $name = trim($name);

        if ($name === '') {
            return '-';
        }

        /*
         * Hilangkan prefix kategori mapel:
         * [kejuruan] Bahasa Inggris -> Bahasa Inggris
         */
        if (preg_match('/^\[([^\]]+)\]\s*(.+)$/', $name, $matches)) {
            $name = trim((string)$matches[2]);
        }

        /*
         * Kalau fallback dari nama course:
         * Bahasa Inggris - X Teknik Multimedia 1 - Ganjil
         * ambil bagian mapelnya saja.
         */
        $parts = array_map('trim', explode(' - ', $name));

        if (count($parts) >= 2) {
            $name = $parts[0];
        }

        return $name !== '' ? $name : '-';
    }

    public static function get_page_data(
        int $userid,
        int $courseid = 0,
        int $semester = 0,
        int $tahunajaranid = 0
    ): array {
        if ($semester <= 0) {
            $semester = period_filter_service::get_selected_semester();
        }

        if ($tahunajaranid <= 0) {
            $tahunajaranid = period_filter_service::get_selected_tahunajaranid();
        }

        /*
         * Penting:
         * Sidebar harus dipanggil dengan userid dan tahunajaranid.
         * Kalau tidak, show_pkl_menu bisa salah dan menu PKL tetap muncul
         * di halaman monitoring.
         */
        $data = common_service::get_sidebar_data('monitoring', $userid, $tahunajaranid);

        $group = common_service::get_first_group_walikelas_by_tahunajaran($userid, $tahunajaranid);

        if (!$group) {
            $data['nokelas'] = true;

            $data += period_filter_service::build_filter_data();
            $data += period_filter_service::get_filter_ui_data(
                '/local/akademikmonitor/pages/walikelas/monitoring/monitoring.php',
                ['courseid' => $courseid]
            );

            return $data;
        }

        $mapel = self::get_mapel_by_kelas((int)$group->id, $semester);

        /*
         * Validasi courseid.
         * Kalau courseid dari URL bukan milik kelas/semester ini,
         * jangan dipakai. Pakai mapel pertama agar data tidak nyasar.
         */
        $validcourseids = [];

        foreach ($mapel as $m) {
            $validcourseids[(int)$m->id] = true;
        }

        if ($courseid <= 0 || !isset($validcourseids[$courseid])) {
            $courseid = !empty($mapel) ? (int)$mapel[0]->id : 0;
        }

        foreach ($mapel as &$m) {
            $m->is_selected = ((int)$m->id === $courseid);
        }
        unset($m);

        $selectedmapelname = '-';

        foreach ($mapel as $m) {
            if ((int)$m->id === $courseid) {
                $selectedmapelname = (string)$m->nama_mapel;
                break;
            }
        }

        $data['kelas'] = (string)$group->name;

        $data['mapel'] = array_map(static function($m) {
            return [
                'id' => (int)$m->id,
                'nama_mapel' => (string)$m->nama_mapel,
                'is_selected' => !empty($m->is_selected),
            ];
        }, $mapel);

        $data['selected_course'] = $courseid;
        $data['selected_mapel_name'] = $selectedmapelname;
        $data['selectedsemester'] = $semester;
        $data['selectedtahunajaranid'] = $tahunajaranid;
        $data['selected_tahunajaranid'] = $tahunajaranid;
        $data['caneditperiod'] = period_filter_service::can_edit_tahunajaran($tahunajaranid);
        $data['readonlyperiod'] = !$data['caneditperiod'];
        
        $data += period_filter_service::build_filter_data();
        $data += period_filter_service::get_filter_ui_data(
            '/local/akademikmonitor/pages/walikelas/monitoring/monitoring.php',
            ['courseid' => $courseid]
        );

        if ($courseid > 0) {
            $monitoring = self::get_monitoring_nilai((int)$group->id, $courseid, $userid);
            $data['groups'] = $monitoring['groups'];
            $data['columns'] = $monitoring['columns'];
            $data['rows'] = $monitoring['rows'];
        } else {
            $data['groups'] = [];
            $data['columns'] = [];
            $data['rows'] = [];
        }

        return $data;
    }
}