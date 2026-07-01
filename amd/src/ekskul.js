/* eslint-disable complexity */
/* eslint-disable require-jsdoc */

define(['core/notification'], function(Notification) {

    return {
        init: function() {

            const ajaxurl = document.getElementById('am-ajaxurl')?.value || '';
            const sesskey = document.getElementById('am-sesskey')?.value || '';

            const modal = document.getElementById('am-modal');
            const title = document.getElementById('m-title');
            const mId = document.getElementById('m-id');
            const mNama = document.getElementById('m-nama');
            const mPembinaid = document.getElementById('m-pembinaid');

            /**
             * Open modal
            */
            function openModal() {
                if (modal) {
                    modal.style.display = 'flex';
                }
            }

            /**
             * Close modal
            */
            function closeModal() {
                if (modal) {
                    modal.style.display = 'none';
                }
            }
            /**
             * Open Reser form
            */
            function resetForm() {
                if (mId) {
                    mId.value = '';
                }

                if (mNama) {
                    mNama.value = '';
                }

                if (mPembinaid) {
                    mPembinaid.value = '0';
                }
            }
/**
 * Send AJAX request
 * @param {string} action
 * @param {Object} payload
 */
            async function post(action, payload = {}) {
                const fd = new FormData();
                fd.append('sesskey', sesskey);
                fd.append('action', action);

                Object.entries(payload).forEach(([key, value]) => {
                    fd.append(key, value);
                });

                const res = await fetch(ajaxurl, {
                    method: 'POST',
                    body: fd
                });

                return res.json();
            }

            document.body.addEventListener('click', async function(e) {
                const btn = e.target.closest('[data-action]');

                if (!btn) {
                    return;
                }

                const action = btn.dataset.action;

                if (action === 'open-add') {
                    resetForm();

                    if (title) {
                        title.textContent = 'Tambah Ekstrakurikuler';
                    }

                    openModal();
                    return;
                }

                if (action === 'edit') {
                    if (title) {
                        title.textContent = 'Edit Ekstrakurikuler';
                    }

                    if (mId) {
                        mId.value = btn.dataset.id || '';
                    }

                    if (mNama) {
                        mNama.value = btn.dataset.nama || '';
                    }

                    if (mPembinaid) {
                        mPembinaid.value = btn.dataset.pembina || '0';
                    }

                    openModal();
                    return;
                }

                if (action === 'close-modal') {
                    closeModal();
                    return;
                }

                if (action === 'save') {
                    const id = mId ? mId.value.trim() : '';
                    const nama = mNama ? mNama.value.trim() : '';
                    const pembinaid = parseInt(mPembinaid ? mPembinaid.value : '0', 10) || 0;

                    if (!nama) {
                        Notification.alert('Error', 'Nama ekskul wajib diisi', 'OK');
                        return;
                    }

                    if (!pembinaid) {
                        Notification.alert('Error', 'Pembina wajib dipilih', 'OK');
                        return;
                    }

                    try {
                        const json = id
                            ? await post('update', {id, nama, pembinaid})
                            : await post('create', {nama, pembinaid});

                        if (json.ok) {
                            resetForm();
                            closeModal();

                            /*
                             * Reload halaman supaya data tabel langsung mengambil data terbaru
                             * dari database.
                             *
                             * Kenapa reload?
                             * Karena tombol Simpan ada di modal, bukan di baris tabel.
                             * Jadi btn.closest('tr') tidak bisa menemukan row yang diedit.
                             */
                            window.location.reload();
                            return;
                        }

                        Notification.alert('Error', json.message || 'Gagal menyimpan', 'OK');

                    } catch (err) {
                        Notification.alert('Error', err?.message || 'Terjadi error', 'OK');
                    }

                    return;
                }

                if (action === 'toggle') {
                    const id = btn.dataset.id;

                    if (!id) {
                        return;
                    }

                    try {
                        const json = await post('toggle', {id});

                        if (json.ok) {
                            /*
                             * Untuk toggle juga lebih aman reload, supaya status, tombol,
                             * dan badge selalu sesuai data database.
                             */
                            window.location.reload();
                            return;
                        }

                        Notification.alert('Error', json.message || 'Gagal ubah status', 'OK');

                    } catch (err) {
                        Notification.alert('Error', err?.message || 'Terjadi error', 'OK');
                    }

                    return;
                }
            });

            if (modal) {
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        closeModal();
                    }
                });
            }
        }
    };
});