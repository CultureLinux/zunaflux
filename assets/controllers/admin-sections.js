const adminButtons = [...document.querySelectorAll('[data-admin-section]')];
const adminNavigation = document.getElementById('admin-navigation');

if (adminButtons.length) {
    const selectSection = name => {
        if (!adminButtons.some(button => button.dataset.adminSection === name)) return;
        adminButtons.forEach(button => {
            const active = button.dataset.adminSection === name;
            const panel = document.getElementById(`admin-${button.dataset.adminSection}`);
            if (panel) panel.hidden = !active;
            button.setAttribute('aria-pressed', String(active));
        });
        try { sessionStorage.setItem('admin-section', name); } catch (_) { /* Storage can be disabled. */ }
    };
    adminButtons.forEach(button => button.addEventListener('click', () => selectSection(button.dataset.adminSection)));
    let initial = 'configuration';
    try { initial = sessionStorage.getItem('admin-section') || initial; } catch (_) { /* Use the default section. */ }
    if (!adminButtons.some(button => button.dataset.adminSection === initial)) initial = 'configuration';
    selectSection(initial);
    if (adminNavigation) adminNavigation.hidden = false;
}
