<?php
namespace local_akademikmonitor\service;

defined('MOODLE_INTERNAL') || die();

/**
 * Service khusus untuk membentuk identitas course hasil generate.
 *
 * File ini dibuat supaya format nama course tidak ditulis ulang
 * di generate.php dan coursemoodle.php.
 *
 * Jadi:
 * - generate.php memakai service ini saat membuat/update course.
 * - coursemoodle.php memakai service ini saat menampilkan preview.
 *
 * Dengan begitu nama course di preview dan hasil generate selalu sama.
 */
class course_name_service {

    /**
     * Membuat label semester.
     */
    public static function semester_label(int $semester): string {
        return $semester === 1 ? 'Ganjil' : 'Genap';
    }

    /**
     * Membersihkan teks untuk fullname course.
     *
     * Untuk fullname, karakter "/" tidak dihapus supaya tahun ajaran
     * tetap tampil seperti 2025/2026.
     */
    public static function clean_fullname_part(string $text): string {
        $text = trim($text);
        $text = preg_replace('/\s+/', ' ', $text);

        return $text;
    }

    /**
     * Membersihkan teks untuk kategori/slug.
     *
     * Karakter "/" dan "\" diganti menjadi "-" supaya aman untuk kategori
     * atau bagian nama yang tidak boleh terlalu bebas.
     */
    public static function clean_course_part(string $text): string {
        $text = trim($text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = str_replace(['/', '\\'], '-', $text);

        return $text;
    }

    /**
     * Ambil label tahun ajaran secara fleksibel.
     *
     * Kenapa fleksibel?
     * Karena tabel tahun_ajaran di plugin bisa memakai field yang berbeda,
     * misalnya tahun_ajaran, nama, label, atau tahun_awal/tahun_akhir.
     */
    public static function tahun_label(?\stdClass $tahun): string {
        if (!$tahun) {
            return '-';
        }

        $fields = [
            'tahun_ajaran',
            'tahunajaran',
            'tahun',
            'nama_tahun_ajaran',
            'nama_tahunajaran',
            'label',
            'periode',
            'nama',
        ];

        foreach ($fields as $field) {
            if (property_exists($tahun, $field) && trim((string)$tahun->{$field}) !== '') {
                return trim((string)$tahun->{$field});
            }
        }

        $awalfields = ['tahun_awal', 'awal', 'startyear', 'start_year'];
        $akhirfields = ['tahun_akhir', 'akhir', 'endyear', 'end_year'];

        $awal = '';
        $akhir = '';

        foreach ($awalfields as $field) {
            if (property_exists($tahun, $field) && trim((string)$tahun->{$field}) !== '') {
                $awal = trim((string)$tahun->{$field});
                break;
            }
        }

        foreach ($akhirfields as $field) {
            if (property_exists($tahun, $field) && trim((string)$tahun->{$field}) !== '') {
                $akhir = trim((string)$tahun->{$field});
                break;
            }
        }

        if ($awal !== '' && $akhir !== '') {
            return $awal . '/' . $akhir;
        }

        return '-';
    }

    /**
     * Membuat label rombel.
     *
     * Contoh hasil:
     * X Teknik Multimedia 1
     *
     * Kalau field kelas.nama sudah berisi lengkap seperti
     * "X Teknik Multimedia 1", maka tidak akan ditambah ulang.
     */
    public static function rombel_label(\stdClass $kelas, \stdClass $jurusan): string {
        $tingkat = self::clean_course_part((string)($kelas->tingkat ?? ''));
        $jurusanname = self::clean_course_part((string)($jurusan->nama_jurusan ?? ''));
        $namakelas = self::clean_course_part((string)($kelas->nama ?? ''));

        $lowernama = strtolower($namakelas);
        $lowertingkat = strtolower($tingkat);
        $lowerjurusan = strtolower($jurusanname);

        if (
            $namakelas !== ''
            && $tingkat !== ''
            && $jurusanname !== ''
            && strpos($lowernama, $lowertingkat) !== false
            && strpos($lowernama, $lowerjurusan) !== false
        ) {
            return $namakelas;
        }

        $parts = [];

        if ($tingkat !== '') {
            $parts[] = $tingkat;
        }

        if ($jurusanname !== '') {
            $parts[] = $jurusanname;
        }

        if ($namakelas !== '') {
            $parts[] = $namakelas;
        }

        $label = self::clean_course_part(implode(' ', $parts));

        return $label !== '' ? $label : 'Kelas ' . (int)($kelas->id ?? 0);
    }

    /**
     * Membuat idnumber course.
     *
     * Format:
     * AM-TA{tahunajaranid}-K{kelasid}-KM{kurikulummapelid}-S{semester}
     *
     * Contoh:
     * AM-TA3-K50-KM12-S1
     */
    public static function idnumber(
        int $tahunajaranid,
        int $kelasid,
        int $kmid,
        int $semester
    ): string {
        return 'AM-TA' . $tahunajaranid . '-K' . $kelasid . '-KM' . $kmid . '-S' . $semester;
    }

    /**
     * Membuat shortname aman untuk Moodle.
     */
    public static function slug(string $text): string {
        $text = preg_replace('/[^A-Za-z0-9]+/', '-', $text);
        $text = trim((string)$text, '-');

        return strtoupper($text ?: 'COURSE');
    }

    /**
     * Mengambil jenis mapel.
     *
     * Urutan sumber:
     * 1. Prefix di nama mapel, misalnya [umum] Bahasa Indonesia.
     * 2. Field jenis/jenis_mapel/kategori/kelompok kalau ada.
     * 3. Fallback ke "kejuruan".
     *
     * Ini memperbaiki kasus:
     * [kejuruan] [umum] Bahasa Indonesia
     */
    public static function jenis_mapel(\stdClass $mapel): string {
        $namamapel = (string)($mapel->nama_mapel ?? '');

        if (preg_match('/^\s*\[(umum|kejuruan|muatan lokal|mulok|pilihan|wajib)\]\s*/i', $namamapel, $matches)) {
            $jenis = strtolower(trim($matches[1]));

            return $jenis === 'mulok' ? 'muatan lokal' : $jenis;
        }

        $fields = [
            'jenis',
            'jenis_mapel',
            'kategori',
            'kelompok',
            'kelompok_mapel',
        ];

        foreach ($fields as $field) {
            if (property_exists($mapel, $field) && trim((string)$mapel->{$field}) !== '') {
                $jenis = strtolower(self::clean_course_part((string)$mapel->{$field}));
                $jenis = trim($jenis);

                return $jenis === 'mulok' ? 'muatan lokal' : $jenis;
            }
        }

        return 'kejuruan';
    }

    /**
     * Membersihkan nama mapel.
     *
     * Kalau nama mapel di database:
     * [umum] Bahasa Indonesia
     *
     * Maka label mapel yang dipakai dalam fullname menjadi:
     * Bahasa Indonesia
     */
    public static function mapel_label(\stdClass $mapel): string {
        $label = self::clean_fullname_part((string)($mapel->nama_mapel ?? 'Mata Pelajaran'));

        $label = preg_replace(
            '/^\s*\[(umum|kejuruan|muatan lokal|mulok|pilihan|wajib)\]\s*/i',
            '',
            $label
        );

        $label = trim((string)$label);

        return $label !== '' ? $label : 'Mata Pelajaran';
    }

    /**
     * Membentuk semua identitas course dalam satu tempat.
     *
     * Return:
     * - idnumber
     * - fullname
     * - shortname
     * - jenis_mapel
     * - mapel_label
     * - rombel_label
     * - semester_label
     * - tahun_label
     */
    public static function build_names(
        \stdClass $mapel,
        \stdClass $kelas,
        \stdClass $jurusan,
        \stdClass $tahun,
        int $semester
    ): array {
        $jenismapel = self::jenis_mapel($mapel);
        $mapellabel = self::mapel_label($mapel);
        $rombollabel = self::rombel_label($kelas, $jurusan);
        $semesterlabel = self::semester_label($semester);

        $tahunlabel = self::clean_fullname_part(self::tahun_label($tahun));

        if ($tahunlabel === '' || $tahunlabel === '-') {
            $tahunlabel = 'Tahun Ajaran';
        }

        $fullname = '[' . $jenismapel . '] ' .
            $mapellabel . ' - ' .
            $rombollabel . ' - ' .
            $semesterlabel . '-' .
            $tahunlabel;

        $shortbase = self::slug(
            $jenismapel . '-' .
            $mapellabel . '-' .
            $rombollabel . '-' .
            $semesterlabel . '-' .
            $tahunlabel
        );

        $shortname = substr(
            $shortbase . '-TA' . (int)$tahun->id . '-K' . (int)$kelas->id . '-S' . $semester,
            0,
            95
        );

        $idnumber = self::idnumber(
            (int)$tahun->id,
            (int)$kelas->id,
            (int)$mapel->kmid,
            $semester
        );

        return [
            'idnumber' => $idnumber,
            'fullname' => $fullname,
            'shortname' => $shortname,
            'jenis_mapel' => $jenismapel,
            'mapel_label' => $mapellabel,
            'rombel_label' => $rombollabel,
            'semester_label' => $semesterlabel,
            'tahun_label' => $tahunlabel,
        ];
    }
}