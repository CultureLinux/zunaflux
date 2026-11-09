(() => {
    const tabs = [...document.querySelectorAll('[data-preference-tab]')];
    if (!tabs.length) return;

    const select = name => {
        tabs.forEach(tab => {
            const active = tab.dataset.preferenceTab === name;
            const panel = document.getElementById(`preference-${tab.dataset.preferenceTab}`);
            tab.setAttribute('aria-selected', String(active));
            tab.tabIndex = active ? 0 : -1;
            if (panel) panel.hidden = !active;
        });
    };

    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => select(tab.dataset.preferenceTab));
        tab.addEventListener('keydown', event => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const next = 'Home' === event.key ? 0 : 'End' === event.key ? tabs.length - 1 : (index + ('ArrowRight' === event.key ? 1 : -1) + tabs.length) % tabs.length;
            tabs[next].focus();
            select(tabs[next].dataset.preferenceTab);
        });
    });

    select('general');
})();
