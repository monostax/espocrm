import View from 'view';
import RecordIcon from 'helpers/record-icon';

const catalogs = new Map();
const keyOf = icon => `${icon.type}:${icon.value}`;
const fold = value => value.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();

export default class RecordIconPicker extends View {
    templateContent = `
        <div class="record-icon-picker-tabs" role="tablist" aria-label="{{labels.title}}">
            <button type="button" role="tab" data-tab="emoji">{{labels.emojis}}</button>
            <button type="button" role="tab" data-tab="icon">{{labels.icons}}</button>
            <button type="button" class="btn btn-text btn-sm" data-action="clear">{{labels.remove}}</button>
        </div>
        <div class="record-icon-picker-controls">
            <input type="search" class="form-control input-sm" data-name="search" aria-label="{{labels.search}}" placeholder="{{labels.search}}">
            <select class="form-control input-sm" data-name="family" aria-label="{{labels.family}}">
                <option value="all">{{labels.allFamilies}}</option>
                <option value="tabler">Tabler</option>
                <option value="fontAwesome">Font Awesome</option>
            </select>
            <select class="form-control input-sm" data-name="tone" aria-label="{{labels.skinTone}}">
                <option value="0">✋ {{labels.skinTone}}</option>
                <option value="1">✋🏻</option><option value="2">✋🏼</option>
                <option value="3">✋🏽</option><option value="4">✋🏾</option><option value="5">✋🏿</option>
            </select>
        </div>
        <div class="record-icon-picker-results" tabindex="-1" role="tabpanel" aria-label="{{labels.title}}"></div>
        <div class="record-icon-picker-status text-muted small" role="status" aria-live="polite"></div>
    `;

    data() { return {labels: this.labels}; }

    setup() {
        this.labels = {};
        for (const key of ['title', 'emojis', 'icons', 'remove', 'search', 'family', 'allFamilies',
            'skinTone', 'recent', 'empty', 'loadError', 'saveError', 'loading']) {
            this.labels[key] = this.translate(key, 'labels', 'RecordIcon');
        }
        this.tab = this.options.value?.type === 'icon' ? 'icon' : 'emoji';
        this.tone = this.getStorage().get('state', 'recordIconSkinTone') || 0;
        const recent = this.getStorage().get('state', 'recordIconRecent');
        this.recent = Array.isArray(recent) ? recent.slice(0, 18) : [];
        this.host = document.createElement('div');
        this.host.id = this.options.fullSelector?.slice(1) || `record-icon-picker-${this.cid}`;
        this.host.className = 'record-icon-picker-popup';
        this.host.setAttribute('role', 'dialog');
        this.host.setAttribute('aria-label', this.labels.title);
        (this.options.triggerElement.closest('.modal') || document.body).append(this.host);
        this.setSelector(`#${this.host.id}`);
        this.options.triggerElement.setAttribute('aria-expanded', 'true');
        this.options.triggerElement.setAttribute('aria-controls', this.host.id);

        this.addHandler('click', '[data-tab]', (event, target) => {
            this.tab = target.dataset.tab;
            this.updateTabs();
            this.updateResults();
            this.element.querySelector('[data-name="search"]').focus();
        });
        this.addHandler('input', '[data-name="search"]', () => this.updateResults());
        this.addHandler('change', '[data-name="family"]', () => this.updateResults());
        this.addHandler('change', '[data-name="tone"]', (event, target) => {
            this.tone = Number(target.value);
            this.getStorage().set('state', 'recordIconSkinTone', this.tone);
            this.updateResults();
        });
        this.addHandler('click', '[data-icon-index]', (event, target) => {
            if (!this.busy) {
                const item = this.results[Number(target.dataset.iconIndex)];
                this.selected = {type: item.type, value: item.value};
                this.trigger('select', this.selected);
            }
        });
        this.addActionHandler('clear', () => {
            if (!this.busy) { this.trigger('select', null); }
        });

        this.outside = event => {
            if (!this.host.contains(event.target) && !this.options.triggerElement.contains(event.target)) {
                this.close(false);
            }
        };
        this.keydown = event => this.handleKeydown(event);
        this.reposition = () => this.position();
        document.addEventListener('pointerdown', this.outside);
        document.addEventListener('keydown', this.keydown, true);
        window.addEventListener('resize', this.reposition);
        window.addEventListener('scroll', this.reposition, true);
        this.once('remove', () => {
            document.removeEventListener('pointerdown', this.outside);
            document.removeEventListener('keydown', this.keydown, true);
            window.removeEventListener('resize', this.reposition);
            window.removeEventListener('scroll', this.reposition, true);
            this.options.triggerElement.setAttribute('aria-expanded', 'false');
            this.options.triggerElement.removeAttribute('aria-controls');
            this.host.remove();
        });

        this.iconItems = RecordIcon.classes(this.getMetadata()).map(value => ({
            type: 'icon', value,
            label: value.replace(/^(ti ti-|fa[rs] fa-)/, '').replaceAll('-', ' '),
            family: value.startsWith('ti ') ? 'tabler' : 'fontAwesome',
        }));
    }

