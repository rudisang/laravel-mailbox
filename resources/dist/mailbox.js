/*! Laravel Mailbox workbench — interaction layer. ES module, no dependencies, no inline styles. */

const root = document.documentElement;
const body = document.body;
const base = (body.dataset.mailboxBase || '').replace(/\/+$/, '');
const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

const list = document.getElementById('mailbox-list');
const detail = document.getElementById('mailbox-detail');
const live = document.getElementById('mailbox-live');
const unreadBadge = document.getElementById('mailbox-unread');
const shell = document.querySelector('[data-pane]');
const toast = document.querySelector('[data-toast]');
const helpDialog = document.getElementById('mailbox-shortcuts');
const searchForm = document.querySelector('form[data-search]');
const searchInput = document.getElementById('mailbox-search');

const ID = /\/messages\/([0-9A-HJKMNP-TV-Z]{26})/;
const FALLBACK_EMPTY =
    '<section class="mb-empty" aria-labelledby="mailbox-empty-title">' +
    '<span class="mb-empty__mark" aria-hidden="true">' +
    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' +
    '<path d="M3 7.5 12 13l9-5.5"/><rect x="3" y="5" width="18" height="14" rx="2.5"/></svg></span>' +
    '<h1 id="mailbox-empty-title">Nothing selected</h1>' +
    '<p>Choose a message on the left to read it, inspect its headers, MIME tree and diagnostics.</p>' +
    '</section>';
const PREVIEW_TABS = ['html', 'text'];

let seq = Number(body.dataset.mailboxSeq || '0') || 0;
let currentId = idFrom(location.pathname);
let emptyDetailHtml = currentId ? '' : detail?.innerHTML || '';
let cursor = -1;
let busy = 0;
let etag = null;
let pollTimer = 0;
let searchTimer = 0;
let toastTimer = 0;
let lastActivity = Date.now();

const IDLE_MS = 10 * 60 * 1000;
const POLL_VISIBLE = 2000;
const POLL_HIDDEN = 30000;

/* ---------------------------------------------------------- helpers */

const qs = (selector, scope = document) => scope.querySelector(selector);
const qsa = (selector, scope = document) => Array.from(scope.querySelectorAll(selector));

function idFrom(pathname) {
    const match = ID.exec(pathname || '');

    return match ? match[1] : null;
}

function request(url, options = {}) {
    return fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers: { 'X-Requested-With': 'XMLHttpRequest', ...(options.headers || {}) },
    });
}

function partial(pathname, search, kind) {
    const params = new URLSearchParams(search || '');
    params.set('partial', kind);

    return pathname + '?' + params.toString();
}

function setBusy(delta) {
    busy = Math.max(0, busy + delta);
    body.toggleAttribute('data-busy', busy > 0);
}

function announce(message) {
    if (!live) {
        return;
    }
    live.textContent = '';
    window.setTimeout(() => {
        live.textContent = message;
    }, 50);
}

function flash(message) {
    announce(message);

    if (!toast) {
        return;
    }
    toast.textContent = message;
    toast.setAttribute('data-visible', '');
    window.clearTimeout(toastTimer);
    toastTimer = window.setTimeout(() => toast.removeAttribute('data-visible'), 3200);
}

function rows() {
    return list ? qsa('a[data-message]', list) : [];
}

function setPane(name) {
    if (shell) {
        shell.dataset.pane = name;
    }
}

/* ---------------------------------------------------------- theme */

const THEMES = ['system', 'light', 'dark'];
const THEME_LABELS = { system: 'System', light: 'Light', dark: 'Dark' };

function applyTheme(theme) {
    const value = THEMES.includes(theme) ? theme : 'system';
    root.dataset.theme = value;
    qsa('[data-theme-label]').forEach((node) => {
        node.textContent = THEME_LABELS[value];
    });
    try {
        localStorage.setItem('mailbox-theme', value);
    } catch (error) {
        /* storage disabled — the session default still applies */
    }
}

function bootTheme() {
    let stored = null;
    try {
        stored = localStorage.getItem('mailbox-theme');
    } catch (error) {
        stored = null;
    }
    applyTheme(stored || root.dataset.theme || 'system');
}

