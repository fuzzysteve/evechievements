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



    // Ship page tabs (mastery levels, attributes, bonuses): switch panels in place and keep
    // ?level= / ?tab= in the URL so it still links to the open tab
    const pageTabs = document.querySelectorAll('.level-tab[data-panel]');
    pageTabs.forEach(tab => {
        tab.addEventListener('click', e => {
            e.preventDefault();
            pageTabs.forEach(other => {
                const active = other === tab;
                other.classList.toggle('level-tab--active', active);
                other.setAttribute('aria-selected', active ? 'true' : 'false');
                document.getElementById(other.dataset.panel).hidden = !active;
            });
            const url = new URL(window.location);
            url.searchParams.delete('level');
            url.searchParams.delete('tab');
            const [key, value] = tab.dataset.query.split('=');
            url.searchParams.set(key, value);
            history.replaceState(null, '', url);
        });
    });

    // Nav search suggestions. Built with textContent: pilot names are user data.
    const searchInput = document.getElementById('site-search');
    const suggestBox  = document.getElementById('site-search-results');
    if (searchInput && suggestBox) {
        let timer = null;
        let latest = 0;

        const close = () => {
            suggestBox.hidden = true;
            searchInput.setAttribute('aria-expanded', 'false');
        };
        const links = () => [...suggestBox.querySelectorAll('a')];

        const section = (title, items) => {
            const wrap = document.createElement('div');
            const head = document.createElement('p');
            head.className = 'search-suggest__head';
            head.textContent = title;
            wrap.appendChild(head);
            items.forEach(item => {
                const a = document.createElement('a');
                a.href = item.url;
                a.className = 'search-suggest__item';
                const img = document.createElement('img');
                img.src = item.image;
                img.alt = '';
                img.width = img.height = 32;
                const text = document.createElement('span');
                const name = document.createElement('span');
                name.className = 'search-suggest__name';
                name.textContent = item.name;
                const detail = document.createElement('span');
                detail.className = 'search-suggest__detail';
                detail.textContent = item.detail;
                text.append(name, detail);
                a.append(img, text);
                wrap.appendChild(a);
            });
            return wrap;
        };

        const render = (data, q) => {
            suggestBox.replaceChildren();
            if (data.pilots.length) suggestBox.appendChild(section('PILOTS', data.pilots));
            if (data.ships.length)  suggestBox.appendChild(section('SHIPS', data.ships));
            if (!data.pilots.length && !data.ships.length) {
                const none = document.createElement('p');
                none.className = 'search-suggest__empty';
                none.textContent = 'No pilots or ships match.';
                suggestBox.appendChild(none);
            }
            const all = document.createElement('a');
            all.href = '/search?q=' + encodeURIComponent(q);
            all.className = 'search-suggest__all';
            all.textContent = 'See all results →';
            suggestBox.appendChild(all);
            suggestBox.hidden = false;
            searchInput.setAttribute('aria-expanded', 'true');
        };

        searchInput.addEventListener('input', () => {
            clearTimeout(timer);
            const q = searchInput.value.trim();
            if (q.length < 2) { close(); return; }
            timer = setTimeout(() => {
                const id = ++latest;
                fetch('/search.json?q=' + encodeURIComponent(q))
                    .then(r => r.json())
                    .then(data => { if (id === latest) render(data, q); })
                    .catch(close);
            }, 200);
        });

        // Arrow keys move between suggestions, Escape closes
        searchInput.closest('form').addEventListener('keydown', e => {
            const items = links();
            const index = items.indexOf(document.activeElement);
            if (e.key === 'ArrowDown' && items.length) {
                e.preventDefault();
                items[Math.min(index + 1, items.length - 1)].focus();
            } else if (e.key === 'ArrowUp' && index >= 0) {
                e.preventDefault();
                (index === 0 ? searchInput : items[index - 1]).focus();
            } else if (e.key === 'Escape') {
                close();
                searchInput.focus();
            }
        });
        document.addEventListener('click', e => {
            if (!searchInput.closest('form').contains(e.target)) close();
        });
    }

    // Sortable tables: header buttons sort rows on their data-* values. Numbers sort high to low
    // first, text A–Z; clicking the same header again reverses.
    document.querySelectorAll('table[data-sortable]').forEach(table => {
        const tbody = table.tBodies[0];
        table.querySelectorAll('.sort-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const th      = btn.closest('th');
                const numeric = btn.dataset.sortType === 'number';
                const current = th.getAttribute('aria-sort');
                const dir     = current ? (current === 'ascending' ? 'descending' : 'ascending')
                                        : (numeric ? 'descending' : 'ascending');
                const key  = btn.dataset.sortKey;
                const sign = dir === 'ascending' ? 1 : -1;
                const rows = [...tbody.rows].sort((a, b) => sign * (numeric
                    ? parseFloat(a.dataset[key]) - parseFloat(b.dataset[key])
                    : a.dataset[key].localeCompare(b.dataset[key])));
                tbody.append(...rows);
                table.querySelectorAll('th[aria-sort]').forEach(h => h.removeAttribute('aria-sort'));
                th.setAttribute('aria-sort', dir);
            });
        });
    });

    document.querySelectorAll('.section-toggle').forEach(btn => {
        btn.addEventListener('click', function() {
            const target = document.getElementById(this.dataset.target);
            const collapsed = target.classList.toggle('collapsed');
            this.textContent = collapsed ? '▸' : '▾';
            this.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            this.setAttribute('aria-label', (collapsed ? 'Expand' : 'Collapse') + this.getAttribute('aria-label').replace(/^\S+/, ''));
        });
    });
});
