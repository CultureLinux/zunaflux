export const viewFromParams = (params) => {
    if (params.get('favorite') === '1') return 'favorite';
    if (params.get('saved') === '1') return 'saved';
    const read = params.get('read');
    return read === '0' || read === '1' || read === '' ? read : '0';
};

export const setViewParams = (params, view) => {
    for (const key of ['read', 'favorite', 'saved']) params.delete(key);
    if (view === 'favorite' || view === 'saved') params.set(view, '1');
    else params.set('read', view);
};

export const isReadingSourceVisible = ({ unread, read, favorite = 0, saved = 0 }, view, sourceCategoryId, selectedCategoryId) => {
    if (selectedCategoryId && sourceCategoryId !== selectedCategoryId) return false;
    return (view === '0' ? unread : view === '1' ? read : view === 'favorite' || view === 'saved' ? unread : unread + read) > 0;
};

export const updateSourceMarkReadControls = (forms, subscriptionId, categoryId) => {
    forms.forEach((form) => { form.hidden = categoryId ? form.dataset.categoryId !== categoryId : !subscriptionId || form.dataset.subscriptionId !== subscriptionId; });
};

export const updateReadingSources = (root, view, categoryFilter) => {
    root.querySelectorAll('[data-reading-category]').forEach((link) => {
        const url = new URL(link.href);
        setViewParams(url.searchParams, view);
        link.href = url.href;
    });
    root.querySelectorAll('[data-reading-source]').forEach((row) => {
        const unread = Number(row.dataset.unread);
        const read = Number(row.dataset.read);
        const favorite = Number(row.dataset.favorite);
        const saved = Number(row.dataset.saved);
        row.hidden = !isReadingSourceVisible({ unread, read, favorite, saved }, view, row.dataset.categoryId || '', categoryFilter);
        const link = row.querySelector('[data-source-link]');
        const url = new URL(link.href);
        setViewParams(url.searchParams, view);
        link.href = url.href;
    });
    root.querySelectorAll('[data-reading-category-group]').forEach((group) => {
        const outsideCategory = categoryFilter && group.dataset.readingCategoryGroup !== categoryFilter;
        const noUnread = view === '0' && ![...group.querySelectorAll('[data-reading-source]')].some((row) => Number(row.dataset.unread) > 0);
        group.hidden = !!outsideCategory || noUnread;
    });
};
