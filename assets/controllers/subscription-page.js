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

const mobileActions = document.querySelector('[data-mobile-subscription-actions]');
const mobileLayout = window.matchMedia('(max-width: 767px)');
const movableForms = [...document.querySelectorAll('[data-mobile-move]')].map((form) => {
    const placeholder = document.createComment('subscription form');
    form.before(placeholder);
    return { form, placeholder, slot: document.querySelector(`[data-mobile-slot="${form.dataset.mobileMove}"]`) };
});
const updateMobileLayout = () => {
    movableForms.forEach(({ form, placeholder, slot }) => {
        if (mobileLayout.matches) slot.append(form);
        else placeholder.after(form);
    });
    if (mobileActions) mobileActions.hidden = !mobileLayout.matches;
    if (!mobileLayout.matches) document.querySelectorAll('#mobile-add-feed[open], #mobile-import-feed[open]').forEach(dialog => dialog.close());
};
if (mobileActions && typeof HTMLDialogElement !== 'undefined' && HTMLDialogElement.prototype.showModal) {
    updateMobileLayout();
    mobileLayout.addEventListener('change', updateMobileLayout);
}
const directCategory = document.querySelector('[data-direct-category]');
directCategory?.addEventListener('change', () => {
    const create = directCategory.value === 'new';
    document.querySelector('[data-direct-new-category]').hidden = !create;
    document.querySelector('[data-direct-category-name]').required = create;
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
        discoveryForm.closest('dialog')?.close();
        discoveryDialog.showModal();
        const startedAt = Date.now();
        if (toastStack) discoveryDialog.append(toastStack);
        try {
            const response = await fetch(discoveryForm.dataset.liveUrl, { method: 'POST', headers: { Accept: 'application/json' }, body: new FormData(discoveryForm), signal: request.signal });
            let data;
            try {
                data = await response.json();
            } catch (error) {
                if (error.name === 'AbortError') throw error;
                throw new Error(`Réponse inattendue du serveur (HTTP ${response.status}). Consultez les journaux du serveur.`);
            }
            if (!data || typeof data !== 'object' || (response.ok && !Array.isArray(data.feeds))) {
                throw new Error(`Réponse inattendue du serveur (HTTP ${response.status}). Consultez les journaux du serveur.`);
            }
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

// Le déplacement à la souris complète le sélecteur de catégorie du formulaire.
const dragDesktop = window.matchMedia('(min-width: 1024px) and (pointer: fine)');
let draggedSubscription = null;
document.querySelectorAll('[data-drag-handle]').forEach((handle) => {
    const updateHandle = () => {
        handle.draggable = dragDesktop.matches;
        handle.hidden = !dragDesktop.matches;
    };
    updateHandle();
    dragDesktop.addEventListener('change', updateHandle);
    handle.addEventListener('dragstart', (event) => {
        if (!dragDesktop.matches) return;
        draggedSubscription = handle.closest('[data-subscription-item]');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', 'subscription');
    });
    handle.addEventListener('dragend', () => {
        draggedSubscription = null;
        document.querySelectorAll('[data-drop-category]').forEach((zone) => zone.classList.remove('bg-accent-soft'));
    });
});
document.querySelectorAll('[data-drop-category]').forEach((zone) => {
    zone.addEventListener('dragover', (event) => {
        if (!draggedSubscription || !dragDesktop.matches) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        zone.classList.add('bg-accent-soft');
    });
    zone.addEventListener('dragleave', (event) => {
        if (!zone.contains(event.relatedTarget)) zone.classList.remove('bg-accent-soft');
    });
    zone.addEventListener('drop', (event) => {
        if (!draggedSubscription || !dragDesktop.matches) return;
        event.preventDefault();
        zone.classList.remove('bg-accent-soft');
        if (draggedSubscription.closest('[data-drop-category]') === zone) return;
        const form = draggedSubscription.querySelector('[data-move-subscription]');
        form.elements.category_id.value = zone.dataset.dropCategory;
        form.requestSubmit();
    });
});
