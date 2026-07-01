<?php
namespace local_akademikmonitor\service;

defined('MOODLE_INTERNAL') || die();

use context_system;

class access_service {

    /**
     * Memastikan user memiliki hak mengelola plugin.
     */
    public static function require_manage(): void {

        require_login();

        require_capability(
            'local/akademikmonitor:manage',
            context_system::instance()
        );

    }

}