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
 *
 */
            function openModal() {
                if (modal) {
                    modal.style.display = 'flex';
                }
            }
/**
 *
 */
            function closeModal() {
                if (modal) {
                    modal.style.display = 'none';
                }
            }
/**
 *
 */
            function openImportModal() {
                if (importModal) {
                    importModal.style.display = 'flex';
                }
            }
/**
 *
 */
            function closeImportModal() {
                if (importModal) {
                    importModal.style.display = 'none';
                }
            }
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

                const res = await fetch(ajaxurl, {
                    method: 'POST',
                    body: fd
                });

                const text = await res.text();

                try {
                    return JSON.parse(text);
                } catch (err) {
                    return {ok: false, message: 'Response backend bukan JSON yang valid'};
                }
            }

            document.body.addEventListener('click', async function(e) {
                const btn = e.target.closest('[data-action]');
                if (!btn) {
                    return;
                }

                const title = document.getElementById('m-title');
                const mPklid = document.getElementById('m-pklid');
                const mUserid = document.getElementById('m-userid');
                const mKelasid = document.getElementById('m-kelasid');
                const mMitraid = document.getElementById('m-mitraid');
                const mSemester = document.getElementById('m-semester');
                const mWaktuMulai = document.getElementById('m-waktu_mulai');
                const mWaktuSelesai = document.getElementById('m-waktu_selesai');
                const mNilai = document.getElementById('m-nilai');
                const nisnInput = document.getElementById('m-nisn');
                const action = btn.getAttribute('data-action');

                if (action === 'open-add') {
                    if (title) { title.textContent = 'Tambah PKL Siswa'; }
                    if (mPklid) { mPklid.value = ''; }
                    if (mUserid) { mUserid.value = ''; mUserid.disabled = false; }
                    if (mKelasid) { mKelasid.value = btn.dataset.kelasid || ''; }
                    if (mMitraid) { mMitraid.value = ''; }
                    if (mSemester) { mSemester.value = String(getActiveSemester()); }
                    if (mWaktuMulai) { mWaktuMulai.value = ''; }
                    if (mWaktuSelesai) { mWaktuSelesai.value = ''; }
                    if (mNilai) { mNilai.value = ''; }
                    if (nisnInput) { nisnInput.value = ''; }
                    openModal();
                    return;
                }

                if (action === 'edit') {
                    if (title) { title.textContent = 'Edit PKL Siswa'; }
                    if (mPklid) { mPklid.value = btn.dataset.pklid || ''; }
                    if (mUserid) { mUserid.value = btn.dataset.userid || ''; mUserid.disabled = true; }
                    if (mKelasid) { mKelasid.value = btn.dataset.kelasid || ''; }
                    if (mMitraid) { mMitraid.value = btn.dataset.mitraid || ''; }
                    if (mSemester) { mSemester.value = btn.dataset.semester || String(getActiveSemester()); }
                    if (mWaktuMulai) { mWaktuMulai.value = btn.dataset.waktu_mulai || ''; }
                    if (mWaktuSelesai) { mWaktuSelesai.value = btn.dataset.waktu_selesai || ''; }
                    if (mNilai) { mNilai.value = btn.dataset.nilai || ''; }
                    if (nisnInput) { nisnInput.value = btn.dataset.nisn || ''; }
                    openModal();
                    return;
                }

                if (action === 'close-modal') { closeModal(); return; }
                if (action === 'open-import') { openImportModal(); return; }
                if (action === 'close-import') { closeImportModal(); return; }

                if (action === 'save_pkl') {
                    const pklid = mPklid?.value || '';
                    const userid = mUserid?.value || '';
                    const kelasid = mKelasid?.value || '';
                    const mitraid = mMitraid?.value || '';
                    const semester = mSemester?.value || String(getActiveSemester());
                    const waktu_mulai = mWaktuMulai?.value || '';
                    const waktu_selesai = mWaktuSelesai?.value || '';
                    const nilai = mNilai?.value || '';

                    if (!userid || !kelasid || !mitraid || !semester || !waktu_mulai || !waktu_selesai || !nilai) {
                        alert('Semua field wajib diisi');
                        return;
                    }

                    btn.disabled = true;

                    try {
                        const json = await post('save_pkl', {
                            pklid,
                            userid,
                            kelasid,
                            mitraid,
                            semester,
                            waktu_mulai,
                            waktu_selesai,
                            nilai
                        });

                        if (json.ok) {
                            location.reload();
                        } else {
                            alert(json.message || 'Gagal simpan');
                        }
                    } catch (err) {
                        alert('Terjadi kesalahan saat mengirim data');
                    } finally {
                        btn.disabled = false;
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