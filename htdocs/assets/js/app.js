/* EVEchievements — minimal JS */
document.addEventListener('DOMContentLoaded', () => {
    // Highlight active nav link
    const links = document.querySelectorAll('.topnav__link');
    links.forEach(link => {
        if (link.href === window.location.href ||
            (link.getAttribute('href') !== '/' && window.location.pathname.startsWith(link.getAttribute('href')))) {
            link.classList.add('topnav__link--active');
        }
    });

    // Auto-submit visibility toggle
    const visToggle = document.querySelector('input[name="is_public"]');
    if (visToggle) {
        visToggle.addEventListener('change', function() {
            this.closest('form').submit();
        });
    }

    // Asset type search filter
    const assetSearch = document.getElementById('asset-search');
    if (assetSearch) {
        assetSearch.addEventListener('input', function() {
            const term = this.value.toLowerCase();
            document.querySelectorAll('#asset-table .asset-row').forEach(row => {
                const name = row.querySelector('td:nth-child(2)').textContent.toLowerCase();
                row.classList.toggle('asset-row--hidden', !name.includes(term));
            });
        });
    }



    // Mastery level tabs: switch panels in place, keep ?level= so the URL still links to the tab
    const levelTabs = document.querySelectorAll('.level-tab[data-level]');
    const showLevel = (level) => {
        levelTabs.forEach(tab => {
            const active = tab.dataset.level === level;
            tab.classList.toggle('level-tab--active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
            document.getElementById('level-' + tab.dataset.level).hidden = !active;
        });
    };
    levelTabs.forEach(tab => {
        tab.addEventListener('click', e => {
            e.preventDefault();
            showLevel(tab.dataset.level);
            const url = new URL(window.location);
            url.searchParams.set('level', tab.dataset.level);
            history.replaceState(null, '', url);
        });
    });

    document.querySelectorAll('.section-toggle').forEach(btn => {
        btn.addEventListener('click', function() {
            const target = document.getElementById(this.dataset.target);
            target.classList.toggle('collapsed');
            this.textContent = target.classList.contains('collapsed') ? '▸' : '▾';
        });
    });
});
