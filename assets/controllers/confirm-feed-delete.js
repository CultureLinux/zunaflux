document.querySelectorAll('[data-confirm-open]').forEach((button) => {
    const dialog = document.getElementById(button.dataset.confirmOpen);
    if (!(dialog instanceof HTMLDialogElement)) return;
    button.addEventListener('click', () => dialog.showModal());
    dialog.querySelector('[data-confirm-close]')?.addEventListener('click', () => dialog.close());
});