/* ---------------------------------------------------------- unread badge */

function setUnread(count) {
    if (!unreadBadge) {
        return;
    }
    unreadBadge.dataset.unread = String(count);
    const number = qs('[data-unread-count]', unreadBadge) || unreadBadge;
    number.textContent = String(count);
}

/* ---------------------------------------------------------- list */

function markSelection({ markRead = false } = {}) {
    let index = -1;
    rows().forEach((row, position) => {
        const selected = row.dataset.message === currentId;
        row.classList.toggle('is-selected', selected);
        row.setAttribute('aria-current', selected ? 'true' : 'false');
        if (selected) {
            if (markRead) {
                row.classList.remove('is-unread');
            }
            index = position;
        }
    });
    if (index >= 0) {
        cursor = index;
    }
}

function rowById(id) {
    return id ? rows().find((row) => row.dataset.message === id) || null : null;
}

function captureCursor() {
    const focused = document.activeElement;

    return {
        cursorId: cursor >= 0 ? rows()[cursor]?.dataset.message || null : null,
        focusId: focused instanceof HTMLElement ? focused.dataset.message || null : null,
    };
}

function restoreCursor({ cursorId, focusId }) {
    const all = rows();
    const target = rowById(cursorId);

    if (target) {
        cursor = all.indexOf(target);
    } else if (cursor >= all.length) {
        cursor = all.length - 1;
    }

    all.forEach((row, index) => row.classList.toggle('is-cursor', index === cursor && cursor >= 0));

    const focusTarget = rowById(focusId);
    if (focusTarget && document.activeElement !== focusTarget) {
        focusTarget.focus({ preventScroll: true });
    }
}

function moveCursor(step) {
    const all = rows();
    if (all.length === 0) {
        return;
    }
    cursor = Math.min(all.length - 1, Math.max(0, (cursor < 0 ? (step > 0 ? -1 : 0) : cursor) + step));
    all.forEach((row, index) => row.classList.toggle('is-cursor', index === cursor));
    const row = all[cursor];
    row.focus({ preventScroll: true });
    row.scrollIntoView({ block: 'nearest', behavior: reduceMotion.matches ? 'auto' : 'smooth' });
}

function syncFilterLinks(query) {
    qsa('a[data-filter]').forEach((link) => {
        const url = new URL(link.href, location.href);
        if (query) {
            url.searchParams.set('q', query);
        } else {
            url.searchParams.delete('q');
        }
        link.href = url.pathname + (url.search === '?' ? '' : url.search);
    });
}

/** @returns {Promise<number>} how many rows in the refreshed list were not there before */
async function refreshList() {
    if (!list) {
        return 0;
    }
    const before = new Set(rows().map((row) => row.dataset.message));
    const keyboard = captureCursor();
    let arrived = 0;
    setBusy(1);
    try {
        const response = await request(partial(base || location.pathname, location.search, 'list'));
        if (!response.ok) {
            return 0;
        }
        list.innerHTML = await response.text();
        rows().forEach((row) => {
            if (before.has(row.dataset.message)) {
                return;
            }
            arrived += 1;
            if (!reduceMotion.matches) {
                row.classList.add('mb-row-new');
            }
        });
        markSelection();
        restoreCursor(keyboard);
    } catch (error) {
        /* offline or navigating away — the next poll retries */
    } finally {
        setBusy(-1);
    }

    return arrived;
}

/* ---------------------------------------------------------- detail */

function swapDetail(html, focusHeading) {
    if (!detail) {
        return;
    }
    const paint = () => {
        detail.innerHTML = html;
        detail.scrollTop = 0;
        initDetail();
        detail.classList.remove('is-swapping');
        if (focusHeading) {
            qs('[data-detail-title]', detail)?.focus({ preventScroll: true });
        }
    };

    if (reduceMotion.matches) {
        paint();
        return;
    }

    detail.classList.add('is-swapping');
    window.setTimeout(paint, 120);
}

