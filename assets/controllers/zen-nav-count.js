import { fetchCounters } from '../reading/api.js';

const zenNav = document.querySelector('[data-zen-nav]');
const zenNavCount = document.querySelector('[data-zen-count]');

if (zenNav && zenNavCount) {
    fetchCounters()
        .then(data => {
            const count = data.all.unread_count;
            zenNavCount.textContent = String(count);
            zenNav.setAttribute('aria-label', (count === 1 ? zenNav.dataset.openOne : zenNav.dataset.openMany).replace('%count%', String(count)));
        })
        .catch(() => { zenNavCount.textContent = '–'; });
}
