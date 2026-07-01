define([], function() {
    return {
        init: function(selectedSemester, tahunajaranid) {
            document.addEventListener('DOMContentLoaded', function() {
                const select = document.getElementById('filter-mapel');
                if (!select) {
                    return;
                }

                select.addEventListener('change', function() {
                    const courseid = this.value || '';
                    const params = new URLSearchParams(window.location.search);

                    if (courseid) {
                        params.set('courseid', courseid);
                    } else {
                        params.delete('courseid');
                    }

                    params.set('semester', String(selectedSemester || 1));

                    if (tahunajaranid) {
                        params.set('tahunajaranid', String(tahunajaranid));
                    }

                    window.location.href = window.location.pathname + '?' + params.toString();
                });
            });
        }
    };
});