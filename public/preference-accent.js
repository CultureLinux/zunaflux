(() => {
    const select = document.querySelector('[data-accent-choice]');
    if (!select) return;
    select.addEventListener('change', () => { select.dataset.accentValue = select.value; });
})();