    async afterRender() {
        this.element.querySelector('[data-name="tone"]').value = this.tone;
        this.scroller = this.element.querySelector('.record-icon-picker-results');
        this.scroller.addEventListener('scroll', () => {
            if (this.scroller.scrollTop + this.scroller.clientHeight > this.scroller.scrollHeight - 120) {
                this.appendResults();
            }
        });
        this.updateTabs();
        this.position();
        this.element.querySelector('[data-name="search"]').focus();
        this.updateResults();
        const locale = (this.getPreferences().get('language') || this.getConfig().get('language') || 'en_US')
            .startsWith('pt') ? 'pt' : 'en';
        const url = `${this.getBasePath()}client/custom/modules/global/res/record-icons/${locale}.json` +
            `?r=${encodeURIComponent(Espo.loader.getCacheTimestamp())}`;
        if (!catalogs.has(url)) {
            catalogs.set(url, fetch(url).then(response => {
                if (!response.ok) { throw new Error('Emoji data unavailable'); }
                return response.json();
            }).catch(error => {
                catalogs.delete(url);
                throw error;
            }));
        }
        try {
            this.emojiData = await catalogs.get(url);
            if (this.host.isConnected) { this.updateResults(); }
        } catch (error) {
            this.loadError = true;
            if (this.host.isConnected) { this.updateResults(); }
        }
    }

    updateTabs() {
        this.element.querySelectorAll('[data-tab]').forEach(button => {
            const active = button.dataset.tab === this.tab;
            button.setAttribute('aria-selected', String(active));
            button.tabIndex = active ? 0 : -1;
            button.classList.toggle('active', active);
        });
        this.element.querySelector('[data-name="family"]').hidden = this.tab !== 'icon';
        this.element.querySelector('[data-name="tone"]').hidden = this.tab !== 'emoji';
    }

    updateResults() {
        const query = fold(this.element.querySelector('[data-name="search"]').value.trim());
        const family = this.element.querySelector('[data-name="family"]').value;
        let items;
        if (this.tab === 'icon') {
            items = this.iconItems.filter(item => family === 'all' || item.family === family);
        } else {
            const groups = new Map((this.emojiData?.groups || []).map(group => [group.order, group.message]));
            items = (this.emojiData?.items || []).map(item => {
                const skin = this.tone && item.skins.find(skin => {
                    const tones = Array.isArray(skin.tone) ? skin.tone : [skin.tone];
                    return tones.every(tone => tone === this.tone);
                });
                return {...item, ...(skin || {}), type: 'emoji', heading: groups.get(item.group)};
            });
        }
        if (query) {
            items = items.filter(item => fold(`${item.label} ${item.value} ${(item.tags || []).join(' ')}`)
                .includes(query));
            const exactMatch = item => fold(item.value) === query || fold(item.label) === query;
            items.sort((a, b) => Number(exactMatch(b)) - Number(exactMatch(a)));
        } else {
            const catalog = new Map(items.map(item => [keyOf(item), item]));
            // Recent skin variants stay available even after the preferred tone changes.
            if (this.tab === 'emoji') {
                (this.emojiData?.items || []).forEach(item => {
                    item.skins.forEach(skin => {
                        catalog.set(`emoji:${skin.value}`, {...skin, type: 'emoji'});
                    });
                });
            }
            const recent = this.recent.map(icon => catalog.get(keyOf(icon))).filter(Boolean)
                .map(item => ({...item, heading: this.labels.recent}));
            items = [...recent, ...items];
        }
        this.results = items;
        this.renderedCount = 0;
        this.lastHeading = null;
        this.grid = null;
        this.scroller.replaceChildren();
        this.scroller.scrollTop = 0;
        this.appendResults();
        this.element.querySelector('.record-icon-picker-status').textContent = items.length ? '' :
            (this.tab === 'emoji' && !this.emojiData ?
                (this.loadError ? this.labels.loadError : this.labels.loading) : this.labels.empty);
    }

