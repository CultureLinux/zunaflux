(() => {
    const stack = document.querySelector('[data-toast-stack]');
    if (!stack) return;
    const setup = (toast) => {
        toast.querySelector('[data-toast-close]')?.addEventListener('click', () => toast.remove());
        setTimeout(() => toast.remove(), 6000);
    };
    stack.querySelectorAll('.toast').forEach(setup);
    window.addEventListener('zunaflux:toast', (event) => {
        const toast = document.createElement('div');
        const type = event.detail.type === 'error' ? 'error' : 'success';
        toast.className = `toast toast--${type}`;
        toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
        toast.append(document.createTextNode(event.detail.message));
        const close = document.createElement('button');
        close.type = 'button';
        close.dataset.toastClose = '';
        close.setAttribute('aria-label', 'Fermer');
        close.textContent = '×';
        toast.append(close);
        stack.append(toast);
        setup(toast);
    });
})();
