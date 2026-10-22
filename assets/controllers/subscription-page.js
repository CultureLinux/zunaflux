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

document.querySelectorAll('[data-open-delete]').forEach((button) => {
    button.addEventListener('click', () => {
        if (button.hasAttribute('data-close-current-dialog')) button.closest('dialog')?.close();
        document.getElementById(button.dataset.openDelete)?.showModal();
    });
});
