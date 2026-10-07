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

    // Skill detail popup: hover or focus any [data-skill-id]. Details come from /skill/{id}.json
    // (static SDE data, cached per page). Built with textContent only.
    const skillCache = new Map();
    const romanNum   = ['', 'I', 'II', 'III', 'IV', 'V'];
    let skillPop = null, skillCurrent = null, skillHideTimer = null;

    const popEl = () => {
        if (!skillPop) {
            skillPop = document.createElement('div');
            skillPop.id = 'skill-pop';
            skillPop.className = 'skill-pop';
            skillPop.setAttribute('role', 'tooltip');
            skillPop.hidden = true;
            skillPop.addEventListener('mouseenter', () => clearTimeout(skillHideTimer));
            skillPop.addEventListener('mouseleave', () => hideSoon());
            document.body.appendChild(skillPop);
        }
        return skillPop;
    };
    const el = (tag, cls, text) => {
        const node = document.createElement(tag);
        if (cls) node.className = cls;
        if (text !== undefined) node.textContent = text;
        return node;
    };
    // Two largest units: 41m, 3h 56m, 2d 12h
    const formatMinutes = (min) => {
        const d = Math.floor(min / 1440), h = Math.floor((min % 1440) / 60), m = min % 60;
        if (d) return d + 'd' + (h ? ' ' + h + 'h' : '');
        if (h) return h + 'h' + (m ? ' ' + m + 'm' : '');
        return m + 'm';
    };
    const renderSkill = (s) => {
        const pop = popEl();
        pop.replaceChildren();
        pop.append(el('p', 'skill-pop__name', s.name));
        const meta = [s.group, 'Rank ' + s.rank, s.primary && s.secondary ? s.primary + ' / ' + s.secondary : null].filter(Boolean);
        pop.append(el('p', 'skill-pop__meta', meta.join(' · ')));
        if (s.description) pop.append(el('p', 'skill-pop__desc', s.description));

        const sp = el('table', 'skill-pop__sp');
        const head = el('tr'), points = el('tr'), time = el('tr', 'skill-pop__time');
        head.append(el('th'));
        points.append(el('th', 'skill-pop__rowhead', 'SP'));
        time.append(el('th', 'skill-pop__rowhead', 'Time'));
        let total = 0;
        for (let l = 1; l <= 5; l++) {
            head.append(el('th', null, romanNum[l]));
            points.append(el('td', null, Number(s.sp[l]).toLocaleString('en')));
            time.append(el('td', null, formatMinutes(s.train_minutes[l])));
            total += s.train_minutes[l];
        }
        sp.append(head, points, time);
        pop.append(el('p', 'skill-pop__label', 'SKILL POINTS AND TRAINING TIME PER LEVEL'), sp);
        pop.append(el('p', 'skill-pop__note', '≈ ' + formatMinutes(total) + ' from untrained to V · with ' + s.train_attribute
            + ' in both attributes, Omega, no implants (Alpha trains at half speed)'));

        pop.append(el('p', 'skill-pop__label', 'REQUIRES'));
        if (s.prerequisites.length) {
            const list = el('ul', 'skill-pop__reqs');
            s.prerequisites.forEach(p => list.append(el('li', null, p.name + ' ' + romanNum[p.level])));
            pop.append(list);
        } else {
            pop.append(el('p', 'skill-pop__desc', 'No prerequisites'));
        }
    };
    const placePop = (target) => {
        const pop = popEl();
        const r   = target.getBoundingClientRect();
        const gap = 6;
        let top = r.bottom + gap;
        if (top + pop.offsetHeight > window.innerHeight - 8) top = Math.max(8, r.top - pop.offsetHeight - gap);
        const left = Math.min(Math.max(8, r.left), window.innerWidth - pop.offsetWidth - 8);
        pop.style.top  = top + 'px';
        pop.style.left = left + 'px';
    };
    const hideSkill = () => {
        if (skillPop) skillPop.hidden = true;
        if (skillCurrent) skillCurrent.removeAttribute('aria-describedby');
        skillCurrent = null;
    };
    const hideSoon = () => { clearTimeout(skillHideTimer); skillHideTimer = setTimeout(hideSkill, 150); };
    const showSkill = (target) => {
        clearTimeout(skillHideTimer);
        if (target === skillCurrent) return;
        if (skillCurrent) skillCurrent.removeAttribute('aria-describedby');
        skillCurrent = target;
        target.setAttribute('aria-describedby', 'skill-pop');
        const id   = target.dataset.skillId;
        const draw = (data) => {
            if (skillCurrent !== target) return;          // moved on while loading
            renderSkill(data);
            popEl().hidden = false;
            placePop(target);
        };
        if (skillCache.has(id)) { draw(skillCache.get(id)); return; }
        const pop = popEl();
        pop.replaceChildren(el('p', 'skill-pop__meta', 'Loading…'));
        pop.hidden = false;
        placePop(target);
        // ?v= changes whenever the JSON's shape does: responses are browser-cached for a day
        fetch('/skill/' + encodeURIComponent(id) + '.json?v=2')
            .then(r => r.ok ? r.json() : Promise.reject(r.status))
            .then(data => { skillCache.set(id, data); draw(data); })
            .catch(() => { if (skillCurrent === target) hideSkill(); });
    };

    document.addEventListener('mouseover', e => {
        const target = e.target.closest('[data-skill-id]');
        if (target) showSkill(target);
    });
    document.addEventListener('mouseout', e => {
        const target = e.target.closest('[data-skill-id]');
        if (target && !target.contains(e.relatedTarget)) hideSoon();
    });
    document.addEventListener('focusin', e => {
        const target = e.target.closest('[data-skill-id]');
        if (target) showSkill(target);
    });
    document.addEventListener('focusout', e => {
        if (e.target.closest('[data-skill-id]')) hideSoon();
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') hideSkill(); });
    window.addEventListener('scroll', () => { if (skillCurrent && skillPop && !skillPop.hidden) placePop(skillCurrent); }, { passive: true });

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
