define(['jquery'], function($) {
    return {
        init: function(userid, groupid, kelasid, semester) {
            $(document).on('click', '#save-ketidakhadiran', function(e) {
                e.preventDefault();

                const sakit = $('#hadir-sakit').val();
                const izin = $('#hadir-izin').val();
                const alfa = $('#hadir-alfa').val();

                $.ajax({
                    url: M.cfg.wwwroot + '/local/akademikmonitor/pages/walikelas/rapor/ajax.php',
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        sesskey: M.cfg.sesskey,
                        action: 'save_ketidakhadiran',
                        userid: userid,
                        groupid: groupid,
                        kelasid: kelasid,
                        semester: semester,
                        sakit: sakit,
                        izin: izin,
                        alfa: alfa
                    },
                    success: function(res) {
                        if (res && res.ok) {
                            alert(res.message || 'Data ketidakhadiran tersimpan');
                        } else {
                            alert((res && res.message) ? res.message : 'Gagal menyimpan ketidakhadiran');
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