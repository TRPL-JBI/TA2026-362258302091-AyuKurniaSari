<?php
namespace local_akademikmonitor\service;

defined('MOODLE_INTERNAL') || die();

class mitra_service {

    public static function list_mitra(string $view = 'active'): array {
        global $DB;

        $view = ($view === 'archived') ? 'archived' : 'active';

        $conditions = [];
        $conditions['is_active'] = ($view === 'active') ? 1 : 0;

        // Tidak pakai SELECT manual, cukup get_records().
        $records = $DB->get_records('mitra_dudi', $conditions, 'id ASC');

        $rows = [];
        foreach ($records as $r) {
            $isactive = !empty($r->is_active);

            $rows[] = [
                'id' => (int)$r->id,
                'nama' => (string)($r->nama ?? ''),
                'alamat' => (string)($r->alamat ?? ''),
                'kontak' => (string)($r->kontak ?? ''),
                'badge_text' => $isactive ? 'Aktif' : 'Diarsipkan',
                'badge_class' => $isactive ? 'am-badge-success' : 'am-badge-muted',
                'toggle_text' => $isactive ? 'Arsipkan' : 'Aktifkan',
                'is_active' => $isactive ? 1 : 0,
            ];
        }

        return $rows;
    }

    /**
     * Create mitra.
     * WAJIB return id karena ajax.php butuh $newid.
     */
    public static function create(string $nama, string $alamat = '', string $kontak = ''): int {
        global $DB;

        $nama = trim($nama);
        $alamat = trim($alamat);
        $kontak = trim($kontak);

        if ($nama === '') {
            throw new \exception('Nama mitra wajib diisi');
        }

        $now = time();
        return (int)$DB->insert_record('mitra_dudi', (object)[
            'nama' => $nama,
            'alamat' => $alamat,
            'kontak' => $kontak,
            'is_active' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    public static function update(int $id, string $nama, string $alamat = '', string $kontak = ''): void {
        global $DB;

        $nama = trim($nama);
        $alamat = trim($alamat);
        $kontak = trim($kontak);

        if ($nama === '') {
            throw new \exception('Nama mitra wajib diisi');
        }

        // Pastikan record ada (lebih jelas errornya).
        $DB->get_record('mitra_dudi', ['id' => $id], 'id', MUST_EXIST);

        $DB->update_record('mitra_dudi', (object)[
            'id' => $id,
            'nama' => $nama,
            'alamat' => $alamat,
            'kontak' => $kontak,
            'timemodified' => time(),
        ]);
    }

    /**
     * Toggle aktif/arsip.
     * Kenapa pakai get_field + set_field?
     * - Lebih sederhana (nggak perlu ambil record full)
     * - Lebih cepat (query lebih kecil)
     *
     * Return:
     * - status baru (1 aktif, 0 arsip) supaya ajax bisa update badge tanpa reload.
     */
    public static function toggle(int $id): int {
        global $DB;

        $current = (int)$DB->get_field('mitra_dudi', 'is_active', ['id' => $id], MUST_EXIST);
        $new = $current ? 0 : 1;

        $DB->set_field('mitra_dudi', 'is_active', $new, ['id' => $id]);
        $DB->set_field('mitra_dudi', 'timemodified', time(), ['id' => $id]);

        return $new;
    }

    /**
     * Import mitra dari data CSV (array rows).
     *
     * Tujuan:
     * - Tetap tanpa SELECT manual
     * - Tidak N+1 record_exists() per baris
     *
     * Strategi:
     * 1) Validasi + kumpulkan nama
     * 2) Prefetch existing pakai IN (...) sekali
     * 3) Insert yang belum ada
     */
    public static function import(array $data): array {
        global $DB;

        $success = 0;
        $failed = 0;
        $errors = [];

        // 1) Bersihkan input + validasi + cegah duplikat di file.
        $clean = [];
        $names = [];
        $seen = [];

        foreach ($data as $index => $row) {
            $line = $index + 2; // +2 karena baris 1 header CSV

            $nama = trim((string)($row['nama'] ?? ''));
            $alamat = trim((string)($row['alamat'] ?? ''));
            $kontak = trim((string)($row['kontak'] ?? ''));

            if ($nama === '') {
                $failed++;
                $errors[] = "Baris {$line}: Nama kosong";
                continue;
            }

            $key = mb_strtolower($nama);
            if (isset($seen[$key])) {
                $failed++;
                $errors[] = "Baris {$line}: Duplikat nama di file (\"{$nama}\")";
                continue;
            }
            $seen[$key] = true;

            $clean[] = [
                'line' => $line,
                'nama' => $nama,
                'alamat' => $alamat,
                'kontak' => $kontak,
            ];
            $names[] = $nama;
        }

        if (!$clean) {
            return ['success' => 0, 'failed' => $failed, 'errors' => $errors];
        }

        // 2) Prefetch mitra yang sudah ada (sekali query).
        list($insql, $params) = $DB->get_in_or_equal($names, SQL_PARAMS_NAMED);

        // get_records_select = tanpa SELECT manual; kita cuma tulis kondisi.
        $existing = $DB->get_records_select('mitra_dudi', "nama $insql", $params, '', 'id,nama');

        $existingnames = [];
        foreach ($existing as $ex) {
            $existingnames[mb_strtolower((string)$ex->nama)] = true;
        }

        // 3) Insert mitra baru.
        foreach ($clean as $r) {
            if (isset($existingnames[mb_strtolower($r['nama'])])) {
                $failed++;
                $errors[] = "Baris {$r['line']}: Mitra sudah ada (\"{$r['nama']}\")";
                continue;
            }

            try {
                $now = time();
                $DB->insert_record('mitra_dudi', (object)[
                    'nama' => $r['nama'],
                    'alamat' => $r['alamat'],
                    'kontak' => $r['kontak'],
                    'is_active' => 1,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
                $success++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = "Baris {$r['line']}: " . $e->getMessage();
                throw $e;
            }
        }

        return [
            'success' => $success,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }
}