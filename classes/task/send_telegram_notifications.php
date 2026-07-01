<?php
namespace local_akademikmonitor\task;

defined('MOODLE_INTERNAL') || die();

use local_akademikmonitor\service\notif_dispatcher_service;

class send_telegram_notifications extends \core\task\scheduled_task {

    public function get_name() {
        return 'Kirim notifikasi Telegram otomatis';
    }

    public function execute() {
        notif_dispatcher_service::run();
    }
}