<?php

namespace local_akademikmonitor\service;

defined('MOODLE_INTERNAL') || die();

class tp_access_service {

    /**
     * Cek apakah user boleh mengelola TP pada course tertentu.
     */
    public static function can_manage_tp(int $userid, int $courseid): bool {
        global $DB;

        // Admin selalu boleh.
        if (is_siteadmin($userid)) {
            return true;
        }

        $sql = "
            SELECT 1
              FROM {role_assignments} ra
              JOIN {context} ctx
                ON ctx.id = ra.contextid
              JOIN {role} r
                ON r.id = ra.roleid
             WHERE ra.userid = :userid
               AND ctx.instanceid = :courseid
               AND ctx.contextlevel = :contextlevel
               AND r.shortname = :rolename
        ";

        return $DB->record_exists_sql($sql, [
            'userid' => $userid,
            'courseid' => $courseid,
            'contextlevel' => CONTEXT_COURSE,
            'rolename' => 'editingteacher'
        ]);
    }
}