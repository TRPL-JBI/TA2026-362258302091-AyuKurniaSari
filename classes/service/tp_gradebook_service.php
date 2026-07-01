<?php
namespace local_akademikmonitor\service;

defined('MOODLE_INTERNAL') || die();


/**
 * Service sinkronisasi Tujuan Pembelajaran (TP) ke gradebook Moodle.
 *
 * Konsep:
 * - TP di plugin tetap disimpan di tabel {tujuan_pembelajaran}.
 * - TP dibuat sebagai grade category Moodle.
 * - Di dalam setiap TP dibuat default Penugasan 1 dan Penugasan 2.
 * - Guru tetap bisa menambahkan item nilai lain secara manual di dalam kategori TP.
 * - Total kategori TP disimpan ke tabel relasi {grade_items_tp}.
 *
 * Struktur akhir di Grader Report:
 *
 * Course
 * - Ujian
 *   - UTS
 *   - UAS
 * - TP 10 - ...
 *   - Penugasan 1
 *   - Penugasan 2
 * - TP 11 - ...
 *   - Penugasan 1
 *   - Penugasan 2
 */
class tp_gradebook_service {

    /**
     * Prefix lama.
     *
     * Dulu TP dibuat sebagai grade item langsung:
     * AM_TP-10
     *
     * Sekarang TP dibuat sebagai kategori.
     * Prefix ini tetap dipakai untuk migrasi nilai lama supaya tidak langsung hilang.
     */
    private const OLD_IDNUMBER_PREFIX = 'am_tp_';

    /**
     * Prefix idnumber untuk total kategori TP.
     *
     * Contoh:
     * am_tp_total_10
     */
    private const TP_TOTAL_PREFIX = 'am_tp_total_';

    /**
     * Prefix idnumber untuk item penugasan default di dalam TP.
     *
     * Contoh:
     * AM_TP_ASSIGN-10-1
     * AM_TP_ASSIGN-10-2
     */
    private const TP_ASSIGNMENT_PREFIX = 'am_tp_assign_';

    /**
     * Idnumber untuk item UTS.
     */
    private const UJIAN_UTS_IDNUMBER = 'am_uts';

    /**
     * Idnumber untuk item UAS.
     */
    private const UJIAN_UAS_IDNUMBER = 'am_uas';

    /**
     * Idnumber untuk total kategori Ujian.
     */
    private const UJIAN_TOTAL_IDNUMBER = 'am_ujian_total';

    /**
     * Source update gradebook.
     *
     * Ini dipakai Moodle untuk menandai perubahan gradebook berasal dari plugin ini.
     */
    private const SOURCE = 'local_akademikmonitor';

    /**
     * Membuat struktur gradebook untuk 1 TP ke semua course yang memakai mapel TP tersebut.
     *
     * Biasanya dipanggil setelah TP baru ditambahkan/disimpan.
     *
     * Kenapa perlu fungsi ini?
     * Karena saat admin membuat TP, TP itu harus otomatis muncul di gradebook course
     * yang menggunakan mapel terkait.
     */
    public static function ensure_grade_items_for_tp(int $tpid): void {
        global $DB;

        if ($tpid <= 0) {
            return;
        }

        $tp = self::get_tp_with_context($tpid);

        if (!$tp || empty($tp->kmid)) {
            return;
        }

        // Jika TP sudah memiliki id_course, jangan disebarkan ke semua course mapel.
        // TP ini hanya milik course/kelas yang dipilih saat admin/guru membuat TP.
        if (!empty($tp->id_course) && (int)$tp->id_course > 0) {
            self::ensure_grade_items_for_tp_course((int)$tp->id, (int)$tp->id_course);
            return;
        }

        /*
         * Tidak pakai SELECT manual.
         * Ambil relasi course_mapel berdasarkan id_kurikulum_mapel.
         */
        $coursemaps = $DB->get_records('course_mapel', [
            'id_kurikulum_mapel' => (int)$tp->kmid,
        ]);

        if (!$coursemaps) {
            return;
        }

        $courseids = [];

        foreach ($coursemaps as $coursemap) {
            if (!empty($coursemap->id_course)) {
                $courseids[] = (int)$coursemap->id_course;
            }
        }

        $courseids = array_values(array_unique(array_filter($courseids)));

        if (!$courseids) {
            return;
        }

        /*
         * Ambil course memakai get_records_list, bukan query SQL manual.
         */
        $courses = $DB->get_records_list('course', 'id', $courseids);

        if (!$courses) {
            return;
        }

        foreach ($courses as $course) {
            self::ensure_grade_structure_for_tp_in_course($tp, (int)$course->id);
        }
    }

public static function ensure_grade_items_for_tp_course(
    int $tpid,
    int $courseid
): void {

    if ($tpid <= 0 || $courseid <= 0) {
        return;
    }

    $tp = self::get_tp_with_context($tpid);

    if (!$tp) {
        return;
    }

    // Pengaman penting: jika TP sudah dikunci ke id_course tertentu,
    // jangan pernah dibuatkan struktur gradebook di course lain.
    if (!empty($tp->id_course) && (int)$tp->id_course > 0 && (int)$tp->id_course !== $courseid) {
        return;
    }

    self::ensure_grade_structure_for_tp_in_course(
        $tp,
        $courseid
    );

    $tpitems = self::get_all_tp_total_items($courseid);

    self::update_sumatif_formula(
        $courseid,
        $tpitems
    );

    self::update_nilai_akhir_formula(
        $courseid
    );

    self::ensure_course_total_aggregation(
        $courseid
    );

    grade_regrade_final_grades($courseid);
}


