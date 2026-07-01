define(['jquery', 'core/notification'], function($, Notification) {
    /**
     * Membuka modal berdasarkan selector.
     *
     * @param {string} selector Selector modal.
     */
    function openModal(selector) {
        $(selector).css('display', 'flex');
    }

    /**
     * Menutup modal berdasarkan selector.
     *
     * @param {string} selector Selector modal.
     */
    function closeModal(selector) {
        $(selector).hide();
    }

    /**
     * Menyimpan data teks rapor melalui AJAX.
     *
     * Function ini dibuat supaya proses simpan Catatan Akademik dan Kokurikuler
     * tidak ditulis berulang-ulang. Perbedaannya hanya pada action, nama field,
     * isi teks, dan aksi setelah data berhasil disimpan.
     *
     * @param {number} userid ID siswa.
     * @param {number} groupid ID kelas.
     * @param {number} kelasid ID kelas.
     * @param {number} semester Semester aktif.
     * @param {string} action Nama action untuk ajax.php.
     * @param {string} fieldname Nama field POST.
     * @param {string} text Isi teks yang disimpan.
     * @param {Function} successCallback Callback setelah berhasil simpan.
     */
    function saveText(userid, groupid, kelasid, semester, action, fieldname, text, successCallback) {
        var data = {
            sesskey: M.cfg.sesskey,
            action: action,
            userid: userid,
            groupid: groupid,
            kelasid: kelasid,
            semester: semester
        };

        data[fieldname] = text;

        $.ajax({
            url: M.cfg.wwwroot + '/local/akademikmonitor/pages/walikelas/rapor/ajax.php',
            method: 'POST',
            dataType: 'json',
            data: data
        }).done(function(res) {
            if (res && res.ok) {
                successCallback();

                Notification.addNotification({
                    message: res.message || 'Data berhasil disimpan',
                    type: 'success'
                });
            } else {
                Notification.addNotification({
                    message: res && res.message ? res.message : 'Gagal menyimpan data',
                    type: 'error'
                });
            }
        }).fail(function(xhr) {
            Notification.addNotification({
                message: 'Request gagal: ' + (xhr.responseText || 'unknown error'),
                type: 'error'
            });
        });
    }

    return {
        /**
         * Inisialisasi event untuk tab Catatan Akademik dan Kokurikuler.
         *
         * @param {number} userid ID siswa.
         * @param {number} groupid ID siswa.
         * @param {number} kelasid ID kelas.
         * @param {number} semester Semester aktif.
         */
        init: function(userid, groupid, kelasid, semester) {
            $(document).on('click', '#btn-edit-catatan', function(e) {
                e.preventDefault();

                var current = $('#catatan-text').text().trim();
                if (current === 'Belum ada catatan') {
                    current = '';
                }

                $('#input-catatan').val(current);
                openModal('#modal-catatan');
            });

            $(document).on('click', '#close-modal, #cancel-catatan', function(e) {
                e.preventDefault();
                closeModal('#modal-catatan');
            });

            $(document).on('click', '#modal-catatan', function(e) {
                if (e.target.id === 'modal-catatan') {
                    closeModal('#modal-catatan');
                }
            });

            $(document).on('click', '#save-catatan', function(e) {
                e.preventDefault();

                var text = $('#input-catatan').val();

                saveText(userid, groupid, kelasid, semester, 'save_catatan', 'catatan', text, function() {
                    $('#catatan-text').text(text || 'Belum ada catatan');
                    closeModal('#modal-catatan');
                });
            });

            $(document).on('click', '#btn-edit-kokurikuler', function(e) {
                e.preventDefault();

                var current = $('#kokurikuler-text').text().trim();
                if (current === 'Belum ada catatan kokurikuler') {
                    current = '';
                }

                $('#input-kokurikuler').val(current);
                openModal('#modal-kokurikuler');
            });

            $(document).on('click', '#close-modal-kokurikuler, #cancel-kokurikuler', function(e) {
                e.preventDefault();
                closeModal('#modal-kokurikuler');
            });

            $(document).on('click', '#modal-kokurikuler', function(e) {
                if (e.target.id === 'modal-kokurikuler') {
                    closeModal('#modal-kokurikuler');
                }
            });

            $(document).on('click', '#save-kokurikuler', function(e) {
                e.preventDefault();

                var text = $('#input-kokurikuler').val();

                saveText(userid, groupid, kelasid, semester, 'save_kokurikuler', 'kokurikuler', text, function() {
                    $('#kokurikuler-text').text(text || 'Belum ada catatan kokurikuler');
                    closeModal('#modal-kokurikuler');
                });
            });
        }
    };
});