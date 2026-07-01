<?php
namespace local_akademikmonitor\service;

defined('MOODLE_INTERNAL') || die();

class mapping_service {

    /**
     * Ambil semua course Moodle, kecuali front page/site course.
     *
     * Kenapa front page dikeluarkan?
     * Karena front page Moodle juga tersimpan di tabel course, tetapi itu bukan course mata pelajaran.
     */
    public static function get_courses(): array {
        global $DB, $SITE;

        $siteid = !empty($SITE->id) ? (int)$SITE->id : 1;

        $records = $DB->get_records_select(
            'course',
            'id <> :siteid',
            ['siteid' => $siteid],
            'fullname ASC',
            'id, fullname, shortname, idnumber'
        );

        $items = [];

        foreach ($records as $course) {
            $semester = self::detect_course_semester($course);

            $items[] = (object)[
                'id' => (int)$course->id,
                'fullname' => format_string((string)$course->fullname),
                'shortname' => format_string((string)$course->shortname),
                'idnumber' => format_string((string)($course->idnumber ?? '')),
                'semester' => $semester,
                'semester_label' => self::semester_label($semester),
            ];
        }

        return $items;
    }

    /**
     * Function alias supaya file lama yang memanggil get_kurikulum_mapel()
     * tidak crash.
     *
     * Error kamu tadi muncul karena index.php memanggil:
     * mapping_service::get_kurikulum_mapel()
     *
     * Tapi yang tersedia hanya:
     * get_kurikulum_mapel_options()
     */
    public static function get_kurikulum_mapel(int $jurusanid = 0): array {
        return self::get_kurikulum_mapel_options($jurusanid);
    }

    /**
     * Ambil daftar kurikulum mapel.
     *
     * Kalau $jurusanid dikirim dari tombol Set Mapel di Manajemen Jurusan,
     * maka mapel yang tampil hanya mapel dari jurusan tersebut.
     *
     * Ini supaya ketika klik Set Mapel dari jurusan SIPIL,
     * dropdown tidak menampilkan mapel milik TKR/RPL/jurusan lain.
     */
    public static function get_kurikulum_mapel_options(int $jurusanid = 0): array {
        global $DB;

        $where = '';
        $params = [];

        if ($jurusanid > 0) {
            $where = 'WHERE kj.id_jurusan = :jurusanid';
            $params['jurusanid'] = $jurusanid;
        }

        $sql = "SELECT km.id,
                       km.id_mapel,
                       km.id_kurikulum_jurusan,
                       km.tingkat_kelas,
                       km.jam_pelajaran,
                       km.kktp,
                       mp.nama_mapel,
                       k.nama AS nama_kurikulum,
                       j.id AS jurusanid,
                       j.nama_jurusan,
                       j.kode_jurusan
                  FROM {kurikulum_mapel} km
                  JOIN {mata_pelajaran} mp ON mp.id = km.id_mapel
                  JOIN {kurikulum_jurusan} kj ON kj.id = km.id_kurikulum_jurusan
                  JOIN {kurikulum} k ON k.id = kj.id_kurikulum
                  JOIN {jurusan} j ON j.id = kj.id_jurusan
                  $where
              ORDER BY j.nama_jurusan ASC, km.tingkat_kelas ASC, mp.nama_mapel ASC";

        $records = $DB->get_records_sql($sql, $params);

        $items = [];

        foreach ($records as $record) {
            $label = $record->nama_mapel .
                ' - ' . $record->nama_kurikulum .
                ' - ' . $record->nama_jurusan .
                ' - Kelas ' . $record->tingkat_kelas;

            if ($record->kktp !== null && $record->kktp !== '') {
                $label .= ' - KKTP ' . $record->kktp;
            }

            $items[] = (object)[
                'id' => (int)$record->id,
                'label' => format_string($label),
                'nama_mapel' => format_string((string)$record->nama_mapel),
                'nama_kurikulum' => format_string((string)$record->nama_kurikulum),
                'nama_jurusan' => format_string((string)$record->nama_jurusan),
                'kode_jurusan' => format_string((string)($record->kode_jurusan ?? '')),
                'tingkat_kelas' => format_string((string)$record->tingkat_kelas),
                'kktp' => isset($record->kktp) && $record->kktp !== '' ? $record->kktp : '-',
            ];
        }

        return $items;
    }

    /**
     * Ambil mapping yang sedang aktif untuk satu course.
     */
    public static function get_current_mapping(int $courseid) {
        global $DB;

        if ($courseid <= 0) {
            return null;
        }

        return $DB->get_record('course_mapel', [
            'id_course' => $courseid,
        ]);
    }

