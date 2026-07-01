<?php

require('../../../../config.php');

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new moodle_exception('invalidrequest');
}

require_sesskey();

$id = required_param('id', PARAM_INT);

$context = context_system::instance();

require_capability('local/akademikmonitor:manage', $context);

global $DB;

$link = $DB->get_record(
    'telegram_user_link',
    ['id' => $id],
    '*',
    MUST_EXIST
);

$link->is_linked = '0';

$DB->update_record('telegram_user_link', $link);

redirect(
    new moodle_url('/local/akademikmonitor/pages/notif/index.php'),
    'Sambungan Telegram berhasil diputuskan.'
);