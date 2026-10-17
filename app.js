// Shared UI behaviour for every signed-in page.
(function () {
    const root = document.documentElement;

    /* ---------- Theme ---------- */
    function currentTheme() {
        const set = root.getAttribute('data-theme');
        if (set) return set;
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    function paintThemeIcons() {
        const dark = currentTheme() === 'dark';
        document.querySelectorAll('[data-theme-toggle] i').forEach((i) => {
            i.className = dark ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
        });
    }
    document.querySelectorAll('[data-theme-toggle]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const next = currentTheme() === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-theme', next);
            try { localStorage.setItem('theme', next); } catch (e) {}
            paintThemeIcons();
        });
    });
    paintThemeIcons();

    /* ---------- Delivery notifications (bell) ---------- */
    // The badge counts notifications this browser hasn't seen; opening the bell marks them as seen.
    const notifBtn = document.querySelector('[data-notif-toggle]');
    const notifPanel = document.querySelector('[data-notif-panel]');
    if (notifBtn && notifPanel) {
        const SEEN_KEY = 'notif_seen_v1';
        const items = Array.from(notifPanel.querySelectorAll('[data-notif-key]'));
        let seen = [];
        try { seen = JSON.parse(localStorage.getItem(SEEN_KEY) || '[]'); } catch (e) { seen = []; }
        if (!Array.isArray(seen)) seen = [];

        const badge = notifBtn.querySelector('[data-notif-count]');
        const unseen = items.filter((li) => !seen.includes(li.dataset.notifKey));
        unseen.forEach((li) => li.classList.add('is-new'));
        badge.textContent = unseen.length > 9 ? '9+' : unseen.length;
        badge.hidden = unseen.length === 0;
        notifBtn.classList.toggle('has-new', unseen.length > 0);

        const setOpen = (open) => {
            notifPanel.hidden = !open;
            notifBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open && unseen.length) {
                const keys = items.map((li) => li.dataset.notifKey);
                try { localStorage.setItem(SEEN_KEY, JSON.stringify([...new Set([...keys, ...seen])].slice(0, 300))); } catch (e) {}
                badge.hidden = true;
                notifBtn.classList.remove('has-new');
            }
            if (!open) items.forEach((li) => li.classList.remove('is-new'));
        };
        notifBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            setOpen(notifPanel.hidden);
        });
        document.addEventListener('click', (e) => {
            if (!notifPanel.hidden && !e.target.closest('.notif-wrap')) setOpen(false);
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !notifPanel.hidden) { setOpen(false); notifBtn.focus(); }
        });
    }

    /* ---------- Sidebar (mobile) ---------- */
    document.querySelectorAll('[data-sidebar-toggle]').forEach((el) => {
        el.addEventListener('click', () => document.body.classList.toggle('sidebar-open'));
    });

    /* ---------- Modals ---------- */
    window.openModal = function (id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.add('open');
        const focusTarget = modal.querySelector('[autofocus]') || modal.querySelector('input:not([type=hidden]), select, textarea');
        setTimeout(() => focusTarget && focusTarget.focus(), 60);
    };
    window.closeModal = function (id) {
        const modal = document.getElementById(id);
        if (modal) modal.classList.remove('open');
    };
    window.anyModalOpen = function () {
        return !!document.querySelector('.modal.open');
    };
    document.addEventListener('click', (e) => {
        const opener = e.target.closest('[data-open-modal]');
        if (opener) {
            e.preventDefault();
            openModal(opener.getAttribute('data-open-modal'));
            return;
        }
        const closer = e.target.closest('[data-close-modal]');
        if (closer) {
            closer.closest('.modal')?.classList.remove('open');
            return;
        }
        if (e.target.classList.contains('modal')) {
            e.target.classList.remove('open');
        }
    });

    /* ---------- Kebab menus ---------- */
    function closeMenus(except) {
        document.querySelectorAll('.menu.open').forEach((m) => { if (m !== except) m.classList.remove('open'); });
    }
    document.addEventListener('click', (e) => {
        const trigger = e.target.closest('[data-menu]');
        if (trigger) {
            e.stopPropagation();
            const menu = trigger.parentElement.querySelector('.menu');
            closeMenus(menu);
            menu.classList.toggle('open');
            // Open upward when there is no room below.
            const rect = trigger.getBoundingClientRect();
            menu.classList.toggle('up', window.innerHeight - rect.bottom < menu.offsetHeight + 20);
            return;
        }
        if (!e.target.closest('.menu')) closeMenus();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        closeMenus();
        document.querySelectorAll('.modal.open').forEach((m) => m.classList.remove('open'));
        document.body.classList.remove('sidebar-open');
    });

    /* ---------- Alerts ---------- */
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-dismiss]');
        if (btn) btn.closest('.alert')?.remove();
    });
    document.querySelectorAll('.main > .alert-success').forEach((el) => {
        // Keep messages that offer a follow-up action (e.g. "Print label").
        if (!el.querySelector('a')) setTimeout(() => el.remove(), 6000);
    });

    /* ---------- Confirm before submit (on the form or the clicked button) ---------- */
    document.addEventListener('submit', (e) => {
        const msg = e.submitter?.getAttribute('data-confirm') || e.target.getAttribute('data-confirm');
        if (msg && !window.confirm(msg)) e.preventDefault();
    });

    /* ---------- Chart tooltips: any element with data-tip ---------- */
    let tip = null;
    function showTip(target, x, y) {
        if (!tip) {
            tip = document.createElement('div');
            tip.className = 'chart-tip';
            tip.setAttribute('role', 'tooltip');
            document.body.appendChild(tip);
        }
        tip.textContent = target.getAttribute('data-tip');
        tip.style.display = 'block';
        const pad = 12;
        const w = tip.offsetWidth;
        const h = tip.offsetHeight;
        let left = x + pad;
        let top = y - h - pad;
        if (left + w > window.innerWidth - 8) left = x - w - pad;
        if (top < 8) top = y + pad;
        tip.style.left = `${Math.max(8, left)}px`;
        tip.style.top = `${top}px`;
    }
    function hideTip() { if (tip) tip.style.display = 'none'; }
    document.addEventListener('mousemove', (e) => {
        const target = e.target.closest?.('[data-tip]');
        if (target) showTip(target, e.clientX, e.clientY); else hideTip();
    });
    document.addEventListener('focusin', (e) => {
        const target = e.target.closest?.('[data-tip]');
        if (!target) return;
        const r = target.getBoundingClientRect();
        showTip(target, r.left + r.width / 2, r.top);
    });
    document.addEventListener('focusout', hideTip);
    document.addEventListener('scroll', hideTip, true);

    /* ---------- Password visibility ---------- */
    document.querySelectorAll('[data-pw-toggle]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const input = btn.parentElement.querySelector('input');
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.querySelector('i').className = show ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
        });
    });

    /* ---------- AJAX helper (adds CSRF token) ---------- */
    window.postForm = function (url, data) {
        const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const body = new URLSearchParams(data);
        body.set('csrf_token', token);
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
            body,
        }).then((r) => r.json());
    };

    window.escapeHtml = function (value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;').replaceAll("'", '&#039;');
    };

    // Local calendar date (toISOString would shift to UTC and can land on the wrong day).
    window.localDatePlusDays = function (days) {
        const d = new Date();
        d.setDate(d.getDate() + Number(days || 0));
        const pad = (n) => String(n).padStart(2, '0');
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    };
})();