    /**
     * Simpan mapping course ke kurikulum_mapel.
     *
     * Satu course hanya boleh punya satu mapping mapel.
     * Maka mapping lama dari course tersebut dihapus dulu.
     *
     * Ini penting supaya course tidak nyangkut ke dua mapel yang berbeda.
     */
    public static function save_mapping(int $courseid, int $kmid): bool {
        global $DB;

        if ($courseid <= 0 || $kmid <= 0) {
            return false;
        }

        if (!$DB->record_exists('course', ['id' => $courseid])) {
            return false;
        }

        if (!$DB->record_exists('kurikulum_mapel', ['id' => $kmid])) {
            return false;
        }

        $DB->delete_records('course_mapel', [
            'id_course' => $courseid,
        ]);

        /*
         * Tabel course_mapel di plugin kamu tidak punya kolom id auto increment.
         * Jadi jangan pakai insert_record() yang mengharapkan id balik.
         * Pakai execute() supaya aman.
         */
        $sql = "INSERT INTO {course_mapel} (id_course, id_kurikulum_mapel)
                VALUES (:courseid, :kmid)";

        $DB->execute($sql, [
            'courseid' => $courseid,
            'kmid' => $kmid,
        ]);

        return true;
    }

    /**
     * Hapus mapping dari satu course.
     */
    public static function delete_mapping(int $courseid): bool {
        global $DB;

        if ($courseid <= 0) {
            return false;
        }

        $DB->delete_records('course_mapel', [
            'id_course' => $courseid,
        ]);

        return true;
    }

    /**
     * Ambil daftar mapping yang sudah tersimpan.
     *
     * Kalau $jurusanid dikirim, daftar mapping difilter sesuai jurusan.
     */
    public static function get_existing_mappings(int $jurusanid = 0): array {
        global $DB;

        $where = '';
        $params = [];

        if ($jurusanid > 0) {
            $where = 'WHERE j.id = :jurusanid';
            $params['jurusanid'] = $jurusanid;
        }

        $sql = "SELECT c.id AS courseid,
                       c.fullname AS coursefullname,
                       c.shortname AS courseshortname,
                       c.idnumber AS courseidnumber,
                       km.id AS kurikulummapelid,
                       km.tingkat_kelas,
                       km.kktp,
                       mp.nama_mapel,
                       k.nama AS nama_kurikulum,
                       j.nama_jurusan,
                       j.kode_jurusan
                  FROM {course_mapel} cm
                  JOIN {course} c ON c.id = cm.id_course
                  JOIN {kurikulum_mapel} km ON km.id = cm.id_kurikulum_mapel
                  JOIN {mata_pelajaran} mp ON mp.id = km.id_mapel
                  JOIN {kurikulum_jurusan} kj ON kj.id = km.id_kurikulum_jurusan
                  JOIN {kurikulum} k ON k.id = kj.id_kurikulum
                  JOIN {jurusan} j ON j.id = kj.id_jurusan
                  $where
              ORDER BY c.fullname ASC";

        $records = $DB->get_records_sql($sql, $params);

        $items = [];

        foreach ($records as $record) {
            $semester = self::detect_course_semester((object)[
                'fullname' => $record->coursefullname,
                'shortname' => $record->courseshortname,
            ]);

            $mapellabel = $record->nama_mapel .
                ' - ' . $record->nama_kurikulum .
                ' - ' . $record->nama_jurusan .
                ' - Kelas ' . $record->tingkat_kelas;

            if ($record->kktp !== null && $record->kktp !== '') {
                $mapellabel .= ' - KKTP ' . $record->kktp;
            }

            $items[] = (object)[
                'courseid' => (int)$record->courseid,
                'coursefullname' => format_string((string)$record->coursefullname),
                'courseshortname' => format_string((string)$record->courseshortname),
                'courseidnumber' => format_string((string)($record->courseidnumber ?? '')),
                'mapellabel' => format_string($mapellabel),
                'semester' => $semester,
                'semester_label' => self::semester_label($semester),
                'semester_warning' => $semester === 0,
            ];
        }

        return $items;
    }

    /**
     * Ambil data jurusan untuk header halaman mapping.
     */
    public static function get_jurusan(int $jurusanid) {
        global $DB;

        if ($jurusanid <= 0) {
            return null;
        }

        return $DB->get_record(
            'jurusan',
            ['id' => $jurusanid],
            'id, nama_jurusan, kode_jurusan',
            IGNORE_MISSING
        );
    }

    /**
     * Deteksi semester dari nama course.
     *
     * Ini dibuat menyesuaikan alur wali kelas kamu.
     * File wali kelas membaca semester dari nama course melalui course_period_service.
     *
     * Jadi mapping juga harus membantu memastikan course punya penanda:
     * - Ganjil
     * - Genap
     */
    public static function detect_course_semester(\stdClass $course): int {
        $fullname = \core_text::strtolower(trim((string)($course->fullname ?? '')));
        $shortname = \core_text::strtolower(trim((string)($course->shortname ?? '')));

        $text = $fullname . ' ' . $shortname;

        if (preg_match('/\b(ganjil|semester\s*1|semester\s*i|smt\s*1|sem\s*1)\b/u', $text)) {
            return 1;
        }

        if (preg_match('/\b(genap|semester\s*2|semester\s*ii|smt\s*2|sem\s*2)\b/u', $text)) {
            return 2;
        }

        return 0;
    }

    /**
     * Label semester untuk ditampilkan di halaman mapping.
     */
    public static function semester_label(int $semester): string {
        if ($semester === 1) {
            return 'Semester Ganjil';
        }

        if ($semester === 2) {
            return 'Semester Genap';
        }

        return 'Belum terbaca';
    }
}