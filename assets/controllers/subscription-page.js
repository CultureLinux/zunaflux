const search = document.querySelector('[data-category-search]');
const normalize = (value) => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('fr').trim();
search?.addEventListener('input', () => {
    const query = normalize(search.value);
    document.querySelectorAll('[data-category-item]').forEach((item) => {
        item.hidden = !normalize(item.dataset.categoryItem).includes(query);
    });
});

document.querySelectorAll('[data-open-delete]').forEach((button) => {
    button.addEventListener('click', () => document.getElementById(button.dataset.openDelete)?.showModal());
});
