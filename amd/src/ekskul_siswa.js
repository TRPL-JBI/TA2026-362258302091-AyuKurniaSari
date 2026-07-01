/* eslint-disable complexity */
define([], function() {
    return {
        init: function(selectedSemester) {
            const ajaxurl = document.getElementById('am-ajaxurl')?.value || '';
            const sesskey = document.getElementById('am-sesskey')?.value || '';
            const modal = document.getElementById('am-modal');
            const importModal = document.getElementById('am-import-modal');
/**
 *
 */
            function getActiveSemester() {
                const semester = parseInt(selectedSemester, 10);
                return [1, 2].includes(semester) ? semester : 1;
            }
/**
 * Open modal
 */
            function openModal() { if (modal) { modal.style.display = 'flex'; } }
/**
 * Open modal
 */
            function closeModal() { if (modal) { modal.style.display = 'none'; } }
/**
 * Open modal
 */
            function openImportModal() { if (importModal) { importModal.style.display = 'flex'; } }
/**
 * Open modal
 */
            function closeImportModal() { if (importModal) { importModal.style.display = 'none'; } }
/**
 * Send AJAX request
 * @param {string} action
 * @param {Object} payload
 */
            async function post(action, payload) {
                const fd = new FormData();
                fd.append('sesskey', sesskey);
                fd.append('action', action);

                Object.keys(payload || {}).forEach(function(k) {
                    fd.append(k, payload[k]);
                });

                const res = await fetch(ajaxurl, { method: 'POST', body: fd });
                const text = await res.text();

                try {
                    return JSON.parse(text);
                } catch (err) {
                    return { ok: false, message: 'Response backend bukan JSON yang valid' };
                }
            }

            document.body.addEventListener('click', async function(e) {
                const btn = e.target.closest('[data-action]');
                if (!btn) { return; }

                const title = document.getElementById('m-title');
                const mUserid = document.getElementById('m-userid');
                const mKelasid = document.getElementById('m-kelasid');
                const mEkskulid = document.getElementById('m-ekskulid');
                const mPredikat = document.getElementById('m-predikat');
                const mSemester = document.getElementById('m-semester');
                const nisnInput = document.getElementById('m-nisn');
                const action = btn.getAttribute('data-action');

                if (action === 'open-add') {
                    if (title) { title.textContent = 'Tambah Peserta Ekskul'; }
                    if (mUserid) { mUserid.value = ''; mUserid.disabled = false; }
                    if (mKelasid) { mKelasid.value = btn.dataset.kelasid || ''; }
                    if (mEkskulid) { mEkskulid.value = ''; mEkskulid.disabled = false; }
                    if (mPredikat) { mPredikat.value = ''; }
                    if (mSemester) { mSemester.value = String(getActiveSemester()); }
                    if (nisnInput) { nisnInput.value = ''; }
                    openModal();
                    return;
                }

                if (action === 'edit') {
                    if (title) { title.textContent = 'Edit Ekskul Siswa'; }
                    if (mUserid) { mUserid.value = btn.dataset.userid || ''; mUserid.disabled = true; }
                    if (mKelasid) { mKelasid.value = btn.dataset.kelasid || ''; }
                    if (mEkskulid) { mEkskulid.value = btn.dataset.ekskulid || ''; mEkskulid.disabled = true; }
                    if (mPredikat) { mPredikat.value = btn.dataset.predikat || ''; }
                    if (mSemester) { mSemester.value = btn.dataset.semester || String(getActiveSemester()); }
                    if (nisnInput) { nisnInput.value = btn.dataset.nisn || ''; }
                    openModal();
                    return;
                }

                if (action === 'close-modal') { closeModal(); return; }
                if (action === 'open-import') { openImportModal(); return; }
                if (action === 'close-import') { closeImportModal(); return; }
if (action === 'delete') {

    // console.log(btn.dataset);

    if (!confirm('Yakin ingin menghapus data ekstrakurikuler ini?')) {
        return;
    }

    const userid = btn.dataset.userid || '';
    const kelasid = btn.dataset.kelasid || '';
    const ekskulid = btn.dataset.ekskulid || '';
    const semester = btn.dataset.semester || '';

    const json = await post('delete', {
        userid,
        kelasid,
        ekskulid,
        semester
    });

    // console.log(json);

    if (json.ok) {
        location.reload();
    } else {
        alert(json.message || 'Gagal menghapus data');
    }

    return;
}
                if (action === 'save') {
                    const userid = mUserid?.value || '';
                    const kelasid = mKelasid?.value || '';
                    const ekskulid = mEkskulid?.value || '';
                    const predikat = mPredikat?.value || '';
                    const semester = mSemester?.value || String(getActiveSemester());

                    if (!userid || !kelasid || !ekskulid || !predikat) {
                        alert('Semua field wajib diisi');
                        return;
                    }

                    const json = await post('save', {
                        userid,
                        kelasid,
                        ekskulid,
                        predikat,
                        semester
                    });

                    if (json.ok) {
                        location.reload();
                    } else {
                        alert(json.message || 'Gagal simpan');
                    }

                    return;
                }
            });

            const siswaSelect = document.getElementById('m-userid');
            const nisnInput = document.getElementById('m-nisn');

            if (siswaSelect) {
                siswaSelect.addEventListener('change', function() {
                    const selected = siswaSelect.options[siswaSelect.selectedIndex];
                    if (selected && nisnInput) {
                        nisnInput.value = selected.dataset.nisn || '';
                    }
                });
            }

            modal?.addEventListener('click', function(e) {
                if (e.target === modal) {
                    closeModal();
                }
            });

            importModal?.addEventListener('click', function(e) {
                if (e.target === importModal) {
                    closeImportModal();
                }
            });
        }
    };
});