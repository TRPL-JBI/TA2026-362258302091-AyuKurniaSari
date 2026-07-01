/* eslint-disable complexity */
define([], function() {

    return {
        init: function() {

            const ajaxurl = document.getElementById('am-ajaxurl')?.value || '';
            const sesskey = document.getElementById('am-sesskey')?.value || '';
            const tokenInput = document.getElementById('am-token');
            const statusEl = document.getElementById('am-token-status');

            const modal = document.getElementById('am-modal');
            const mId = document.getElementById('m-id');
            const mLabelView = document.getElementById('m-label-view');
            const mOffset = document.getElementById('m-offset');
            const mTime = document.getElementById('m-time');
            const mKeywordView = document.getElementById('m-keyword-view');
            const mKeywordHelp = document.getElementById('m-keyword-help');

            const recipientsBox = document.getElementById('m-recipients');

            /* ================= STATUS ================= */
            /**
             * @param {string} text
             * @param {boolean} ok
             */
            function showStatus(text, ok) {
                if (!statusEl) {
 return;
}
                statusEl.textContent = text;
                statusEl.style.color = ok ? '#1f7a3b' : '#b42318';
            }

            /* ================= AJAX ================= */

            /**
             * Send AJAX request
             *
             * @param {string} action
             * @param {Object} payload
             * @returns {Promise<Object>}
             */
            async function post(action, payload = {}) {
                const fd = new FormData();
                fd.append('sesskey', sesskey);
                fd.append('action', action);

                Object.keys(payload).forEach(k => {
                    fd.append(k, payload[k]);
                });

                const res = await fetch(ajaxurl, {
                    method: 'POST',
                    body: fd
                });

                return res.json();
            }

            /* ================= MODAL ================= */

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

            /* ================= RECIPIENTS ================= */

            /**
             *
             * @param {string} str
             */
            function setRecipientsChecked(str) {
                if (!recipientsBox) {
 return;
}

                const current = (str || '')
                    .split(',')
                    .map(s => s.trim())
                    .filter(Boolean);

                recipientsBox
                    .querySelectorAll('input[type="checkbox"]')
                    .forEach(ch => {
                        ch.checked = current.includes(ch.value);
                    });
            }

            /**
             *
             */
            function getRecipientsValue() {
                if (!recipientsBox) {
 return '';
}

                const arr = [];
                recipientsBox
                    .querySelectorAll('input[type="checkbox"]')
                    .forEach(ch => {
                        if (ch.checked) {
 arr.push(ch.value);
}
                    });

                return arr.join(', ');
            }

            /* ================= ACTION HANDLER ================= */

            document.body.addEventListener('click', async function(e) {
                const btn = e.target.closest('[data-action]');
                if (!btn) {
 return;
}

                const action = btn.dataset.action;

                // CHECK TELEGRAM
                if (action === 'check-telegram') {
                    const token = tokenInput?.value.trim() || '';
                    if (!token) {
                        showStatus('Token kosong.', false);
                        return;
                    }

                    showStatus('Mengecek koneksi...', true);
                    const json = await post('check_token', {token});
                    showStatus(json.message || (json.ok ? 'OK' : 'Gagal'), !!json.ok);
                    return;
                }

                // SAVE TELEGRAM
                if (action === 'save-telegram') {
                    const token = tokenInput?.value.trim() || '';
                    if (!token) {
                        showStatus('Token kosong.', false);
                        return;
                    }

                    showStatus('Menyimpan token...', true);
                    const json = await post('save_token', {token, enabled: '1'});
                    showStatus(json.message || (json.ok ? 'Tersimpan' : 'Gagal'), !!json.ok);
                    return;
                }

                // EDIT RULE
                if (action === 'edit-rule') {
                    if (mOffset) {
                        mOffset.closest('div').style.display = '';
                    }

                    if (mKeywordView) {
                        mKeywordView.closest('div').style.display = '';
                    }

                    const reportWrap =
                        document.getElementById('report-days-wrap');

                    if (reportWrap) {
                        reportWrap.style.display = 'none';
                    }
                    if (mId) {
                mId.value = btn.dataset.id || '';
                }
                                    if (mLabelView) {
                    mLabelView.textContent = btn.dataset.label || '-';
                }
                                    if (mOffset) {
                mOffset.value = btn.dataset.offset || '';
                }
                                    if (mTime) {
                mTime.value = btn.dataset.time || '07:00:00';
                }
                                    if (mKeywordView) {
                    mKeywordView.textContent = btn.dataset.keywordLabel || '-';
                }
                if (mKeywordHelp) {
                    mKeywordHelp.textContent = btn.dataset.keywordHelp || 'Keyword dikunci oleh sistem.';
                }
                    setRecipientsChecked(btn.dataset.recipients || '');
                    openModal();
                    return;
                }
                // EDIT REPORT
                if (action === 'edit-report') {

                    if (mId) {
                        mId.value = btn.dataset.id || '';
                    }

                    if (mLabelView) {
                        mLabelView.textContent = btn.dataset.label || '-';
                    }

                    if (mOffset) {
                        mOffset.value = btn.dataset.offset || '';
                    }

                    if (mTime) {
                        mTime.value = btn.dataset.time || '16:00:00';
                    }

                    if (mKeywordView) {
                        mKeywordView.textContent = btn.dataset.schedule || '-';
                    }

                    if (mKeywordHelp) {
                        mKeywordHelp.textContent =
                            'Pengaturan jadwal pengiriman laporan.';
                    }
                    const reportWrap =
                        document.getElementById('report-days-wrap');

                    if (reportWrap) {
                        reportWrap.style.display = '';
                    }
                    const offsetWrap =
                        document.getElementById('offset-wrap');

                    if (offsetWrap) {
                        offsetWrap.style.display = 'none';
                    }

                    const keywordWrap =
                        document.getElementById('keyword-wrap');

                    if (keywordWrap) {
                        keywordWrap.style.display = 'none';
                    }
                    document.querySelectorAll('.report-day')
                        .forEach(cb => {
                            cb.checked = false;
                        });

                    const selectedDays =
                        (btn.dataset.days || '')
                            .split('');

                    document.querySelectorAll('.report-day')
                        .forEach(cb => {
                            cb.checked =
                                selectedDays.includes(cb.value);
                        });

                    setRecipientsChecked(btn.dataset.recipients || '');

                    openModal();
                    return;
                }
                // CLOSE MODAL
                if (action === 'close-modal') {
                    closeModal();
                    return;
                }

                // SAVE RULE
                if (action === 'save-rule') {

                    const id = mId?.value || '';

                    let offset = mOffset?.value.trim() || '';

                    const time = mTime?.value.trim() || '';

                    const recipients = getRecipientsValue();

                    const reportWrap =
                        document.getElementById('report-days-wrap');

                    if (reportWrap &&
                        reportWrap.style.display !== 'none') {

                        const checkedDays = [];

                        document
                            .querySelectorAll('.report-day:checked')
                            .forEach(cb => {
                                checkedDays.push(cb.value);
                            });

                        offset = checkedDays.join('');
                    }

                    const json = await post('update_rule', {
                        id,
                        offset,
                        time,
                        recipients
                    });

                    if (json.ok) {
                        location.reload();
                    } else {
                        alert(json.message || 'Gagal update rule');
                    }

                    return;
                }

                // TOGGLE RULE
                if (action === 'toggle-rule') {
                    const id = btn.dataset.id;
                    const json = await post('toggle_rule', {id});

                    if (json.ok) {
 location.reload();
} else {
 alert(json.message || 'Gagal toggle');
}

                    return;
                }
            });

            /* ================= CLICK OUTSIDE MODAL ================= */

            modal?.addEventListener('click', function(e) {
                if (e.target === modal) {
 closeModal();
}
            });

        }
    };

});