<?php
namespace local_akademikmonitor\service;

defined('MOODLE_INTERNAL') || die();

class course_period_service {

    public static function resolve_course_semester(\stdClass $course, int $selectedsemester = 0): int {
        $fullname = \core_text::strtolower(trim((string)($course->fullname ?? '')));
        $shortname = \core_text::strtolower(trim((string)($course->shortname ?? '')));
        $text = $fullname . ' ' . $shortname;

        if (preg_match('/\b(ganjil|semester\s*1|semester\s*i|smt\s*1|sem\s*1)\b/u', $text)) {
            return 1;
        }

        if (preg_match('/\b(genap|semester\s*2|semester\s*ii|smt\s*2|sem\s*2)\b/u', $text)) {
            return 2;
        }

        if (in_array($selectedsemester, [1, 2], true)) {
            return $selectedsemester;
        }

        return 0;
    }

    public static function is_course_match_semester(\stdClass $course, int $selectedsemester): bool {
        if (!in_array($selectedsemester, [1, 2], true)) {
            return true;
        }

        $coursesemester = self::resolve_course_semester($course, $selectedsemester);

        if ($coursesemester === 0) {
            return true;
        }

        return $coursesemester === $selectedsemester;
    }

    public static function filter_courses_by_semester(array $courses, int $selectedsemester): array {
        if (!in_array($selectedsemester, [1, 2], true)) {
            return $courses;
        }

        $filtered = [];
        $hasexplicitmatch = false;

        foreach ($courses as $key => $course) {
            $resolved = self::resolve_course_semester($course, 0);
            if ($resolved > 0) {
                $hasexplicitmatch = true;
            }

            if (self::is_course_match_semester($course, $selectedsemester)) {
                $filtered[$key] = $course;
            }
        }

        // Kalau nama course belum punya penanda semester,
        // jangan sampai semua course hilang total.
        if (!$hasexplicitmatch) {
            return $courses;
        }

        return $filtered;
    }
}