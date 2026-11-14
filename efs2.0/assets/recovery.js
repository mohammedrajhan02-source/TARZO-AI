'use strict';

function wireRecovery() {
  const panel = Array.from(document.querySelectorAll('#app section.panel'))
    .find(el => el.querySelector('h2')?.textContent.trim() === 'Backup & recovery');
  if (!panel || panel.dataset.recoveryWired) return;
  panel.dataset.recoveryWired = '1';
  const hint = panel.querySelector('.form-hint');
  if (hint) hint.textContent = 'Recovery replaces current database data with an EFS backup. An automatic safety backup is saved first. Administrator access is required.';
  if (S?.user?.role !== 'admin') return;

  const picker = document.createElement('input');
  picker.type = 'file'; picker.accept = '.sql'; picker.hidden = true;
  const choose = document.createElement('button');
  choose.type = 'button'; choose.className = 'btn'; choose.textContent = 'Recovery';
  panel.querySelector('.actions').append(choose, picker);
  const box = document.createElement('div');
  box.className = 'efs-recovery';
  box.innerHTML = '<h3>Recovery files</h3><p class="efs-recovery-status" role="status" aria-live="polite"></p><div class="efs-recovery-files"></div><p class="form-hint">EFS SQL backups only · Maximum 8 MB. Delete removes the uploaded file and its list entry; restored system data and safety backups remain.</p>';
  panel.append(box);
  const status = box.querySelector('.efs-recovery-status');
  const list = box.querySelector('.efs-recovery-files');
  let busy = false;

  function message(text, error = false) {
    status.textContent = text;
    status.classList.toggle('is-error', error);
  }
  function lock(value) {
    busy = value;
    panel.querySelectorAll('button').forEach(button => { button.disabled = value; });
    box.setAttribute('aria-busy', String(value));
  }
  async function loadFiles() {
    const files = await api('recovery_list');
    if (!box.isConnected) return;
    list.replaceChildren();
    if (!files.length) {
      const empty = document.createElement('p');
      empty.className = 'form-hint'; empty.textContent = 'No recovery files uploaded.';
      list.append(empty);
    }
    files.forEach(file => {
      const row = document.createElement('div'); row.className = 'efs-recovery-file';
      const details = document.createElement('div'); details.className = 'efs-recovery-details';
      const name = document.createElement('strong'); name.textContent = file.name;
      const info = document.createElement('small');
      const label = {ready: 'Ready to restore', restored: 'Restored', failed: 'Restore failed'}[file.status] || file.status;
      info.textContent = `${label} · ${(file.size / 1024).toFixed(1)} KB · Uploaded ${file.uploaded_at} UTC`;
      details.append(name, info);
      if (file.restored_at && file.status === 'restored') {
        const date = document.createElement('small'); date.textContent = `Restored ${file.restored_at} UTC`; details.append(date);
      }
      if (file.safety_backup) {
        const safety = document.createElement('small');
        safety.textContent = `Safety backup: var/backups/${file.safety_backup}`; details.append(safety);
      }
      const actions = document.createElement('div'); actions.className = 'efs-recovery-actions';
      if (file.status !== 'restored') {
        const restore = document.createElement('button'); restore.type = 'button';
        restore.className = 'btn small'; restore.textContent = 'Restore'; restore.disabled = busy;
        restore.addEventListener('click', () => { if (!busy) restoreFile(file); }); actions.append(restore);
      }
      const remove = document.createElement('button'); remove.type = 'button';
      remove.className = 'btn small efs-recovery-delete'; remove.textContent = 'Delete'; remove.disabled = busy;
      remove.addEventListener('click', async () => {
        if (busy || !window.confirm(`Delete uploaded file "${file.name}"?\n\nThis removes only the uploaded file and list entry. Restored system data and safety backups will remain.`)) return;
        lock(true);
        try { await api('recovery_delete', {id: file.id}); await loadFiles(); message('Uploaded file deleted. System data was not changed.'); }
        catch (error) { message(error.message, true); }
        finally { lock(false); }
      });
      actions.append(remove); row.append(details, actions); list.append(row);
    });
  }
  async function restoreFile(file) {
    if (!window.confirm(`Restore "${file.name}"?\n\nThis REPLACES current database data, including users, sales, stock and accounts. It does not merge records. A safety backup is created first.\n\nAll users will be signed out. You must know an administrator login stored in this backup. Pause work at all tills until recovery finishes.\n\nContinue?`)) return;
    lock(true); message('Creating safety backup and restoring data… Keep this page open.');
    try {
      await api('recovery_restore', {id: file.id, confirm: 'RESTORE'});
      window.alert('Recovery completed. Please sign in using an account from the restored backup.');
      window.location.reload();
    } catch (error) {
      message(`${error.message} If the connection was interrupted, refresh and check file status before retrying.`, true);
      try { await loadFiles(); } catch (_) { /* Preserve the original error. */ }
      lock(false);
    }
  }
  choose.addEventListener('click', () => { if (!busy) { picker.value = ''; picker.click(); } });
  picker.addEventListener('change', async () => {
    const file = picker.files?.[0];
    if (!file || busy) return;
    if (!/\.sql$/i.test(file.name) || file.size > 8 * 1024 * 1024 || !file.size) {
      message('Choose a non-empty EFS .sql backup, maximum 8 MB.', true); return;
    }
    lock(true); message('Uploading and checking backup…');
    try {
      const content = await new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result).split(',')[1]);
        reader.onerror = () => reject(Error('Could not read the selected file.'));
        reader.readAsDataURL(file);
      });
      const uploaded = await api('recovery_upload', {name: file.name, content});
      await loadFiles(); message('Backup validated. Choose Restore to replace current data.');
      lock(false);
      if (box.isConnected) await restoreFile(uploaded);
    } catch (error) { message(error.message, true); lock(false); }
    finally { picker.value = ''; }
  });
  loadFiles().catch(error => message(error.message, true));
}