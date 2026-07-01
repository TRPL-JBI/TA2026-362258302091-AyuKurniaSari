<?php

namespace local_akademikmonitor\navigation\views;

defined('MOODLE_INTERNAL') || die();

use navigation_node;
use moodle_url;
use pix_icon;

class primary {

    public static function extend($navigation) {
        global $USER;

        if (!\isloggedin() || \isguestuser()) {
            return;
        }

        // Menu khusus wali kelas.
        if (\local_akademikmonitor_is_wali_kelas_user((int)$USER->id)) {

            $navigation->add(
                'Monitoring Siswa',
                new moodle_url(
                    '/local/akademikmonitor/pages/walikelas/dashboard.php'
                ),
                navigation_node::TYPE_CUSTOM,
                null,
                'monitoringsiswa',
                new pix_icon('i/report', '')
            );
        }

            // ── Tab Capaian & Tujuan Pembelajaran (guru) ──────────────────────────
             if (
                self::is_guru_user((int)$USER->id)
            ) {
            $navigation->add(
                'Capaian & Tujuan Pembelajaran',
                new moodle_url(
                    '/local/akademikmonitor/pages/guru/tp_pilih_course.php'
                ),
                navigation_node::TYPE_CUSTOM,
                null,
                'guru_cp_tp_akademikmonitor',
                new pix_icon('i/edit', '')
            );
        }
    }

    /**
     * Cek apakah user adalah guru (editingteacher / teacher)
     * yang mengajar minimal satu course.
     *
     * Pakai archetype role agar portable antar instalasi Moodle
     * (ID role bisa berbeda-beda tiap instalasi).
     */
    private static function is_guru_user(int $userid): bool {
        global $DB;

        if ($userid <= 0) {
            return false;
        }

        $roles = $DB->get_records_list(
            'role',
            'archetype',
            ['editingteacher'],
            '',
            'id'
        );

        if (empty($roles)) {
            return false;
        }

        $roleids = array_keys($roles);

        [$insql, $inparams] = $DB->get_in_or_equal(
            $roleids,
            SQL_PARAMS_NAMED,
            'roleid'
        );

        $inparams['userid']        = $userid;
        $inparams['contextlevel']  = CONTEXT_COURSE;

        $sql = "SELECT ra.id
                  FROM {role_assignments} ra
                  JOIN {context} ctx ON ctx.id = ra.contextid
                 WHERE ra.userid        = :userid
                   AND ra.roleid        {$insql}
                   AND ctx.contextlevel = :contextlevel";

        return $DB->record_exists_sql($sql, $inparams);
    }    
}