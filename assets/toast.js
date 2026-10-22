(() => {
    const stack = document.querySelector('[data-toast-stack]');
    if (!stack) return;
    const setup = (toast) => {
        let closing = false;
        const remove = () => {
            if (closing) return;
            closing = true;
            clearTimeout(timer);
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { toast.remove(); return; }
            toast.classList.add('toast--closing');
            toast.addEventListener('animationend', () => toast.remove(), { once: true });
            setTimeout(() => toast.remove(), 250);
        };
        const timer = setTimeout(remove, 6000);
        toast.querySelector('[data-toast-close]')?.addEventListener('click', remove);
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
