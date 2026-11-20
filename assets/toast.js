const t = (key, params = {}) => (typeof window !== 'undefined' && window.zunaflux?.t ? window.zunaflux.t(key, params) : Object.entries(params).reduce((text, [name, value]) => text.replace(`%${name}%`, String(value)), key));

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
        // Errors stay until dismissed (WCAG 2.2.1); other toasts pause while hovered or focused.
        const persistent = toast.classList.contains('toast--error');
        let timer = null;
        const start = () => { if (!persistent && !closing) timer = setTimeout(remove, 6000); };
        const stop = () => clearTimeout(timer);
        toast.addEventListener('mouseenter', stop);
        toast.addEventListener('mouseleave', start);
        toast.addEventListener('focusin', stop);
        toast.addEventListener('focusout', start);
        start();
        toast.querySelector('[data-toast-close]')?.addEventListener('click', remove);
    };
    stack.querySelectorAll('.toast').forEach(setup);
    window.addEventListener('zunaflux:toast', (event) => {
        const toast = document.createElement('div');
        const type = event.detail.type === 'error' ? 'error' : 'success';
        toast.className = `toast toast--${type}`;
        toast.append(document.createTextNode(event.detail.message));
        const close = document.createElement('button');
        close.type = 'button';
        close.dataset.toastClose = '';
        close.setAttribute('aria-label', t('Fermer'));
        close.textContent = '×';
        toast.append(close);
        stack.append(toast);
        setup(toast);
    });
})();