    /**
     * Membuat struktur semua TP dari satu kurikulum_mapel ke course tertentu.
     *
     * Biasanya dipanggil saat course Moodle digenerate dari kelas/rombel.
     *
     * Kenapa perlu fungsi ini?
     * Karena setelah course dibuat, gradebook harus langsung punya:
     * - Ujian
     * - TP 1
     * - TP 2
     * - dan seterusnya
     */
    public static function ensure_grade_items_for_kurikulum_mapel(int $kmid, int $courseid = 0): void {
        global $DB;

        if ($kmid <= 0) {
            return;
        }

        /*
         * Tidak pakai SELECT JOIN manual.
         * Ambil dulu semua CP berdasarkan kurikulum_mapel.
         */
        $cps = $DB->get_records('capaian_pembelajaran', [
            'id_kurikulum_mapel' => $kmid,
        ]);

        if (!$cps) {
            return;
        }

        $cpids = [];

        foreach ($cps as $cp) {
            if (!empty($cp->id)) {
                $cpids[] = (int)$cp->id;
            }
        }

        $cpids = array_values(array_unique(array_filter($cpids)));

        if (!$cpids) {
            return;
        }

        /*
         * Ambil semua TP berdasarkan daftar CP.
         * Ini tetap Moodle DB API.
         */
        $tpsraw = $DB->get_records_list(
            'tujuan_pembelajaran',
            'id_capaian_pembelajaran',
            $cpids,
            'id_capaian_pembelajaran ASC, id ASC'
        );

        if (!$tpsraw) {
            return;
        }

        $tps = [];

        foreach ($tpsraw as $tp) {
            // Jika sinkronisasi dipanggil untuk course tertentu, ambil hanya TP
            // yang memang milik course itu. Ini mencegah TP course lain ikut
            // muncul di gradebook dan menyebabkan kategori/penugasan terlihat dobel.
            if ($courseid > 0 && property_exists($tp, 'id_course') && (int)$tp->id_course > 0 && (int)$tp->id_course !== $courseid) {
                continue;
            }

            // Untuk mode tanpa courseid, TP yang sudah punya id_course tidak disebar
            // ke semua course. TP per-course disinkronkan lewat ensure_grade_items_for_tp_course().
            if ($courseid <= 0 && property_exists($tp, 'id_course') && (int)$tp->id_course > 0) {
                continue;
            }

            $cpid = !empty($tp->id_capaian_pembelajaran)
                ? (int)$tp->id_capaian_pembelajaran
                : 0;

            if ($cpid <= 0 || empty($cps[$cpid])) {
                continue;
            }

            $cp = $cps[$cpid];

            /*
             * Tambahkan context CP ke object TP.
             * Ini menggantikan hasil JOIN.
             */
            $tp->cpid = (int)$cp->id;
            $tp->cp_deskripsi = $cp->deskripsi ?? '';
            $tp->kmid = !empty($cp->id_kurikulum_mapel)
                ? (int)$cp->id_kurikulum_mapel
                : 0;

            /*
             * Supaya aman kalau database lama belum punya kolom konten.
             */
            if (!property_exists($tp, 'konten')) {
                $tp->konten = '';
            }

            $tps[] = $tp;
        }

        if (!$tps) {
            // Tidak membersihkan gradebook secara otomatis.
            // Jika grade item/kategori dihapus saat masih dipakai calculation Moodle,
            // Grader report bisa berubah menjadi Error.
            if ($courseid > 0) {
                self::repair_broken_calculations($courseid);
            }
            return;
        }

        /*
         * Kalau courseid dikirim, langsung buat struktur gradebook hanya untuk course itu.
         */
        if ($courseid > 0) {
            self::ensure_ujian_structure($courseid);

            $allowedtpids = array_map(static function($tp) { return (int)$tp->id; }, $tps);
            // Jangan hapus kategori/item gradebook otomatis. Cukup pastikan TP aktif
            // pada course yang dipilih tersedia, lalu perbaiki formula yang rusak.

            foreach ($tps as $tp) {
                self::ensure_grade_structure_for_tp_in_course($tp, $courseid);
            }
            grade_regrade_final_grades($courseid);
            $tpitems = self::get_all_tp_total_items($courseid);
            self::ensure_course_total_aggregation($courseid);
            self::update_sumatif_formula($courseid, $tpitems);
            grade_regrade_final_grades($courseid);
            self::update_nilai_akhir_formula($courseid);

            self::ensure_course_total_aggregation($courseid);
            self::repair_broken_calculations($courseid);

            grade_regrade_final_grades($courseid);
            return;
        }

        /*
         * Kalau courseid tidak dikirim, cari semua course yang memakai kurikulum_mapel ini.
         */
        $coursemaps = $DB->get_records('course_mapel', [
            'id_kurikulum_mapel' => $kmid,
        ]);

        if (!$coursemaps) {
            return;
        }

        $courseids = [];

        foreach ($coursemaps as $coursemap) {
            if (!empty($coursemap->id_course)) {
                $courseids[] = (int)$coursemap->id_course;
            }
        }

        $courseids = array_values(array_unique(array_filter($courseids)));

        if (!$courseids) {
            return;
        }

        $courses = $DB->get_records_list('course', 'id', $courseids);

        if (!$courses) {
            return;
        }

        foreach ($courses as $course) {
            self::ensure_ujian_structure((int)$course->id);

            foreach ($tps as $tp) {
                self::ensure_grade_structure_for_tp_in_course($tp, (int)$course->id);
            }
            $tpitems = self::get_all_tp_total_items((int)$course->id);

            self::update_sumatif_formula(
                (int)$course->id,
                $tpitems
            );

            self::update_nilai_akhir_formula(
                (int)$course->id
            );

            self::ensure_course_total_aggregation((int)$course->id);
            self::repair_broken_calculations((int)$course->id);

            grade_regrade_final_grades((int)$course->id);
        }
    }
    private static function get_all_tp_total_items(
        int $courseid
    ): array {

        global $DB;

        if ($DB->get_manager()->table_exists('grade_items_tp')) {
            return $DB->get_records_sql("
                SELECT gi.*
                  FROM {grade_items} gi
                  JOIN {grade_items_tp} gitp ON gitp.id_grade_items = gi.id
                  JOIN {tujuan_pembelajaran} tp ON tp.id = gitp.id_tp
                 WHERE gi.courseid = :courseid
                   AND gi.idnumber LIKE :prefix
                   AND (tp.id_course = :tpcourseid OR tp.id_course IS NULL OR tp.id_course = 0)
                 ORDER BY gi.id ASC
            ", [
                'courseid' => $courseid,
                'tpcourseid' => $courseid,
                'prefix' => 'am_tp_total_%',
            ]);
        }

