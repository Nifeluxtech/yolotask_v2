'use strict';

(() => {
  const closeToast = (button) => {
    const toast = button.closest('.glass-toast');
    if (toast) toast.remove();
  };

  const openDialog = (button) => {
    const id = button.getAttribute('data-modal-open');
    const dialog = id ? document.getElementById(id) : null;
    if (typeof HTMLDialogElement === 'undefined' || !(dialog instanceof HTMLDialogElement) || typeof dialog.showModal !== 'function' || dialog.open) return;
    dialog.showModal();
    document.body.classList.add('modal-open');
  };

  const closeDialog = (button) => {
    const dialog = button.closest('dialog');
    if (typeof HTMLDialogElement !== 'undefined' && dialog instanceof HTMLDialogElement && dialog.open) dialog.close();
  };

  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;

    const dismiss = target.closest('[data-toast-dismiss]');
    if (dismiss) {
      closeToast(dismiss);
      return;
    }

    const opener = target.closest('[data-modal-open]');
    if (opener) {
      openDialog(opener);
      return;
    }

    const closer = target.closest('[data-modal-close]');
    if (closer) closeDialog(closer);
  });

  document.querySelectorAll('dialog').forEach((dialog) => {
    if (typeof HTMLDialogElement === 'undefined' || !(dialog instanceof HTMLDialogElement)) return;
    dialog.addEventListener('close', () => document.body.classList.remove('modal-open'));
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) dialog.close();
    });
  });
})();
