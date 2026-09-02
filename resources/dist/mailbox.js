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
const notifyDialog = document.getElementById('mailbox-notify');
const notifyButton = document.querySelector('[data-notify]');
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
let copyTimer = 0;
let lastActivity = Date.now();
let polling = false;
let pollAgain = false;
let announced = seq;

const IDLE_MS = 10 * 60 * 1000;
const POLL_VISIBLE = 2000;
const POLL_HIDDEN = 30000;
const POLL_HIDDEN_NOTIFYING = 5000;

const NOTIFY_KEY = 'mailbox-notify';
const NOTIFY_LABELS = {
    on: 'Notifications on. Click to turn off',
    off: 'Turn on new-mail notifications',
    blocked: 'Notifications are blocked in this browser. Click for help',
    insecure: 'Notifications need HTTPS or localhost. Click for help',
};
/* 192px monochrome envelope tile (PNG; browsers do not render SVG notification icons reliably). */
const NOTIFY_ICON =
    'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAMAAAADABAMAAACg8nE0AAAAMFBMVEUKCgr///8AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAACO3ZjQAAAAEHRSTlP//wAAAAAAAAAAAAAAAAAADwvvhgAAAxNJREFUeNrtXM2a2yAMFNoeeqv8BjTv/0yt38DssRfTw+bPDgYJJLrrosNmkzgaZkZg4eSzu8BL/ILaSCRz+9fWGVoC/f6F/ejb8sO6p/82bd7+Dc2xvNMhg0Z5kllQPf8uD+rn32Z6AlDLD7CmAFZQjPkVYJ01AR7Z0ECgjR5oIdDzgNGGwGPEaETgPmQ0InAfM1oRuA0arQjcRo1mBK7DRjAONFPoKgzaKfQxcLQj8DHyHh6sdtnnPgxmu+xrFwarZfq500y2BZgt068nkAi8MYEzVNGXN3nEiBEjRowY8SnCHb9DkjyLGGASjjQGWdvitKRADX1ylA8AqMJOEgA4vXr5R70pVeUiNoBTnFNfv313mZJe+FnocDajuLCFR2LVQig4EEGBAtUCuHYChSqaBA7XlSk1ClQEcI0ClScaNRIoArhGAuWlghrfx7YaKdcZtpnoGlbT+8pFDIGWGgaxPEq3O1QoUZkC7Y4UAsSSz1OZQN7kkBfJQZlAHiDmi50YBApluuQouELby5oHOZ8ZDpcBMj5zHGbM5EOfWQ4zAA59Jh6B8lp04DPPYdZil/aZ5zALIOkz02Feb5rwmeswDyDhM7EJsLrrF5/ZDnPb973PbIe5ALtTj+MLxN2AhOficRICTICNzyQhwN1CPfkscViwR3v4LBKID3D3WeSwZJcZKkpUBBCzTzX2ySHzTAVgUzWLxU4/Vggku5QQ5ALJAGIFAdnFkCAnIAO4+ryA2eWcKBVIfL0oAIQg+sQ3GUBcAMZX7ucBEF08dTUMJJd/SQbtqPI33f+PybEuV/Jjb8lD/3yvAQifaB5ENYUOJKrSKEgAKhAWWZlGHYE6fJc5YsSIESNGjIDxw+4B0MUEfwYPvG36M0iEtgtRD4m8afYeDND0XNClTL1lcrScC/6W3BtO4j5LBRr2E2jXWuDLX5OGCM26I0w9GHR0aNXg4cGjekuKRj0qvv6jivDIhjZnZ59K601aCczc00Xl7jAI+gibPAjqCNssW2vx0p7/4jO3tgGYfoS24f+Ewt1/QPn2Qn8B62ClYI4jEOwAAAAASUVORK5CYII=';

/* Read after the constants above are initialised (module-level TDZ). */
let notifyPref = readNotifyPreference();

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

function anyDialogOpen() {
    return Boolean(helpDialog?.open || notifyDialog?.open);
}