        return $DB->get_records_sql("
            SELECT gi.*
            FROM {grade_items} gi
            WHERE gi.courseid = :courseid
            AND gi.idnumber LIKE :prefix
            ORDER BY gi.id ASC
        ", [
            'courseid' => $courseid,
            'prefix' => 'am_tp_total_%',
        ]);
    }

    /**
     * Update nama kategori TP jika data TP diedit.
     *
     * Kenapa perlu?
     * Karena nama TP di tabel plugin dan nama kategori di gradebook harus tetap sinkron.
     *
     * Contoh:
     * TP awal:
     * TP 10 - Descriptive text
     *
     * Setelah diedit:
     * TP 10 - Descriptive text bidang kejuruan
     *
     * Maka nama di gradebook juga harus ikut berubah.
     */
    public static function sync_grade_item_name(int $tpid): void {
        global $DB, $CFG;

        if ($tpid <= 0) {
            return;
        }

        require_once($CFG->libdir . '/grade/grade_category.php');
        require_once($CFG->libdir . '/grade/grade_item.php');
        require_once($CFG->libdir . '/gradelib.php');

        $tp = self::get_tp_with_context($tpid);

        if (!$tp) {
            return;
        }

        /*
         * Tidak pakai SELECT JOIN manual.
         * Ambil relasi dari tabel plugin dulu.
         */
        $relations = $DB->get_records('grade_items_tp', [
            'id_tp' => $tpid,
        ]);

        if (!$relations) {
            return;
        }

        $gradeitemids = [];

        foreach ($relations as $relation) {
            if (!empty($relation->id_grade_items)) {
                $gradeitemids[] = (int)$relation->id_grade_items;
            }
        }

        $gradeitemids = array_values(array_unique(array_filter($gradeitemids)));

        if (!$gradeitemids) {
            return;
        }

        /*
         * Ambil grade_items memakai DB API.
         */
        $gradeitemrecords = $DB->get_records_list('grade_items', 'id', $gradeitemids);

        if (!$gradeitemrecords) {
            return;
        }

        foreach ($gradeitemrecords as $record) {
            /*
             * Pastikan yang disinkronkan adalah total kategori TP versi baru,
             * bukan grade item lain.
             */
            if ((string)$record->idnumber !== self::build_tp_total_idnumber($tpid)) {
                continue;
            }

            $gradeitem = \grade_item::fetch([
                'id' => (int)$record->id,
            ]);

            if (!$gradeitem || empty($gradeitem->iteminstance)) {
                continue;
            }

            $category = \grade_category::fetch([
                'id' => (int)$gradeitem->iteminstance,
            ]);

if (!$category) {
    continue;
}

$newname = self::build_tp_category_name($tp);

$changed = false;

if ((string)$category->fullname !== $newname) {
    $category->fullname = $newname;
    $changed = true;
}


            if ((int)$category->aggregateonlygraded !== 1) {
                $category->aggregateonlygraded = 1;
                $changed = true;
            }

            if ($changed) {
                $category->update(self::SOURCE);
            }

            self::set_category_total_idnumber(
                $category,
                self::build_tp_total_idnumber($tpid)
            );

            self::ensure_default_tp_assignments(
                (int)$record->courseid,
                $category,
                (int)$tp->id
            );

            self::ensure_course_total_aggregation((int)$record->courseid);

            grade_regrade_final_grades((int)$record->courseid);
            
        }
    }

    /**
     * Cek apakah sebuah TP sudah memiliki nilai (grade) di gradebook.
     *
     * Logika:
     * 1. Ambil semua grade_item yang terhubung ke TP ini via tabel grade_items_tp.
     * 2. Untuk setiap grade_item (termasuk child item di dalam kategori TP),
     *    cek apakah ada baris di tabel grade_grades dengan finalgrade NOT NULL.
     *
     * Return true  → TP sudah dinilai, TIDAK BOLEH dihapus.
     * Return false → TP belum dinilai, boleh dihapus.
     */
    public static function tp_has_grades(int $tpid): bool {
        global $DB;

        if ($tpid <= 0) {
            return false;
        }

        // Aman untuk install lama/upgrade yang belum memiliki tabel relasi.
        if (!$DB->get_manager()->table_exists('grade_items_tp')) {
            return false;
        }

        // Ambil relasi grade_item dari tabel plugin.
        $relations = $DB->get_records('grade_items_tp', ['id_tp' => $tpid]);
        if (!$relations) {
            return false;
        }

        $gradeitemids = [];
        foreach ($relations as $rel) {
            if (!empty($rel->id_grade_items)) {
                $gradeitemids[] = (int)$rel->id_grade_items;
            }
        }
        $gradeitemids = array_values(array_unique(array_filter($gradeitemids)));

        if (!$gradeitemids) {
            return false;
        }

        // Ambil record grade_items untuk mengetahui courseid dan iteminstance (category id).
        $gradeitemrecords = $DB->get_records_list('grade_items', 'id', $gradeitemids);

        // Kumpulkan semua grade_item id yang perlu dicek nilainya:
        // - grade_item total kategori TP
        // - semua child grade_item di dalam kategori TP (Penugasan 1, Penugasan 2, dsb)
        $check_ids = $gradeitemids;

        foreach ($gradeitemrecords as $gi) {
            // Jika ini adalah total kategori (itemtype='category'), cari semua child-nya.
            if (!empty($gi->iteminstance) && (string)$gi->itemtype === 'category') {
                $children = $DB->get_records('grade_items', [
                    'categoryid' => (int)$gi->iteminstance,
                ], '', 'id');
                foreach ($children as $child) {
                    $check_ids[] = (int)$child->id;
                }
            }
        }

        $check_ids = array_values(array_unique(array_filter($check_ids)));

        if (!$check_ids) {
            return false;
        }

        // Cek apakah ada baris grade_grades dengan finalgrade NOT NULL untuk item-item ini.
        [$insql, $inparams] = $DB->get_in_or_equal($check_ids, SQL_PARAMS_NAMED, 'gid');
        $sql = "SELECT COUNT(gg.id)
                  FROM {grade_grades} gg
                 WHERE gg.itemid {$insql}
                   AND gg.finalgrade IS NOT NULL";

        $count = (int)$DB->count_records_sql($sql, $inparams);
        return $count > 0;
    }

    /**
     * Hapus struktur gradebook untuk TP.
     *
     * Catatan:
     * - Yang dihapus adalah kategori TP milik plugin.
     * - Item Penugasan 1 dan Penugasan 2 di dalamnya ikut hilang karena parent category dihapus.
     *
     * Kenapa perlu?
     * Supaya kalau TP dihapus dari plugin, gradebook tidak meninggalkan kategori lama.
     */
    public static function delete_grade_items_for_tp(int $tpid): void {
        global $DB, $CFG;

        if ($tpid <= 0) {
            return;
        }

        require_once($CFG->libdir . '/grade/grade_category.php');
        require_once($CFG->libdir . '/grade/grade_item.php');
        require_once($CFG->libdir . '/gradelib.php');

        /*
         * Tidak pakai SELECT JOIN manual.
         * Ambil relasi dulu dari tabel plugin.
         */
        $relations = $DB->get_records('grade_items_tp', [
            'id_tp' => $tpid,
        ]);

        if (!$relations) {
            return;
        }

        $gradeitemids = [];

        foreach ($relations as $relation) {
            if (!empty($relation->id_grade_items)) {
                $gradeitemids[] = (int)$relation->id_grade_items;
            }
        }

        $gradeitemids = array_values(array_unique(array_filter($gradeitemids)));

        if (!$gradeitemids) {
            $DB->delete_records('grade_items_tp', [
                'id_tp' => $tpid,
            ]);
            return;
        }

        $gradeitemrecords = $DB->get_records_list('grade_items', 'id', $gradeitemids);

        if ($gradeitemrecords) {
            foreach ($gradeitemrecords as $record) {
                /*
                 * Pastikan hanya kategori TP versi baru yang dihapus.
                 */
                if ((string)$record->idnumber !== self::build_tp_total_idnumber($tpid)) {
                    continue;
                }

                if (empty($record->iteminstance)) {
                    continue;
                }

                $category = \grade_category::fetch([
                    'id' => (int)$record->iteminstance,
                ]);

                if ($category) {
                    $courseid = !empty($record->courseid) ? (int)$record->courseid : 0;

                    $category->delete(self::SOURCE);

                    if ($courseid > 0) {
                        grade_regrade_final_grades($courseid);
                    }
                }
            }
        }

        $DB->delete_records('grade_items_tp', [
            'id_tp' => $tpid,
        ]);
    }

    /**
     * Membersihkan kategori TP lama di gradebook course yang bukan milik course terpilih.
     *
     * Dipakai untuk memperbaiki kasus sebelumnya: saat generate/sinkron gradebook,
     * semua TP dari mapel ikut masuk ke course, padahal sekarang TP memakai id_course.
     * Yang dihapus hanya struktur TP milik plugin dan hanya jika belum ada nilai.
     */
    private static function cleanup_obsolete_tp_categories_for_course(int $courseid, array $allowedtpids): void {
        // Dinonaktifkan sengaja.
        // Sebelumnya fungsi ini menghapus kategori TP lama di gradebook.
        // Jika kategori/item yang dihapus masih dirujuk oleh calculation Moodle
        // seperti Sumatif Total, Nilai Akhir, atau Course total, Grader report
        // dapat menampilkan Error. Untuk keamanan data nilai, refresh sekarang
        // hanya memperbaiki formula yang rusak tanpa menghapus struktur lama.
        if ($courseid > 0) {
            self::repair_broken_calculations($courseid);
        }
    }

    /**
     * Membuat struktur TP di dalam satu course.
     *
     * Ini bagian inti:
     * - Pastikan kategori Ujian ada.
     * - Buat TP sebagai kategori.
     * - Set idnumber pada total kategori TP.
     * - Buat Penugasan 1 dan Penugasan 2 di dalam TP.
     * - Simpan relasi total kategori TP ke tabel {grade_items_tp}.
     */
    private static function ensure_grade_structure_for_tp_in_course(\stdClass $tp, int $courseid): void {
        global $DB, $CFG;
        if ($courseid <= 0 || empty($tp->id)) {
            return;
        }
        require_once($CFG->libdir . '/grade/grade_category.php');
        require_once($CFG->libdir . '/grade/grade_item.php');
        require_once($CFG->libdir . '/gradelib.php');
        self::ensure_ujian_structure($courseid);
        $category = self::ensure_tp_category($courseid, $tp);
        if (!$category) {
            return;
        }
        self::set_category_total_idnumber(
            $category,
            self::build_tp_total_idnumber((int)$tp->id)
        );
        self::migrate_old_tp_item_if_exists($courseid, $category, (int)$tp->id);
        self::ensure_default_tp_assignments($courseid, $category, (int)$tp->id);
        $totalitem = $category->load_grade_item();
        if ($totalitem && !empty($totalitem->id)) {
            $DB->delete_records('grade_items_tp', [
                'id_tp' => (int)$tp->id,
            ]);
            $DB->insert_record('grade_items_tp', (object)[
                'id_grade_items' => (int)$totalitem->id,
                'id_tp' => (int)$tp->id,
            ]);
        }
        self::ensure_course_total_aggregation($courseid);
        self::reorder_final_grade_items($courseid);
        grade_regrade_final_grades($courseid);
    }

    /**
     * Ambil atau buat kategori TP.
     *
     * Kenapa TP harus kategori?
     * Karena satu TP bisa memiliki banyak penilaian:
     * - Penugasan 1
     * - Penugasan 2
     * - Praktik
     * - Sumatif
     * - Projek
     *
     * Moodle akan menghitung total TP dari nilai-nilai di dalam kategori ini.
     */
    private static function ensure_tp_category(int $courseid, \stdClass $tp): ?\grade_category {
        global $CFG;

        require_once($CFG->libdir . '/grade/grade_category.php');

        $fullname = self::build_tp_category_name($tp);

        /*
         * Cari kategori TP berdasarkan idnumber total kategori.
         */
        $category = self::find_tp_category_by_total_idnumber($courseid, (int)$tp->id);

        // Jangan mencari kategori hanya berdasarkan nama.
        // Nama konten bisa sama pada TP berbeda, sehingga pencarian berdasarkan nama
        // dapat membuat satu kategori dipakai oleh beberapa TP dan memicu struktur dobel.
        if ($category) {
            $changed = false;

            if ((string)$category->fullname !== $fullname) {
                $category->fullname = $fullname;
                $changed = true;
            }



            if ((int)$category->aggregateonlygraded !== 1) {
                $category->aggregateonlygraded = 1;
                $changed = true;
            }

            if ($changed) {
                $category->update(self::SOURCE);
            }

            return $category;
        }

        /*
         * Buat kategori baru untuk TP.
         */
        $category = new \grade_category();
        $category->courseid = $courseid;
        $category->fullname = $fullname;
        $category->aggregation = GRADE_AGGREGATE_MEAN;
        $category->aggregateonlygraded = 1;
        $category->insert(self::SOURCE);

        return $category;
    }

    /**
     * Cari kategori TP dari idnumber total kategorinya.
     *
     * Grade category tidak punya idnumber langsung.
     * Yang punya idnumber adalah grade item total dari kategori tersebut.
     */
    private static function find_tp_category_by_total_idnumber(int $courseid, int $tpid): ?\grade_category {
        global $CFG;

        require_once($CFG->libdir . '/grade/grade_category.php');
        require_once($CFG->libdir . '/grade/grade_item.php');

        $totalitem = \grade_item::fetch([
            'courseid' => $courseid,
            'itemtype' => 'category',
            'idnumber' => self::build_tp_total_idnumber($tpid),
        ]);

        if (!$totalitem || empty($totalitem->iteminstance)) {
            return null;
        }

        $category = \grade_category::fetch([
            'id' => (int)$totalitem->iteminstance,
        ]);

        return $category ?: null;
    }