    appendResults() {
        const end = Math.min(this.renderedCount + 144, this.results.length);
        for (let i = this.renderedCount; i < end; i++) {
            const item = this.results[i];
            const heading = item.heading || (item.family === 'tabler' ? 'Tabler' : 'Font Awesome');
            if (this.lastHeading !== heading) {
                const label = document.createElement('div');
                label.className = 'record-icon-picker-heading text-muted';
                label.textContent = heading;
                this.scroller.append(label);
                this.lastHeading = heading;
                this.grid = null;
            }
            if (!this.grid) {
                this.grid = document.createElement('div');
                this.grid.className = 'record-icon-picker-grid';
                this.scroller.append(this.grid);
            }
            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.iconIndex = i;
            button.title = item.label;
            button.setAttribute('aria-label', item.label);
            button.setAttribute('aria-pressed', String(keyOf(this.options.value || {}) === keyOf(item)));
            button.tabIndex = i === 0 ? 0 : -1;
            button.disabled = !!this.busy;
            button.innerHTML = RecordIcon.html(item, this.getMetadata());
            this.grid.append(button);
        }
        this.renderedCount = end;
    }

    handleKeydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopImmediatePropagation();
            this.close();
            return;
        }
        if (!this.host.contains(event.target)) { return; }
        if (event.target.matches('[data-tab]') && ['ArrowLeft', 'ArrowRight'].includes(event.key)) {
            event.preventDefault();
            this.tab = this.tab === 'emoji' ? 'icon' : 'emoji';
            this.updateTabs();
            this.updateResults();
            this.element.querySelector(`[data-tab="${this.tab}"]`).focus();
            return;
        }
        const current = event.target.dataset.iconIndex;
        if (event.target.matches('[data-name="search"]') && event.key === 'ArrowDown') {
            event.preventDefault();
            this.scroller.querySelector('button')?.focus();
        } else if (current !== undefined && ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'].includes(event.key)) {
            event.preventDefault();
            const delta = {ArrowLeft: -1, ArrowRight: 1, ArrowUp: -9, ArrowDown: 9};
            const index = event.key === 'Home' ? 0 : event.key === 'End' ? this.results.length - 1 :
                Math.max(0, Math.min(this.results.length - 1, Number(current) + delta[event.key]));
            while (index >= this.renderedCount) { this.appendResults(); }
            event.target.tabIndex = -1;
            const next = this.scroller.querySelector(`[data-icon-index="${index}"]`);
            next.tabIndex = 0;
            next.focus();
        } else if (event.key === 'Tab') {
            const controls = [...this.host.querySelectorAll('button, input, select')]
                .filter(element => !element.disabled && !element.hidden && element.tabIndex >= 0);
            const index = controls.indexOf(event.target);
            if ((event.shiftKey && index === 0) || (!event.shiftKey && index === controls.length - 1)) {
                event.preventDefault();
                controls[event.shiftKey ? controls.length - 1 : 0]?.focus();
            }
        }
    }

    position() {
        const trigger = this.options.triggerElement;
        if (!trigger.isConnected) { this.close(false); return; }
        const rect = trigger.getBoundingClientRect();
        const height = this.host.offsetHeight;
        const width = this.host.offsetWidth;
        this.host.style.left = `${Math.max(8, Math.min(rect.left, window.innerWidth - width - 8))}px`;
        this.host.style.top = `${Math.max(8, rect.bottom + height + 8 < window.innerHeight ?
            rect.bottom + 6 : rect.top - height - 6)}px`;
    }

    setBusy(busy) {
        this.busy = busy;
        this.host.setAttribute('aria-busy', String(busy));
        this.host.querySelectorAll('button, input, select').forEach(element => { element.disabled = busy; });
    }

    showError() {
        this.selected = null;
        this.element.querySelector('.record-icon-picker-status').textContent = this.labels.saveError;
    }

    close(restoreFocus = true) {
        if (this.closed || this.busy) { return; }
        this.closed = true;
        if (this.selected) {
            this.getStorage().set('state', 'recordIconRecent', [this.selected,
                ...this.recent.filter(icon => keyOf(icon) !== keyOf(this.selected))].slice(0, 18));
        }
        const trigger = this.options.triggerElement;
        this.trigger('close');
        if (restoreFocus && trigger.isConnected) { trigger.focus(); }
    }
}