function emptyDetail() {
    currentId = null;
    setPane('list');
    markSelection();
    swapDetail(emptyDetailHtml || FALLBACK_EMPTY, false);
}

async function openMessage(href, { push = true, focus = true } = {}) {
    if (!detail) {
        return;
    }
    const url = new URL(href, location.href);
    const id = idFrom(url.pathname);
    setBusy(1);
    try {
        const response = await request(partial(url.pathname, url.search, 'detail'));

        if (response.status === 404) {
            history.replaceState({}, '', base + location.search);
            await refreshList();
            emptyDetail();
            flash('That message is no longer in the mailbox.');

            return;
        }

        if (!response.ok) {
            location.assign(href);

            return;
        }
        const html = await response.text();
        currentId = id;
        if (push) {
            history.pushState({ mailbox: id }, '', url.pathname + location.search);
        }
        setPane('detail');
        swapDetail(html, focus);
        markSelection({ markRead: true });
        pollSoon();
    } catch (error) {
        location.assign(href);
    } finally {
        setBusy(-1);
    }
}

/* ---------------------------------------------------------- tabs */

function tabButtons() {
    return detail ? qsa('[role="tablist"] [role="tab"]', detail) : [];
}

function activeTabIndex() {
    return Math.max(0, tabButtons().findIndex((tab) => tab.getAttribute('aria-selected') === 'true'));
}

function selectTab(index, focus) {
    const tabs = tabButtons();
    if (tabs.length === 0) {
        return;
    }
    const target = (index + tabs.length) % tabs.length;

    tabs.forEach((tab, position) => {
        const on = position === target;
        tab.setAttribute('aria-selected', on ? 'true' : 'false');
        tab.tabIndex = on ? 0 : -1;
        const panel = document.getElementById(tab.getAttribute('aria-controls') || '');
        if (panel) {
            panel.hidden = !on;
        }
    });

    const name = tabs[target].dataset.tab || '';
    const group = qs('[data-viewport-group]', detail);
    if (group) {
        const show = PREVIEW_TABS.includes(name);
        if (!show && group.contains(document.activeElement)) {
            tabs[target].focus();
        }
        group.hidden = !show;
    }

    try {
        sessionStorage.setItem('mailbox-tab', name);
    } catch (error) {
        /* ignore */
    }

    if (focus) {
        tabs[target].focus();
    }
}

function initDetail() {
    if (!detail) {
        return;
    }
    const tabs = tabButtons();
    if (tabs.length > 0) {
        let remembered = null;
        try {
            remembered = sessionStorage.getItem('mailbox-tab');
        } catch (error) {
            remembered = null;
        }
        const index = tabs.findIndex((tab) => tab.dataset.tab === remembered);
        selectTab(index >= 0 ? index : activeTabIndex(), false);
    }

    let viewport = null;
    try {
        viewport = sessionStorage.getItem('mailbox-viewport');
    } catch (error) {
        viewport = null;
    }
    setViewport(viewport || 'full');
}

function setViewport(mode) {
    const card = detail ? qs('[data-detail-root]', detail) : null;
    if (card) {
        card.dataset.viewportMode = mode;
    }
    qsa('[data-viewport]').forEach((button) => {
        button.setAttribute('aria-pressed', button.dataset.viewport === mode ? 'true' : 'false');
    });
    try {
        sessionStorage.setItem('mailbox-viewport', mode);
    } catch (error) {
        /* ignore */
    }
}

/* ---------------------------------------------------------- polling */

function pollSchedule() {
    window.clearTimeout(pollTimer);
    pollTimer = 0;
    if (Date.now() - lastActivity > IDLE_MS) {
        return;
    }
    pollTimer = window.setTimeout(() => {
        pollTimer = 0;
        pollTick();
    }, document.visibilityState === 'visible' ? POLL_VISIBLE : POLL_HIDDEN);
}

function pollSoon() {
    window.clearTimeout(pollTimer);
    pollTimer = window.setTimeout(() => {
        pollTimer = 0;
        pollTick();
    }, 250);
}

function wake() {
    lastActivity = Date.now();
    if (!pollTimer) {
        pollSchedule();
    }
}