function openDialog(dialog) {
    if (!dialog) {
        return;
    }
    if (typeof dialog.showModal === 'function') {
        if (!dialog.open) {
            dialog.showModal();
        }
    } else {
        dialog.setAttribute('open', '');
    }
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
    qsa('[data-copy]', detail).forEach((button) => {
        button.disabled = false;
    });
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

/* ---------------------------------------------------------- notifications */

function readNotifyPreference() {
    try {
        return localStorage.getItem(NOTIFY_KEY) === '1';
    } catch (error) {
        return false;
    }
}

function notifyPreferred() {
    return notifyPref;
}

function setNotifyPreference(on) {
    notifyPref = on;
    try {
        localStorage.setItem(NOTIFY_KEY, on ? '1' : '0');
    } catch (error) {
        /* storage disabled — the choice lasts for this page only */
    }
}

function notifyPermission() {
    try {
        return 'Notification' in window ? Notification.permission : 'unsupported';
    } catch (error) {
        return 'unsupported';
    }
}

/**
 * Secure context comes first on purpose: on plain http browsers still expose
 * Notification but report "denied" without ever asking, which would read as "blocked".
 */
function notifyState() {
    if (!window.isSecureContext) {
        return 'insecure';
    }
    const permission = notifyPermission();
    if (permission === 'unsupported') {
        return 'unsupported';
    }
    if (permission === 'denied') {
        return 'blocked';
    }

    return permission === 'granted' && notifyPreferred() ? 'on' : 'off';
}

function renderNotify() {
    const state = notifyState();
    if (!notifyButton) {
        return state;
    }
    notifyButton.hidden = state === 'unsupported';
    notifyButton.dataset.state = state;
    notifyButton.setAttribute('aria-pressed', state === 'on' ? 'true' : 'false');
    if (state === 'blocked' || state === 'insecure') {
        notifyButton.setAttribute('aria-haspopup', 'dialog');
        notifyButton.setAttribute('aria-controls', 'mailbox-notify');
    } else {
        notifyButton.removeAttribute('aria-haspopup');
        notifyButton.removeAttribute('aria-controls');
    }
    const label = NOTIFY_LABELS[state] || NOTIFY_LABELS.off;
    notifyButton.title = label;
    const text = qs('[data-notify-label]', notifyButton);
    if (text) {
        text.textContent = label;
    }

    return state;
}

function openNotifyHelp(panel) {
    if (!notifyDialog) {
        return;
    }
    qsa('[data-notify-panel]', notifyDialog).forEach((node) => {
        node.hidden = node.dataset.notifyPanel !== panel;
    });
    openDialog(notifyDialog);
}

function showNotification(title, options = {}, onclick = null) {
    try {
        const notification = new Notification(title, { icon: NOTIFY_ICON, tag: 'mailbox-new', renotify: true, ...options });
        notification.onclick = () => {
            window.focus();
            notification.close();
            if (onclick) {
                onclick();
            }
        };

        return notification;
    } catch (error) {
        return null;
    }
}

function requestNotifyPermission() {
    return new Promise((resolve) => {
        try {
            const result = Notification.requestPermission(resolve);
            if (result && typeof result.then === 'function') {
                result.then(resolve, () => resolve('default'));
            }
        } catch (error) {
            resolve('default');
        }
    });
}

/** Runs synchronously up to the permission request, which browsers gate on the click. */
async function toggleNotify() {
    const state = renderNotify();

    if (state === 'insecure' || state === 'blocked') {
        openNotifyHelp(state);

        return;
    }

    if (state === 'on') {
        setNotifyPreference(false);
        renderNotify();
        flash('Notifications off');
        pollSchedule();

        return;
    }

    const permission = notifyPermission() === 'granted' ? 'granted' : await requestNotifyPermission();

    if (permission === 'granted') {
        setNotifyPreference(true);
        renderNotify();
        flash('Notifications on');
        /* One confirmation banner every time it is switched on, so the user sees it work. */
        showNotification('Notifications are on', { body: 'New mail captured by Mailbox will show up here.' });
        pollSchedule();
    } else if (permission === 'denied') {
        renderNotify();
        openNotifyHelp('blocked');
    } else {
        renderNotify();
        flash('Notifications stay off until you allow them');
    }
}

function senderLine(from) {
    if (!from || !from.address) {
        return 'Unknown sender';
    }

    return 'From ' + (from.name ? from.name + ' <' + from.address + '>' : from.address);
}

/** Only messages above the announced high-water mark count, so a late response never repeats one. */
function announceArrival(recent, arrived) {
    const fresh = recent.filter((message) => Number(message.seq) > announced);
    if (fresh.length === 0) {
        return;
    }
    announced = Math.max(announced, ...fresh.map((message) => Number(message.seq)));

    if (notifyState() !== 'on') {
        return;
    }
    if (document.visibilityState === 'visible' && document.hasFocus()) {
        return; /* the toast and the list already say it */
    }

    const count = fresh.length < recent.length ? fresh.length : Math.max(arrived, fresh.length);

    if (count === 1) {
        const [message] = fresh;
        const href = base + '/messages/' + String(message.id || '');
        showNotification(message.subject || '(No subject)', { body: senderLine(message.from) }, () => {
            if (idFrom(href)) {
                openMessage(href);
            }
        });

        return;
    }

    showNotification(count + ' new messages', {
        body: fresh
            .slice(0, 3)
            .map((message) => message.subject || '(No subject)')
            .join('\n'),
    });
}

function watchNotifyPermission() {
    try {
        navigator.permissions
            ?.query({ name: 'notifications' })
            .then((status) => {
                status.onchange = () => renderNotify();
            })
            .catch(() => {
                /* Safari: no Permissions API for notifications; focus/visibility re-checks cover it */
            });
    } catch (error) {
        /* ignore */
    }
}

/* ---------------------------------------------------------- copy */

function copyText(value) {
    try {
        if (window.isSecureContext && navigator.clipboard?.writeText) {
            return navigator.clipboard.writeText(value).then(
                () => true,
                () => copyLegacy(value),
            );
        }
    } catch (error) {
        /* fall through to the legacy path */
    }

    return Promise.resolve(copyLegacy(value));
}

function copyLegacy(value) {
    try {
        const area = document.createElement('textarea');
        area.value = value;
        area.setAttribute('readonly', '');
        area.setAttribute('aria-hidden', 'true');
        area.className = 'mb-clipboard';
        body.appendChild(area);
        area.focus({ preventScroll: true });
        area.select();
        area.setSelectionRange(0, value.length);
        const copied = document.execCommand('copy');
        area.remove();

        return copied;
    } catch (error) {
        return false;
    }
}

function copyFrom(button) {
    const value = button.dataset.copy || '';
    copyText(value).then((copied) => {
        button.focus({ preventScroll: true });
        if (!copied) {
            flash('Could not copy. The full value is in the tooltip.');

            return;
        }
        button.setAttribute('data-copied', '');
        window.clearTimeout(copyTimer);
        copyTimer = window.setTimeout(() => button.removeAttribute('data-copied'), 1600);
        flash('Message-ID copied');
    });
}

/* ---------------------------------------------------------- polling */

function pollSchedule() {
    window.clearTimeout(pollTimer);
    pollTimer = 0;
    const notifying = notifyState() === 'on';
    if (!notifying && Date.now() - lastActivity > IDLE_MS) {
        return;
    }
    let delay = POLL_VISIBLE;
    if (document.visibilityState !== 'visible') {
        delay = notifying ? POLL_HIDDEN_NOTIFYING : POLL_HIDDEN;
    }
    pollTimer = window.setTimeout(() => {
        pollTimer = 0;
        pollTick();
    }, delay);
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
    if (polling) {
        pollAgain = true; /* one request at a time keeps responses in order */

        return;
    }
    polling = true;
    try {
        const response = await request(base + '/api/status?since=' + encodeURIComponent(String(seq)), {
            headers: etag ? { 'If-None-Match': etag } : {},
        });

        if (response.status === 200) {
            etag = response.headers.get('ETag') || etag;
            const status = await response.json();
            const next = Number(status.seq) || 0;
            setUnread(Number(status.unread) || 0);

            if (next !== seq) {
                const recent = Array.isArray(status.recent) ? status.recent : [];
                const arrived = Number(status.arrived) || recent.length;
                seq = next;
                body.dataset.mailboxSeq = String(seq);
                const fresh = await refreshList();
                const count = Math.max(fresh, arrived); /* the server count includes mail the current filter hides */
                if (count > 0) {
                    flash(count + ' new message' + (count === 1 ? '' : 's'));
                }
                announceArrival(recent, arrived);
            }
        }
    } catch (error) {
        /* transient failure — retry on the next tick */
    }
    polling = false;
    if (pollAgain) {
        pollAgain = false;
        pollSoon();

        return;
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
    if (closeButton) {
        event.preventDefault();
        closeButton.closest('dialog')?.close();

        return;
    }

    const notifyToggle = event.target.closest?.('[data-notify]');
    if (notifyToggle) {
        event.preventDefault();
        toggleNotify();

        return;
    }

    const copyButton = event.target.closest?.('[data-copy]');
    if (copyButton) {
        event.preventDefault();
        copyFrom(copyButton);

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
        if (anyDialogOpen()) {
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

    if (event.metaKey || event.ctrlKey || event.altKey || isTyping(event.target) || anyDialogOpen()) {
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
    openDialog(helpDialog);
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
    renderNotify();
    if (document.visibilityState === 'visible') {
        wake();
        pollSoon();
    } else {
        pollSchedule();
    }
});

window.addEventListener('focus', () => {
    renderNotify();
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

[helpDialog, notifyDialog].forEach((dialog) => {
    if (!dialog) {
        return;
    }
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) {
            dialog.close();
        }
    });
});

/* ---------------------------------------------------------- boot */

bootTheme();
root.dataset.js = '';
renderNotify();
watchNotifyPermission();
initDetail();
markSelection();
if (currentId) {
    setPane('detail');
}
if (list) {
    pollSchedule();
}