private static function cleanup_old_assignment_items(
    int $courseid,
    int $tpid
): void {

    global $DB;

    $oldpatterns = [
        'AM-TP-ASSIGN-' . $tpid . '-1',
        'AM-TP-ASSIGN-' . $tpid . '-2',
        'AM_TP_ASSIGN-' . $tpid . '-1',
        'AM_TP_ASSIGN-' . $tpid . '-2',
    ];

    foreach ($oldpatterns as $idnumber) {

        $item = \grade_item::fetch([
            'courseid' => $courseid,
            'idnumber' => $idnumber,
        ]);

        if ($item) {
            $item->delete(self::SOURCE);
        }
    }
}
    /**
     * Membuat item default di dalam kategori TP:
     * - Penugasan 1
     * - Penugasan 2
     *
     * Kenapa default dibuat?
     * Supaya setelah course digenerate, guru langsung melihat struktur TP
     * dan bisa langsung mengisi nilai.
     */
    private static function ensure_default_tp_assignments(
        int $courseid,
        \grade_category $category,
        int $tpid
    ): void {
        self::cleanup_old_assignment_items($courseid, $tpid);
        self::ensure_manual_grade_item(
            $courseid,
            'Penugasan 1',
            self::build_tp_assignment_idnumber($tpid, 1),
            (int)$category->id,
            100.0
        );

        self::ensure_manual_grade_item(
            $courseid,
            'Penugasan 2',
            self::build_tp_assignment_idnumber($tpid, 2),
            (int)$category->id,
            100.0
        );
    }

    /**
     * Migrasi item TP versi lama.
     *
     * Versi lama:
     * - TP dibuat sebagai grade item langsung.
     * - Contoh idnumber: AM_TP-10
     *
     * Versi baru:
     * - TP menjadi kategori.
     * - Nilai lama dipindahkan menjadi Penugasan 1.
     *
     * Kenapa perlu?
     * Supaya ketika kamu sudah pernah isi nilai di struktur lama,
     * nilai itu tidak langsung hilang saat struktur diubah.
     */
    private static function migrate_old_tp_item_if_exists(
        int $courseid,
        \grade_category $category,
        int $tpid
    ): void {
        global $CFG;

        require_once($CFG->libdir . '/grade/grade_item.php');

        $oldidnumber = self::OLD_IDNUMBER_PREFIX . $tpid;
        $newidnumber = self::build_tp_assignment_idnumber($tpid, 1);

        $olditem = \grade_item::fetch([
            'courseid' => $courseid,
            'idnumber' => $oldidnumber,
        ]);

        if (!$olditem) {
            return;
        }

        $olditem->categoryid = (int)$category->id;
        $olditem->itemname = 'Penugasan 1';
        $olditem->idnumber = $newidnumber;
        $olditem->gradetype = GRADE_TYPE_VALUE;
        $olditem->grademin = 0;
        $olditem->grademax = 100;
        $olditem->hidden = 0;
        $olditem->locked = 0;
        $olditem->update(self::SOURCE);
    }

    /**
     * Membuat struktur kategori Ujian.
     *
     * Struktur:
     * Ujian
     * - UTS
     * - UAS
     *
     * Kenapa dipisah?
     * Karena UTS dan UAS bukan bagian dari satu TP tertentu.
     * Jadi lebih rapi kalau tetap berada di kategori Ujian.
     */
    private static function ensure_ujian_structure(int $courseid): void {
        global $CFG;

        if ($courseid <= 0) {
            return;
        }

        require_once($CFG->libdir . '/grade/grade_category.php');
        require_once($CFG->libdir . '/grade/grade_item.php');

        /*
         * Cari kategori Ujian.
         * Cek juga nama "ujian" kecil untuk memperbaiki struktur lama.
         */
        $category = \grade_category::fetch([
            'courseid' => $courseid,
            'fullname' => 'Ujian',
        ]);

        if (!$category) {
            $category = \grade_category::fetch([
                'courseid' => $courseid,
                'fullname' => 'ujian',
            ]);
        }

        if ($category) {
            $changed = false;

            if ((string)$category->fullname !== 'Ujian') {
                $category->fullname = 'Ujian';
                $changed = true;
            }

            if ((int)$category->aggregateonlygraded !== 1) {
                $category->aggregateonlygraded = 1;
                $changed = true;
            }

            if ($changed) {
                $category->update(self::SOURCE);
            }
        } else {
            $category = new \grade_category();
            $category->courseid = $courseid;
            $category->fullname = 'Ujian';
            $category->aggregation = GRADE_AGGREGATE_MEAN;
            $category->aggregateonlygraded = 1;
            $category->insert(self::SOURCE);
        }

        self::ensure_manual_grade_item(
            $courseid,
            'UTS',
            self::UJIAN_UTS_IDNUMBER,
            (int)$category->id,
            100.0
        );

        self::ensure_manual_grade_item(
            $courseid,
            'UAS',
            self::UJIAN_UAS_IDNUMBER,
            (int)$category->id,
            100.0
        );

        self::set_category_total_idnumber(
            $category,
            self::UJIAN_TOTAL_IDNUMBER
        );
    }

    /**
     * Ambil atau buat manual grade item.
     *
     * Dipakai untuk:
     * - UTS
     * - UAS
     * - Penugasan 1
     * - Penugasan 2
     *
     * Kenapa dibuat dalam satu fungsi?
     * Supaya proses pembuatan grade item konsisten dan tidak mengulang kode.
     */
    private static function ensure_manual_grade_item(
        int $courseid,
        string $itemname,
        string $idnumber,
        int $categoryid,
        float $grademax = 100.0
    ): ?\grade_item {
        global $CFG;

        if ($courseid <= 0 || $categoryid <= 0 || trim($itemname) === '' || trim($idnumber) === '') {
            return null;
        }

        require_once($CFG->libdir . '/grade/grade_item.php');

        $gradeitem = \grade_item::fetch([
            'courseid' => $courseid,
            'idnumber' => $idnumber,
        ]);

        if ($gradeitem) {
            $changed = false;

            if ((string)$gradeitem->itemname !== $itemname) {
                $gradeitem->itemname = $itemname;
                $changed = true;
            }

            if ((int)$gradeitem->categoryid !== $categoryid) {
                $gradeitem->categoryid = $categoryid;
                $changed = true;
            }

            if ((int)$gradeitem->gradetype !== GRADE_TYPE_VALUE) {
                $gradeitem->gradetype = GRADE_TYPE_VALUE;
                $changed = true;
            }

            if ((float)$gradeitem->grademin !== 0.0) {
                $gradeitem->grademin = 0;
                $changed = true;
            }

            if ((float)$gradeitem->grademax !== (float)$grademax) {
                $gradeitem->grademax = $grademax;
                $changed = true;
            }

            if ((int)$gradeitem->hidden !== 0) {
                $gradeitem->hidden = 0;
                $changed = true;
            }

            if ((int)$gradeitem->locked !== 0) {
                $gradeitem->locked = 0;
                $changed = true;
            }

            if ($changed) {
                $gradeitem->update(self::SOURCE);
            }

            return $gradeitem;
        }

        $gradeitem = new \grade_item();
        $gradeitem->courseid = $courseid;
        $gradeitem->categoryid = $categoryid;
        $gradeitem->itemtype = 'manual';
        $gradeitem->itemname = $itemname;
        $gradeitem->idnumber = $idnumber;
        $gradeitem->gradetype = GRADE_TYPE_VALUE;
        $gradeitem->grademin = 0;
        $gradeitem->grademax = $grademax;
        $gradeitem->hidden = 0;
        $gradeitem->locked = 0;
        $gradeitem->insert(self::SOURCE);

        return $gradeitem;
    }

    /**
     * Set idnumber pada total kategori.
     *
     * Kenapa penting?
     * Karena total kategori TP inilah yang dibaca sebagai nilai akhir TP.
     *
     * Contoh:
     * TP 10
     * - Penugasan 1 = 80
     * - Penugasan 2 = 90
     *
     * Total kategori TP 10 = 85
     *
     * Total itulah yang bisa dipakai untuk rapor dan capaian kompetensi.
     */
    private static function set_category_total_idnumber(\grade_category $category, string $idnumber): void {
        $gradeitem = $category->load_grade_item();

        if (!$gradeitem) {
            return;
        }

        $changed = false;

        if ((string)$gradeitem->idnumber !== $idnumber) {
            $gradeitem->idnumber = $idnumber;
            $changed = true;
        }

        if ((float)$gradeitem->grademax !== 100.0) {
            $gradeitem->grademax = 100;
            $changed = true;
        }

        if ((int)$gradeitem->hidden !== 0) {
            $gradeitem->hidden = 0;
            $changed = true;
        }

        if ($changed) {
            $gradeitem->update(self::SOURCE);
        }
    }

    /**
     * Mengatur course total.
     *
     * Di konsep baru:
     * - Tidak pakai rumus khusus yang hanya membaca Ujian + Sumatif.
     * - Kategori Sumatif tidak dipakai lagi.
     * - Course total memakai rata-rata dari kategori:
     *   - Ujian
     *   - TP 10
     *   - TP 11
     *   - TP 12
     *
     * Kenapa calculation dikosongkan?
     * Karena kalau course total masih punya calculation lama,
     * Moodle akan tetap menghitung dari rumus lama dan TP baru bisa tidak ikut terbaca.
     */
    private static function ensure_course_total_aggregation(int $courseid): void {
        global $CFG;

        if ($courseid <= 0) {
            return;
        }

        require_once($CFG->libdir . '/grade/grade_category.php');
        require_once($CFG->libdir . '/grade/grade_item.php');
        require_once($CFG->libdir . '/gradelib.php');

        $coursecategory = \grade_category::fetch_course_category($courseid);

        if (!$coursecategory) {
            return;
        }

        $changed = false;



        if ((int)$coursecategory->aggregateonlygraded !== 1) {
            $coursecategory->aggregateonlygraded = 1;
            $changed = true;
        }

        if ($changed) {
            $coursecategory->update(self::SOURCE);
        }

        $courseitem = $coursecategory->load_grade_item();
        if ($courseitem && !empty($courseitem->calculation)) {
            // Kembalikan Course total ke perhitungan bawaan kategori Moodle.
            // Ini membersihkan formula lama yang bisa menyebabkan Error.
            $courseitem->set_calculation('');
            $courseitem->force_regrading();
        }

        grade_regrade_final_grades($courseid);
    }

    /**
     * Refresh aman untuk memperbaiki Grader report yang muncul Error.
     *
     * Penyebab umum Error adalah calculation Moodle masih mengarah ke idnumber
     * grade item yang sudah berubah/hilang. Fungsi ini tidak menghapus nilai,
     * tidak menghapus kategori, dan hanya mengosongkan calculation yang rusak.
     */
    public static function repair_broken_calculations(int $courseid): void {
        global $DB, $CFG;

        if ($courseid <= 0) {
            return;
        }

        require_once($CFG->libdir . '/grade/grade_item.php');
        require_once($CFG->libdir . '/grade/grade_category.php');
        require_once($CFG->libdir . '/gradelib.php');

        $items = $DB->get_records('grade_items', ['courseid' => $courseid]);
        if (!$items) {
            return;
        }

        foreach ($items as $record) {
            $calculation = trim((string)($record->calculation ?? ''));
            if ($calculation === '') {
                continue;
            }

            $broken = false;
            if (preg_match_all('/\[\[([^\]]+)\]\]/', $calculation, $matches)) {
                foreach ($matches[1] as $idnumber) {
                    $idnumber = trim((string)$idnumber);
                    if ($idnumber === '') {
                        continue;
                    }
                    if (!$DB->record_exists('grade_items', [
                        'courseid' => $courseid,
                        'idnumber' => $idnumber,
                    ])) {
                        $broken = true;
                        break;
                    }
                }
            }

            if (!$broken) {
                continue;
            }

            $gradeitem = \grade_item::fetch(['id' => (int)$record->id]);
            if ($gradeitem) {
                $gradeitem->set_calculation('');
                $gradeitem->force_regrading();
            }
        }

        // Pastikan course total tidak menyimpan formula lama.
        $coursecategory = \grade_category::fetch_course_category($courseid);
        if ($coursecategory) {
            $courseitem = $coursecategory->load_grade_item();
            if ($courseitem && !empty($courseitem->calculation)) {
                $courseitem->set_calculation('');
                $courseitem->force_regrading();
            }
        }

        grade_regrade_final_grades($courseid);
    }

    /**
     * Ambil TP lengkap dengan context CP dan kurikulum_mapel.
     *
     * Tidak pakai SELECT JOIN manual.
     *
     * Alur datanya:
     * tujuan_pembelajaran
     * -> capaian_pembelajaran
     * -> id_kurikulum_mapel
     */
    private static function get_tp_with_context(int $tpid): ?\stdClass {
        global $DB;

        if ($tpid <= 0) {
            return null;
        }

        $tp = $DB->get_record(
            'tujuan_pembelajaran',
            ['id' => $tpid],
            '*',
            IGNORE_MISSING
        );

        if (!$tp) {
            return null;
        }

        if (empty($tp->id_capaian_pembelajaran)) {
            return null;
        }

        $cp = $DB->get_record(
            'capaian_pembelajaran',
            ['id' => (int)$tp->id_capaian_pembelajaran],
            '*',
            IGNORE_MISSING
        );

        if (!$cp) {
            return null;
        }

        /*
         * Tambahkan data CP ke object TP.
         * Ini pengganti hasil JOIN.
         */
        $tp->cpid = (int)$cp->id;
        $tp->cp_deskripsi = $cp->deskripsi ?? '';
        $tp->kmid = !empty($cp->id_kurikulum_mapel)
            ? (int)$cp->id_kurikulum_mapel
            : 0;

        /*
         * Kolom konten ini baru.
         * Jadi dibuat aman kalau database belum punya/record belum membawa property itu.
         */
        if (!property_exists($tp, 'konten')) {
            $tp->konten = '';
        }

        return $tp;
    }

    /**
     * Membuat nama kategori TP yang tampil di Grader Report.
     *
     * Contoh:
     * TP 10 - Descriptive text bidang kejuruan
     */
    private static function build_tp_category_name(\stdClass $tp): string {
        $label = self::get_tp_short_text($tp);

        if ($label === '') {
            $label = 'Tujuan Pembelajaran';
        }

        return self::shorten($label, 90);
    }

    /**
     * Membuat idnumber total kategori TP.
     *
     * Contoh:
     * am_tp_total_10
     */
    private static function build_tp_total_idnumber(int $tpid): string {
        return self::TP_TOTAL_PREFIX . $tpid;
    }

    /**
     * Membuat idnumber item penugasan di dalam TP.
     *
     * Contoh:
     * AM_TP_ASSIGN-10-1
     * AM_TP_ASSIGN-10-2
     */
    private static function build_tp_assignment_idnumber(int $tpid, int $number): string {
        return self::TP_ASSIGNMENT_PREFIX . $tpid . '_' . $number;
    }

    /**
     * Menentukan teks utama untuk nama TP.
     *
     * Prioritas:
     * 1. konten
     * 2. kompetensi
     * 3. deskripsi
     *
     * Kenapa konten diprioritaskan?
     * Karena kamu sebelumnya menambahkan kolom konten untuk isi singkat TP.
     * Jadi lebih cocok untuk nama kolom/kategori di gradebook.
     */
    private static function get_tp_short_text(\stdClass $tp): string {
        foreach (['konten', 'kompetensi', 'deskripsi'] as $field) {
            if (!empty($tp->{$field}) && trim((string)$tp->{$field}) !== '') {
                return trim((string)$tp->{$field});
            }
        }

        return '';
    }

    /**
     * Memotong teks supaya nama kategori tidak terlalu panjang di Grader Report.
     */
    private static function shorten(string $text, int $max): string {
        $text = trim(strip_tags($text));
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim((string)$text);

        if ($text === '') {
            return '';
        }

        if (\core_text::strlen($text) <= $max) {
            return $text;
        }

        return \core_text::substr($text, 0, $max - 3) . '...';
    }
    private static function reorder_final_grade_items(int $courseid): void {
        global $DB;

        $sumatif = \grade_item::fetch([
            'courseid' => $courseid,
            'idnumber' => 'am_sumatif_total',
        ]);

        $akhir = \grade_item::fetch([
            'courseid' => $courseid,
            'idnumber' => 'am_nilai_akhir',
        ]);

        if (!$sumatif || !$akhir) {
            return;
        }

        $maxsortorder = $DB->get_field_sql(
            "SELECT MAX(sortorder)
            FROM {grade_items}
            WHERE courseid = ?",
            [$courseid]
        );

        $sumatif->sortorder = $maxsortorder + 1;
        $sumatif->update();

        $akhir->sortorder = $maxsortorder + 2;
        $akhir->update();
    }
    private static function ensure_sumatif_total_item(int $courseid): ?\grade_item {
        global $DB; global $CFG;

        require_once($CFG->libdir . '/gradelib.php');

        $idnumber = 'am_sumatif_total';

        $existing = \grade_item::fetch([
            'courseid' => $courseid,
            'idnumber' => $idnumber,
        ]);

        if ($existing) {
            return $existing;
        }

        $item = new \grade_item();

        $item->courseid = $courseid;
        $item->itemname = 'Sumatif Total';
        $item->itemtype = 'manual';
        $item->gradetype = GRADE_TYPE_VALUE;
        $item->grademax = 100;
        $item->grademin = 0;
        $item->idnumber = $idnumber;

        $item->insert();

        return $item;
    }