async function pollTick() {
    try {
        const response = await request(base + '/api/status?since=' + seq, {
            headers: etag ? { 'If-None-Match': etag } : {},
        });

        if (response.status === 200) {
            etag = response.headers.get('ETag') || etag;
            const status = await response.json();
            const next = Number(status.seq) || 0;
            setUnread(Number(status.unread) || 0);

            if (next !== seq) {
                seq = next;
                body.dataset.mailboxSeq = String(seq);
                const arrived = await refreshList();
                if (arrived > 0) {
                    flash(arrived + ' new message' + (arrived === 1 ? '' : 's'));
                }
            }
        }
    } catch (error) {
        /* transient failure — retry on the next tick */
    }
    pollSchedule();
}

/* ---------------------------------------------------------- actions */

function readControls() {
    const form = detail ? qs('form[data-action="read"]', detail) : null;

    return form ? { form, input: qs('input[name="read"]', form), label: qs('[data-read-label]', form) } : null;
}

function applyReadState(isRead) {
    const controls = readControls();
    if (controls) {
        if (controls.input) {
            controls.input.value = isRead ? '0' : '1';
        }
        if (controls.label) {
            controls.label.textContent = isRead ? 'Mark unread' : 'Mark read';
        }
    }
    const row = list ? qs('a[data-message="' + CSS.escape(currentId || '') + '"]', list) : null;
    if (row) {
        row.classList.toggle('is-unread', !isRead);
    }
}

async function runAction(form, action) {
    setBusy(1);
    try {
        const response = await request(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
        });

        if (!response.ok) {
            form.submit();
            return;
        }

        const payload = await response.json().catch(() => ({}));

        if (action === 'read') {
            applyReadState(payload.read === true);
            flash(payload.read === true ? 'Marked as read' : 'Marked as unread');
        } else if (action === 'delete') {
            const all = rows();
            const index = all.findIndex((row) => row.dataset.message === currentId);
            const next = all[index + 1] || all[index - 1] || null;
            const href = next ? next.href : null;
            await refreshList();
            if (href) {
                await openMessage(href, { push: true, focus: true });
            } else {
                history.pushState({}, '', base + location.search);
                emptyDetail();
            }
            flash('Message deleted');
        } else if (action === 'clear') {
            await refreshList();
            history.pushState({}, '', base);
            emptyDetail();
            flash('Mailbox cleared');
        }
        pollSoon();
    } catch (error) {
        form.submit();
    } finally {
        setBusy(-1);
    }
}

/* ---------------------------------------------------------- search */

function runSearch({ push = true } = {}) {
    const value = (searchInput?.value || '').trim().slice(0, 200);
    const params = new URLSearchParams(location.search);
    if (value) {
        params.set('q', value);
    } else {
        params.delete('q');
    }
    params.delete('partial');
    const query = params.toString();
    if (push) {
        history.replaceState(history.state, '', location.pathname + (query ? '?' + query : ''));
    }
    syncFilterLinks(value);

    return refreshList();
}

/* ---------------------------------------------------------- events */

function isTyping(target) {
    return (
        target instanceof HTMLElement &&
        (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))
    );
}

document.addEventListener('click', (event) => {
    wake();

    const themeButton = event.target.closest?.('[data-theme-toggle]');
    if (themeButton) {
        event.preventDefault();
        const index = THEMES.indexOf(root.dataset.theme || 'system');
        applyTheme(THEMES[(index + 1) % THEMES.length]);

        return;
    }

    const helpButton = event.target.closest?.('[data-shortcuts-help]');
    if (helpButton) {
        event.preventDefault();
        openHelp();

        return;
    }

    const closeButton = event.target.closest?.('[data-dialog-close]');
    if (closeButton && helpDialog) {
        event.preventDefault();
        helpDialog.close();

        return;
    }

    const viewportButton = event.target.closest?.('[data-viewport]');
    if (viewportButton) {
        event.preventDefault();
        setViewport(viewportButton.dataset.viewport);

        return;
    }

    const tab = event.target.closest?.('[role="tab"][data-tab]');
    if (tab) {
        event.preventDefault();
        selectTab(tabButtons().indexOf(tab), false);

        return;
    }

    const back = event.target.closest?.('[data-back]');
    if (back) {
        event.preventDefault();
        history.pushState({}, '', base + location.search);
        emptyDetail();

        return;
    }

    const link = event.target.closest?.('a[data-message]');
    if (link && event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey) {
        event.preventDefault();
        openMessage(link.href);
    }
});

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    if (form.matches('[data-search]')) {
        event.preventDefault();
        window.clearTimeout(searchTimer);
        runSearch();

        return;
    }

    const action = form.dataset.action;
    if (!action) {
        return;
    }

    if (action === 'clear' && !window.confirm('Delete every captured message? This cannot be undone.')) {
        event.preventDefault();

        return;
    }

    event.preventDefault();
    runAction(form, action);
});

