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



    document.querySelectorAll('.section-toggle').forEach(btn => {
        btn.addEventListener('click', function() {
            const target = document.getElementById(this.dataset.target);
            target.classList.toggle('collapsed');
            this.textContent = target.classList.contains('collapsed') ? '▸' : '▾';
        });
    });
});