private static function update_sumatif_formula(
    int $courseid,
    array $tpitems
): void {
    $sumatif = self::ensure_sumatif_total_item($courseid);
    if (!$sumatif || empty($tpitems)) {
        return;
    }

    $refs = [];

    foreach ($tpitems as $item) {

        if (empty($item->idnumber)) {
            continue;
        }

        $refs[] = '[[' . $item->idnumber . ']]';
    }

    if (empty($refs)) {
        return;
    }

    $formula = '=average(' . implode(',', $refs) . ')';

    $sumatif->set_calculation($formula);

    $sumatif->update();

    $sumatif->force_regrading();

    grade_regrade_final_grades($courseid);
}

private static function ensure_nilai_akhir_item(int $courseid): ?\grade_item {

    $idnumber = 'am_nilai_akhir';

    $existing = \grade_item::fetch([
        'courseid' => $courseid,
        'idnumber' => $idnumber,
    ]);

    if ($existing) {
        return $existing;
    }

    $item = new \grade_item();

    $item->courseid = $courseid;
    $item->itemname = 'Nilai Akhir';
    $item->itemtype = 'manual';
    $item->gradetype = GRADE_TYPE_VALUE;
    $item->grademax = 100;
    $item->grademin = 0;
    $item->idnumber = $idnumber;

    $item->insert();

    return $item;
}

private static function update_nilai_akhir_formula(
    int $courseid
): void {

    $akhir = self::ensure_nilai_akhir_item($courseid);

    if (!$akhir) {
        return;
    }

    $formula = '=round(average([[am_sumatif_total]],[[am_ujian_total]]),0)';
    $akhir->set_calculation($formula);

    $akhir->update();

    $akhir->force_regrading();

    grade_regrade_final_grades($courseid);
}

public static function tp_has_active_course(int $tpid): bool {
    global $DB;

    if (!$DB->get_manager()->table_exists('grade_items_tp')) {
        return false;
    }

    $links = $DB->get_records(
        'grade_items_tp',
        ['id_tp' => $tpid]
    );

    foreach ($links as $link) {

        $gradeitem = $DB->get_record(
            'grade_items',
            ['id' => $link->id_grade_items]
        );

        if (!$gradeitem) {
            continue;
        }

        if ($DB->record_exists(
            'course',
            ['id' => $gradeitem->courseid]
        )) {
            return true;
        }
    }

    return false;
}
}