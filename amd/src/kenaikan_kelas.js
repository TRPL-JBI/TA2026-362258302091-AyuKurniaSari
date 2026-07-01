define(['jquery'], function($) {
    return {
        init: function(userid, groupid, kelasid) {
            $(document).on('click', '#btn-edit-keputusan', function(e) {
                e.preventDefault();
                const current = $('#keputusan-text').text().trim();
                $('#input-keputusan').val(current);
                $('#modal-keputusan').css('display', 'flex');
            });

            $(document).on('click', '#close-modal-keputusan', function(e) {
                e.preventDefault();
                $('#modal-keputusan').hide();
            });

            $(document).on('click', '#modal-keputusan', function(e) {
                if (e.target.id === 'modal-keputusan') {
                    $('#modal-keputusan').hide();
                }
            });

            $(document).on('click', '#save-keputusan', function(e) {
                e.preventDefault();

                const text = $('#input-keputusan').val();

                $.ajax({
                    url: M.cfg.wwwroot + '/local/akademikmonitor/pages/walikelas/rapor/ajax.php',
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        sesskey: M.cfg.sesskey,
                        action: 'save_kenaikan',
                        userid: userid,
                        groupid: groupid,
                        kelasid: kelasid,
                        keputusan: text
                    },
                    success: function(res) {
                        if (res && res.ok) {
                            $('#keputusan-text').text(text);
                            $('#modal-keputusan').hide();
                            alert(res.message || 'Keputusan berhasil disimpan');
                        } else {
                            alert((res && res.message) ? res.message : 'Gagal menyimpan keputusan');
                        }
                    },
                    error: function(xhr) {
                        alert('Request gagal: ' + (xhr.responseText || 'unknown error'));
                    }
                });
            });
        }
    };
});