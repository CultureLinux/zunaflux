const search = document.querySelector('[data-category-search]');
const normalize = (value) => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('fr').trim();
search?.addEventListener('input', () => {
    const query = normalize(search.value);
    document.querySelectorAll('[data-category-item]').forEach((item) => {
        const categoryMatches = normalize(item.dataset.categoryItem).includes(query);
        let hasMatchingSubscription = false;
        item.querySelectorAll('[data-subscription-item]').forEach((subscription) => {
            const subscriptionMatches = [subscription.dataset.subscriptionTitle, subscription.dataset.feedTitle, subscription.dataset.feedUrl]
                .some((value) => normalize(value || '').includes(query));
            subscription.hidden = !categoryMatches && !subscriptionMatches;
            hasMatchingSubscription ||= subscriptionMatches;
        });
        item.hidden = !categoryMatches && !hasMatchingSubscription;
    });
});
document.querySelector('[data-category-search-reset]')?.addEventListener('click', () => {
    if (!search) return;
    search.value = '';
    search.dispatchEvent(new Event('input'));
    search.focus();
});

document.querySelectorAll('[data-open-delete]').forEach((button) => {
    button.addEventListener('click', () => {
        if (button.hasAttribute('data-close-current-dialog')) button.closest('dialog')?.close();
        document.getElementById(button.dataset.openDelete)?.showModal();
    });
});

const discoveryForm = document.querySelector('[data-discovery-form]');
const discoveryDialog = document.querySelector('[data-discovery-dialog]');
if (discoveryForm && discoveryDialog && typeof discoveryDialog.showModal === 'function') {
    const status = discoveryDialog.querySelector('[data-discovery-status]');
    const results = discoveryDialog.querySelector('[data-discovery-results]');
    const options = discoveryDialog.querySelector('[data-discovery-options]');
    const progress = discoveryDialog.querySelector('[data-discovery-progress]');
    const category = discoveryDialog.querySelector('[data-discovery-category]');
    const newCategory = discoveryDialog.querySelector('[data-discovery-new-category]');
    const categoryName = discoveryDialog.querySelector('[data-discovery-category-name]');
    category.addEventListener('change', () => {
        newCategory.hidden = category.value !== 'new';
        categoryName.required = category.value === 'new';
    });
    const manual = discoveryDialog.querySelector('[data-discovery-manual]');
    const manualLink = discoveryDialog.querySelector('[data-discovery-manual-link]');
    const toastStack = document.querySelector('[data-toast-stack]');
    let currentRequest;
    discoveryForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        currentRequest?.abort();
        const request = new AbortController();
        currentRequest = request;
        options.replaceChildren();
        results.hidden = true;
        progress.hidden = false;
        manual.hidden = true;
        manualLink.removeAttribute('href');
        status.textContent = 'Chargement de la page et analyse de ses flux…';
        discoveryDialog.showModal();
        const startedAt = Date.now();
        if (toastStack) discoveryDialog.append(toastStack);
        try {
            const response = await fetch(discoveryForm.dataset.liveUrl, { method: 'POST', body: new FormData(discoveryForm), signal: request.signal });
            const data = await response.json();
            await new Promise((resolve) => setTimeout(resolve, Math.max(0, 1000 - (Date.now() - startedAt))));
            if (request.signal.aborted) return;
            progress.hidden = true;
            if (!response.ok) {
                if (data.reason === 'site_forbidden') {
                    const url = new URL(discoveryForm.elements.site_url.value);
                    if (['http:', 'https:'].includes(url.protocol) && !url.username && !url.password) {
                        manualLink.href = url.href;
                        manual.hidden = false;
                    }
                }
                throw new Error(data.error || 'La recherche a échoué.');
            }
            if (!data.feeds.length) {
                status.textContent = 'Aucun flux RSS ou Atom déclaré sur cette page.';
                window.dispatchEvent(new CustomEvent('zunaflux:toast', { detail: { type: 'error', message: status.textContent } }));
                return;
            }
            status.textContent = `${data.feeds.length} flux trouvé(s). Choisissez celui à ajouter.`;
            data.feeds.forEach((feed) => {
                const label = document.createElement('label');
                label.className = 'discovery-option';
                const radio = document.createElement('input');
                radio.type = 'radio'; radio.name = 'feed_url'; radio.value = feed.url; radio.required = true;
                const text = document.createElement('span');
                const title = document.createElement('strong'); title.textContent = feed.title;
                const url = document.createElement('span'); url.className = 'discovery-url'; url.textContent = feed.url;
                text.append(title, url); label.append(radio, text); options.append(label);
            });
            results.hidden = false;
            window.dispatchEvent(new CustomEvent('zunaflux:toast', { detail: { type: 'success', message: 'Recherche des flux terminée.' } }));
        } catch (error) {
            if (error.name === 'AbortError') return;
            await new Promise((resolve) => setTimeout(resolve, Math.max(0, 1000 - (Date.now() - startedAt))));
            if (request.signal.aborted) return;
            progress.hidden = true;
            status.textContent = error.message || 'La recherche a échoué.';
            window.dispatchEvent(new CustomEvent('zunaflux:toast', { detail: { type: 'error', message: status.textContent } }));
        }
    });
    discoveryDialog.addEventListener('close', () => {
        currentRequest?.abort();
        if (toastStack) document.body.append(toastStack);
    });
}
