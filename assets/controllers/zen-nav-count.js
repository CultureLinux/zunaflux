const zenNav = document.querySelector('[data-zen-nav]');
const zenNavCount = document.querySelector('[data-zen-count]');

if (zenNav && zenNavCount) {
    fetch('/api/statistics/counters', { headers: { Accept: 'application/json' } })
        .then(response => {
            if (!response.ok) throw new Error();
            return response.json();
        })
        .then(data => {
            const count = data.all.unread_count;
            zenNavCount.textContent = String(count);
            zenNav.setAttribute('aria-label', (count === 1 ? zenNav.dataset.openOne : zenNav.dataset.openMany).replace('%count%', String(count)));
        })
        .catch(() => { zenNavCount.textContent = '–'; });
}