document.addEventListener('keydown', (event) => {
    wake();

    if (event.key === 'Escape') {
        if (helpDialog?.open) {
            return;
        }
        if (window.innerWidth < 960 && shell?.dataset.pane === 'detail') {
            history.pushState({}, '', base + location.search);
            emptyDetail();
        }

        return;
    }

    const tabs = tabButtons();
    if (tabs.includes(document.activeElement)) {
        const index = tabs.indexOf(document.activeElement);
        const map = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: tabs.length - 1 };
        if (event.key in map) {
            event.preventDefault();
            selectTab(map[event.key], true);

            return;
        }
    }

    if (event.metaKey || event.ctrlKey || event.altKey || isTyping(event.target) || helpDialog?.open) {
        return;
    }

    switch (event.key) {
        case 'j':
            event.preventDefault();
            moveCursor(1);
            break;
        case 'k':
            event.preventDefault();
            moveCursor(-1);
            break;
        case 'Enter': {
            const row = rows()[cursor];
            if (row && document.activeElement === row) {
                event.preventDefault();
                openMessage(row.href);
            }
            break;
        }
        case '/':
            event.preventDefault();
            searchInput?.focus();
            searchInput?.select();
            break;
        case 'e': {
            const form = detail ? qs('form[data-action="delete"]', detail) : null;
            if (form) {
                event.preventDefault();
                runAction(form, 'delete');
            }
            break;
        }
        case 'u': {
            const controls = readControls();
            if (controls) {
                event.preventDefault();
                runAction(controls.form, 'read');
            }
            break;
        }
        case '[':
            if (tabs.length > 0) {
                event.preventDefault();
                selectTab(activeTabIndex() - 1, false);
            }
            break;
        case ']':
            if (tabs.length > 0) {
                event.preventDefault();
                selectTab(activeTabIndex() + 1, false);
            }
            break;
        case '?':
            event.preventDefault();
            openHelp();
            break;
        default:
            break;
    }
});

function openHelp() {
    if (!helpDialog) {
        return;
    }
    if (typeof helpDialog.showModal === 'function') {
        if (!helpDialog.open) {
            helpDialog.showModal();
        }
    } else {
        helpDialog.setAttribute('open', '');
    }
}

window.addEventListener('popstate', () => {
    const id = idFrom(location.pathname);
    if (searchInput) {
        searchInput.value = new URLSearchParams(location.search).get('q') || '';
    }
    refreshList();
    if (id) {
        openMessage(location.pathname + location.search, { push: false, focus: false });
    } else {
        emptyDetail();
    }
});

document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
        wake();
        pollSoon();
    } else {
        pollSchedule();
    }
});

['pointerdown', 'wheel', 'focusin'].forEach((name) => {
    document.addEventListener(name, wake, { passive: true });
});

if (searchInput) {
    searchInput.addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => runSearch(), 260);
    });
}

if (helpDialog) {
    helpDialog.addEventListener('click', (event) => {
        if (event.target === helpDialog) {
            helpDialog.close();
        }
    });
}

/* ---------------------------------------------------------- boot */

bootTheme();
root.dataset.js = '';
initDetail();
markSelection();
if (currentId) {
    setPane('detail');
}
if (list) {
    pollSchedule();
}
