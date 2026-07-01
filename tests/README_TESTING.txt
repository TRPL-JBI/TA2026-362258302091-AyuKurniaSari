UNIT TESTING MENU - local_akademikmonitor
=========================================

Folder ini berisi file PHPUnit per menu untuk plugin local_akademikmonitor.
Copy semua file .php ke:

    local/akademikmonitor/tests/

Jalankan dari root Moodle:

    vendor/bin/phpunit local/akademikmonitor/tests/admin_kktp_menu_test.php

Atau semua test plugin:

    vendor/bin/phpunit local/akademikmonitor/tests

Kalau PHPUnit belum siap, jalankan dulu:

    php admin/tool/phpunit/cli/init.php

Catatan:
- Test ini fokus ke service/data logic dari tiap menu.
- Komentar di setiap function menjelaskan fitur yang diuji: CREATE, READ, UPDATE, TOGGLE, VALIDATE, FILTER, FALLBACK.
- Jangan langsung hapus test lama. Jalankan dulu satu per satu supaya mudah tahu file mana yang gagal.
