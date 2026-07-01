<?php
namespace local_akademikmonitor\service\walikelas;

defined('MOODLE_INTERNAL') || die();

use context_system;
use moodle_exception;
use required_capability_exception;

class access_service {

    /**
     * Memastikan user memiliki hak mengakses rapor siswa.
     *
     * @param int $userid
     * @param int $groupid
     * @param int $tahunajaranid
     * @param bool $write true = simpan, false = lihat/export
     */
    public static function require_rapor_access(
        int $userid,
        int $groupid,
        // int $kelasid,
        int $tahunajaranid,
        bool $write = false
    ): void {

        global $DB, $USER;
        // $kelasid = common_service::get_generated_kelasid_from_group($groupid);
        $context = context_system::instance();

        /*
         * Pastikan siswa memang anggota group tersebut.
         */
        $studentingroup = $DB->record_exists(
            'groups_members',
            [
                'groupid' => $groupid,
                'userid' => $userid,
            ]
        );

        if (!$studentingroup) {
            debugging(
                'userid=' . $userid .
                ', groupid=' . $groupid,
                DEBUG_DEVELOPER
            );
            throw new moodle_exception(
                'studentnotingroup',
                'local_akademikmonitor'
            );
        }

        /*
         * Ambil seluruh group yang menjadi tanggung jawab wali kelas
         * pada tahun ajaran yang dipilih.
         */
        $groups = common_service::get_group_walikelas_by_tahunajaran(
            (int)$USER->id,
            $tahunajaranid
        );

        // $iswali = isset($groups[$kelasid]);
        $iswali = isset($groups[$groupid]);
        if ($iswali) {
            $kelasid = (int)$groups[$groupid]->kelasid;
        }
        
        // $iswali = array_key_exists($groupid, $groups);

        /*
         * Hak melihat rapor.
         */
        $canview = $iswali ||
            has_capability(
                'local/akademikmonitor:viewrapor',
                $context
            );

        /*
         * Hak mengubah rapor.
         */
        $canwrite = $iswali ||
            has_capability(
                'local/akademikmonitor:manage',
                $context
            );

        if ($write) {

            if (!$canwrite) {
                throw new required_capability_exception(
                    $context,
                    'local/akademikmonitor:manage',
                    'nopermissions',
                    ''
                );
            }

        } else {

            if (!$canview) {
                throw new required_capability_exception(
                    $context,
                    'local/akademikmonitor:viewrapor',
                    'nopermissions',
                    ''
                );
            }

        }
    }

}