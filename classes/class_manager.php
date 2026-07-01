<?php
namespace local_akademikmonitor;

defined('MOODLE_INTERNAL') || die();

/**
 * Service sederhana untuk proses naik kelas / kelulusan.
 *
 * Konsep:
 * - Wali kelas adalah penugasan rombel, bukan jenis user khusus.
 * - Saat naik kelas, wali kelas baru dikosongkan supaya bisa dipilih ulang.
 * - Peserta yang disalin hanya siswa.
 * - Course tidak dibuat otomatis di sini. Course tetap dibuat dari menu Course Moodle,
 *   supaya alur generate course yang sudah fix tidak diubah.
 */
class class_manager {

    public static function get_next_tingkat(string $tingkat): string {
        $tingkat = strtoupper(trim($tingkat));
        if ($tingkat === 'X') {
            return 'XI';
        }
        if ($tingkat === 'XI') {
            return 'XII';
        }
        return 'LULUS';
    }

    public static function naikkan_kelas(int $kelasid): array {
        global $DB;

        if ($kelasid <= 0) {
            throw new \exception('invalidrecord');
        }

        $kelas = $DB->get_record('kelas', ['id' => $kelasid], '*', MUST_EXIST);
        $nexttingkat = self::get_next_tingkat((string)$kelas->tingkat);

        if ($nexttingkat === 'LULUS') {
            return self::luluskan($kelas);
        }

        $nexttahun = self::get_next_tahun_ajaran((int)$kelas->id_tahun_ajaran);
        if (!$nexttahun) {
            throw new \Exception('Tahun ajaran berikutnya belum tersedia. Buat tahun ajaran baru terlebih dahulu.');
        }

        $newnama = self::build_next_kelas_nama((string)$kelas->nama, (string)$kelas->tingkat, $nexttingkat);

        $existing = $DB->get_record('kelas', [
            'nama' => $newnama,
            'tingkat' => $nexttingkat,
            'id_jurusan' => (int)$kelas->id_jurusan,
            'id_tahun_ajaran' => (int)$nexttahun->id,
        ], '*', IGNORE_MULTIPLE);

        if ($existing) {
            $newid = (int)$existing->id;
            $created = false;
        } else {
            $newkelas = new \stdClass();
            $newkelas->nama = $newnama;
            $newkelas->tingkat = $nexttingkat;
            $newkelas->id_jurusan = (int)$kelas->id_jurusan;
            $newkelas->id_tahun_ajaran = (int)$nexttahun->id;
            $newkelas->id_user = null;

            $columns = $DB->get_columns('kelas');
            if (isset($columns['created_at'])) {
                $newkelas->created_at = time();
            }
            if (isset($columns['timecreated'])) {
                $newkelas->timecreated = time();
            }
            if (isset($columns['timemodified'])) {
                $newkelas->timemodified = time();
            }

            $newid = (int)$DB->insert_record('kelas', $newkelas);
            $created = true;
        }

        $copied = self::copy_siswa_to_new_class((int)$kelas->id, $newid, (int)($kelas->id_user ?? 0));
        self::archive_old_class_if_supported($kelas);

        return [
            'status' => 'naik',
            'created' => $created,
            'oldclassid' => (int)$kelas->id,
            'newclassid' => $newid,
            'nexttingkat' => $nexttingkat,
            'copied' => $copied,
        ];
    }

    private static function get_next_tahun_ajaran(int $currentid): ?\stdClass {
        global $DB;
        if ($currentid <= 0) {
            return null;
        }

        $records = $DB->get_records_select(
            'tahun_ajaran',
            'id > :currentid',
            ['currentid' => $currentid],
            'id ASC',
            '*',
            0,
            1
        );

        return $records ? reset($records) : null;
    }

    private static function build_next_kelas_nama(string $nama, string $oldtingkat, string $nexttingkat): string {
        $nama = trim($nama);
        $oldtingkat = trim($oldtingkat);

        if ($nama === '') {
            return $nama;
        }

        if ($oldtingkat !== '' && preg_match('/\b' . preg_quote($oldtingkat, '/') . '\b/i', $nama)) {
            return preg_replace('/\b' . preg_quote($oldtingkat, '/') . '\b/i', $nexttingkat, $nama, 1);
        }

        return $nama;
    }

    private static function copy_siswa_to_new_class(int $oldkelasid, int $newkelasid, int $oldwaliid = 0): int {
        global $DB;

        $studentrole = $DB->get_record('role', ['shortname' => 'student'], 'id', IGNORE_MISSING);
        $studentroleid = $studentrole ? (int)$studentrole->id : 0;
        $pesertas = $DB->get_records('peserta_kelas', ['id_kelas' => $oldkelasid], 'id ASC');
        $copied = 0;

        foreach ($pesertas as $peserta) {
            $userid = (int)($peserta->id_user ?? 0);
            $roleid = (int)($peserta->id_role ?? 0);

            if ($userid <= 0) {
                continue;
            }
            if ($oldwaliid > 0 && $userid === $oldwaliid) {
                continue;
            }

            if ($studentroleid > 0) {
                if ($roleid > 0 && $roleid !== $studentroleid) {
                    continue;
                }
                if ($roleid <= 0) {
                    $roleid = $studentroleid;
                }
            }

            if ($roleid <= 0) {
                continue;
            }

            if ($DB->record_exists('peserta_kelas', ['id_kelas' => $newkelasid, 'id_user' => $userid])) {
                continue;
            }

            $record = new \stdClass();
            $record->id_kelas = $newkelasid;
            $record->id_user = $userid;
            $record->id_role = $roleid;
            $DB->insert_record('peserta_kelas', $record);
            $copied++;
        }

        return $copied;
    }

    private static function archive_old_class_if_supported(\stdClass $kelas): void {
        global $DB;
        $columns = $DB->get_columns('kelas');
        if (!isset($columns['status'])) {
            return;
        }
        $kelas->status = 'arsip';
        if (isset($columns['timemodified'])) {
            $kelas->timemodified = time();
        }
        $DB->update_record('kelas', $kelas);
    }

public static function luluskan(\stdClass $kelas): array {
    $oldids = get_config('local_akademikmonitor', 'kelas_lulus_ids');

    $ids = [];

    if (!empty($oldids)) {
        $decoded = json_decode((string)$oldids, true);

        if (is_array($decoded)) {
            $ids = array_map('intval', $decoded);
        }
    }

    $kelasid = (int)$kelas->id;

    if ($kelasid > 0 && !in_array($kelasid, $ids, true)) {
        $ids[] = $kelasid;
    }

    $ids = array_values(array_unique(array_filter($ids)));

    set_config('kelas_lulus_ids', json_encode($ids), 'local_akademikmonitor');

    return [
        'status' => 'lulus',
        'oldclassid' => $kelasid,
        'newclassid' => 0,
        'nexttingkat' => 'LULUS',
        'copied' => 0,
    ];
}
}
