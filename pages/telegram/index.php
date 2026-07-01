<?php
require_once(__DIR__ . '/../../../../config.php');

use local_akademikmonitor\service\notif_service;

require_login();

// global $USER;
global $USER, $DB;
$currentuser = $DB->get_record('user', ['id' => $USER->id], '*', MUST_EXIST);
$context = context_user::instance($USER->id);


$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/telegram/index.php'));
$PAGE->set_context($context);
$PAGE->set_title('Hubungkan Telegram');
$PAGE->set_heading('Hubungkan Telegram');
$PAGE->set_pagelayout('standard');
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));


$setting = notif_service::get_setting();
$link = notif_service::get_user_link((int)$USER->id);
$connecturl = notif_service::build_telegram_connect_url((int)$USER->id);

$isconnected = false;
$telegramusername = '-';
$telegramchatid = '-';
$linkedat = '-';

if ($link && !empty($link->telegram_chat_id) && (string)$link->is_linked === '1') {
    $isconnected = true;
    $telegramusername = !empty($link->telegram_username) ? '@' . $link->telegram_username : '-';
    $telegramchatid = $link->telegram_chat_id;
    $linkedat = !empty($link->linked_at) ? $link->linked_at : '-';
}

$data = [
    'userid' => (int)$USER->id,
    // 'fullname' => fullname($USER),
    'fullname' => fullname($currentuser),
    'bot_username' => $setting->bot_username ?? '',
    'connect_url' => $connecturl,
    'hasbot' => !empty($setting->bot_username),
    'isconnected' => $isconnected,
    'isnotconnected' => !$isconnected,
    'telegram_username' => $telegramusername,
    'telegram_chat_id' => $telegramchatid,
    'linked_at' => $linkedat,
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_akademikmonitor/telegram', $data);
echo $OUTPUT->footer();