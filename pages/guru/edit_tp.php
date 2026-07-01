<?php
require_once(__DIR__ . '/../../../../config.php');

require_login();

global $DB, $PAGE, $OUTPUT, $USER;

$id       = required_param('id', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);
$cpid     = required_param('cpid', PARAM_INT);

$coursecontext = context_course::instance($courseid, MUST_EXIST);
require_capability('moodle/course:manageactivities', $coursecontext);

$tp = $DB->get_record('tujuan_pembelajaran', [
    'id' => $id,
    'id_course' => $courseid,
    'id_capaian_pembelajaran' => $cpid
], '*', MUST_EXIST);

$course = get_course($courseid);

$PAGE->set_url(new moodle_url('/local/akademikmonitor/pages/guru/edit_tp.php', [
    'id' => $id,
    'courseid' => $courseid,
    'cpid' => $cpid
]));
$PAGE->set_context($coursecontext);
$PAGE->set_title('Edit Tujuan Pembelajaran');
$PAGE->set_heading('Edit Tujuan Pembelajaran');
$PAGE->set_pagelayout('incourse');
$PAGE->requires->css(new moodle_url('/local/akademikmonitor/css/styles.css'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $tp->konten     = optional_param('konten', '', PARAM_TEXT);
    $tp->kompetensi = optional_param('kompetensi', '', PARAM_TEXT);
    $tp->dpl        = optional_param('dpl', '', PARAM_TEXT);
    $tp->atp        = optional_param('atp', '', PARAM_TEXT);
    $tp->deskripsi  = required_param('deskripsi', PARAM_RAW_TRIMMED);

    $DB->update_record('tujuan_pembelajaran', $tp);

    redirect(
        new moodle_url('/local/akademikmonitor/pages/guru/tp.php', [
            'courseid' => $courseid,
            'cpid' => $cpid
        ]),
        'Tujuan Pembelajaran berhasil diperbarui.',
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
?>

<div class="akademikmonitor">
  <div style="width:100%;max-width:none;margin:0;padding:24px 32px;display:block;">

    <div class="am-page-heading" style="margin-bottom:24px;">
      <h1 class="am-h1">Edit Tujuan Pembelajaran</h1>
      <div class="am-sub">
        Perbaiki data TP jika terdapat kesalahan penulisan.
      </div>
    </div>

    <div class="am-card">
      <form method="post">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:12px;">
          <input type="text" name="konten" class="am-input" placeholder="Konten"
                 value="<?php echo s($tp->konten ?? ''); ?>">

          <input type="text" name="kompetensi" class="am-input" placeholder="Kompetensi"
                 value="<?php echo s($tp->kompetensi ?? ''); ?>">

          <input type="text" name="dpl" class="am-input" placeholder="DPL"
                 value="<?php echo s($tp->dpl ?? ''); ?>">

          <input type="text" name="atp" class="am-input" placeholder="ATP"
                 value="<?php echo s($tp->atp ?? ''); ?>">
        </div>

        <textarea name="deskripsi"
                  class="am-input"
                  style="width:100%;min-height:140px;"
                  required><?php echo s($tp->deskripsi ?? ''); ?></textarea>

        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:18px;">
          <a href="<?php echo (new moodle_url('/local/akademikmonitor/pages/guru/tp.php', [
              'courseid' => $courseid,
              'cpid' => $cpid
          ]))->out(false); ?>" class="am-btn am-btn-secondary">
            Batal
          </a>

          <button type="submit" class="am-btn am-btn-primary">
            Simpan Perubahan
          </button>
        </div>
      </form>
    </div>

  </div>
</div>

<?php
echo $OUTPUT->footer();