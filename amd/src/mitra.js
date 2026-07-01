define(['core/notification'], function(Notification) {
    return {
        init: function() {
            const ajaxurl = document.getElementById('am-ajaxurl')?.value || '';
            const sesskey = document.getElementById('am-sesskey')?.value || '';

            const modal = document.getElementById('am-modal');
            const importModal = document.getElementById('am-import-modal');

            const title = document.getElementById('m-title');
            const mid = document.getElementById('m-id');
            const mnama = document.getElementById('m-nama');
            const malamat = document.getElementById('m-alamat');
            const mkontak = document.getElementById('m-kontak');

            /**
             * Open add/edit modal.
             */
            function openModal() {
                if (modal) {
                    modal.style.display = 'flex';
                }
            }

            /**
             * Close add/edit modal.
             */
            function closeModal() {
                if (modal) {
                    modal.style.display = 'none';
                }
            }

            /**
             * Open import modal.
             */
            function openImportModal() {
                if (importModal) {
                    importModal.style.display = 'flex';
                }
            }

            /**
             * Close import modal.
             */
            function closeImportModal() {
                if (importModal) {
                    importModal.style.display = 'none';
                }
            }

            /**
             * Reset add/edit form fields.
             */
            function resetForm() {
                if (mid) {
                    mid.value = '';
                }
                if (mnama) {
                    mnama.value = '';
                }
                if (malamat) {
                    malamat.value = '';
                }
                if (mkontak) {
                    mkontak.value = '';
                }
            }

            /**
             * Send AJAX request to backend.
             *
             * @param {string} action AJAX action name.
             * @param {Object} payload Request payload.
             * @returns {Promise<Object>} JSON response.
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
                        title.textContent = 'Tambah Data Mitra';
                    }
                    openModal();
                    return;
                }

                if (action === 'close-modal') {
                    closeModal();
                    return;
                }

                if (action === 'open-import') {
                    openImportModal();
                    return;
                }

                if (action === 'close-import') {
                    closeImportModal();
                    return;
                }

                if (action === 'edit') {
                    if (title) {
                        title.textContent = 'Edit Data Mitra';
                    }
                    if (mid) {
                        mid.value = btn.dataset.id || '';
                    }
                    if (mnama) {
                        mnama.value = btn.dataset.nama || '';
                    }
                    if (malamat) {
                        malamat.value = btn.dataset.alamat || '';
                    }
                    if (mkontak) {
                        mkontak.value = btn.dataset.kontak || '';
                    }
                    openModal();
                    return;
                }

                if (action === 'save') {
                    const id = mid?.value?.trim() || '';
                    const nama = mnama?.value?.trim() || '';
                    const alamat = malamat?.value?.trim() || '';
                    const kontak = mkontak?.value?.trim() || '';

                    if (!nama) {
                        Notification.alert('Error', 'Nama Mitra wajib diisi', 'OK');
                        return;
                    }

                    try {
                        let out;
                        if (id) {
                            out = await post('update', {id, nama, alamat, kontak});
                        } else {
                            out = await post('create', {nama, alamat, kontak});
                        }

                        if (out?.ok) {
                            closeModal();
                            window.location.reload();
                        } else {
                            Notification.alert('Error', out?.message || 'Gagal menyimpan.', 'OK');
                        }
                    } catch (err) {
                        Notification.alert('Error', err?.message || 'Terjadi error saat menyimpan.', 'OK');
                    }
                    return;
                }

                if (action === 'toggle') {
                    const id = btn.dataset.id;
                    if (!id) {
                        return;
                    }

                    try {
                        const out = await post('toggle', {id});
                        if (out?.ok) {
                            window.location.reload();
                        } else {
                            Notification.alert('Error', out?.message || 'Gagal mengubah status.', 'OK');
                        }
                    } catch (err) {
                        Notification.alert('Error', err?.message || 'Terjadi error saat mengubah status.', 'OK');
                    }
                }
            });

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