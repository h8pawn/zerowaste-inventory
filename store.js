// Customer storefront: product filters, pickup basket and reservations.
(function () {
    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
    const peso = (n) => '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const MAX_QTY = 50;

    /* ---------- Theme (shared with the staff pages) ---------- */
    const root = document.documentElement;
    const currentTheme = () => root.getAttribute('data-theme')
        || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    const paintThemeIcons = () => {
        const dark = currentTheme() === 'dark';
        $$('[data-theme-toggle]').forEach((btn) => {
            btn.querySelector('i').className = dark ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
            btn.title = dark ? 'Light mode' : 'Dark mode';
        });
    };
    $$('[data-theme-toggle]').forEach((btn) => btn.addEventListener('click', () => {
        const next = currentTheme() === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        try { localStorage.setItem('theme', next); } catch (e) { /* storage unavailable */ }
        paintThemeIcons();
    }));
    paintThemeIcons();

    /* ---------- Order history filter (tracking page) ---------- */
    $$('[data-history-filter]').forEach((btn) => btn.addEventListener('click', () => {
        const group = btn.dataset.historyFilter;
        $$('[data-history-filter]').forEach((b) => b.classList.toggle('is-active', b === btn));
        $$('[data-history-status]').forEach((row) => { row.hidden = group !== '' && row.dataset.historyStatus !== group; });
    }));

    // Product data comes from the server-rendered cards, so prices and stock are always current.
    const catalog = new Map();
    $$('.product[data-id]').forEach((el) => {
        if (catalog.has(el.dataset.id)) return;
        catalog.set(el.dataset.id, {
            id: el.dataset.id,
            name: el.dataset.name,
            emoji: el.dataset.emoji || '',
            unit: el.dataset.unit,
            price: Number(el.dataset.price),
            regular: Number(el.dataset.regular),
            discount: Number(el.dataset.discount),
            available: Number(el.dataset.available),
        });
    });

    /* ---------- Shop filters ---------- */
    const grid = $('#productGrid');
    if (grid) {
        const cards = $$('.product', grid);
        const search = $('#shopSearch');
        const sort = $('#shopSort');
        const inStock = $('#inStockOnly');
        const empty = $('#shopEmpty');
        let filter = '';

        const apply = () => {
            const q = search.value.trim().toLowerCase();
            let shown = 0;
            cards.forEach((card) => {
                const d = card.dataset;
                const matchesFilter = !filter
                    || (filter === '__deal' && Number(d.discount) > 0)
                    || (filter === '__new' && d.new === '1')
                    || d.category === filter;
                const matchesSearch = !q || d.name.toLowerCase().includes(q) || d.category.toLowerCase().includes(q);
                const matchesStock = !inStock.checked || Number(d.available) > 0;
                card.hidden = !(matchesFilter && matchesSearch && matchesStock);
                if (!card.hidden) shown++;
            });
            empty.hidden = shown > 0;

            const by = sort.value;
            cards.slice().sort((a, b) => {
                if (by === 'name') return a.dataset.name.localeCompare(b.dataset.name);
                if (by === 'price-asc') return a.dataset.price - b.dataset.price;
                if (by === 'price-desc') return b.dataset.price - a.dataset.price;
                // Featured: in stock first, then bigger deals, then catalog order.
                return (Number(b.dataset.available) > 0) - (Number(a.dataset.available) > 0)
                    || b.dataset.discount - a.dataset.discount
                    || a.dataset.order - b.dataset.order;
            }).forEach((card) => grid.appendChild(card));
        };

        $$('#shopChips .chip').forEach((chip) => chip.addEventListener('click', () => {
            $$('#shopChips .chip').forEach((c) => c.classList.toggle('is-active', c === chip));
            filter = chip.dataset.filter;
            apply();
        }));
        search.addEventListener('input', apply);
        sort.addEventListener('change', apply);
        inStock.addEventListener('change', apply);
        apply();
    }

    /* ---------- Basket ---------- */
    const basket = $('#basket');
    if (!basket) return; // reservations are switched off

    const KEY = 'lisa_basket_v1';
    const PROFILE_KEY = 'lisa_customer_v1';
    const token = $('meta[name="csrf-token"]')?.content || '';
    let cart = {};
    let lineErrors = {};

    try {
        const saved = JSON.parse(localStorage.getItem(KEY) || '{}');
        if (saved && typeof saved === 'object') cart = saved;
    } catch (e) { cart = {}; }

    const save = () => { try { localStorage.setItem(KEY, JSON.stringify(cart)); } catch (e) { /* storage unavailable */ } };

    // Drop items that are gone and clamp quantities to what's in stock now.
    Object.keys(cart).forEach((id) => {
        const item = catalog.get(id);
        const qty = Math.floor(Number(cart[id]));
        if (!item || item.available <= 0 || !(qty > 0)) delete cart[id];
        else cart[id] = Math.min(qty, item.available, MAX_QTY);
    });
    save();

    const totalUnits = () => Object.values(cart).reduce((sum, q) => sum + q, 0);

    function updateCount(bump) {
        $$('[data-basket-count]').forEach((badge) => {
            const n = totalUnits();
            badge.textContent = n;
            badge.hidden = n === 0;
            if (bump) {
                badge.classList.remove('bump');
                void badge.offsetWidth;
                badge.classList.add('bump');
            }
        });
    }

    let toastTimer = null;
    function toast(message, withBasketLink) {
        const t = $('#toast');
        t.innerHTML = `<span>${esc(message)}</span>` + (withBasketLink ? '<button type="button" data-basket-open>View basket</button>' : '');
        t.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { t.hidden = true; }, 3200);
    }

    function setQty(id, qty) {
        const item = catalog.get(id);
        if (!item) return;
        const next = Math.max(0, Math.min(Math.floor(qty) || 0, item.available, MAX_QTY));
        if (next === 0) delete cart[id];
        else cart[id] = next;
        delete lineErrors[id];
        save();
        updateCount();
        renderBasket();
    }

    // Product card steppers and "Add" buttons
    document.addEventListener('click', (e) => {
        const step = e.target.closest('.product [data-step]');
        if (step) {
            const input = step.parentElement.querySelector('input');
            const max = Number(input.max) || MAX_QTY;
            input.value = Math.min(max, Math.max(1, (Number(input.value) || 1) + Number(step.dataset.step)));
            return;
        }
        const add = e.target.closest('.product [data-add]');
        if (!add) return;
        const card = add.closest('.product');
        const item = catalog.get(card.dataset.id);
        const input = card.querySelector('.qty input');
        const wanted = Math.max(1, Math.floor(Number(input.value) || 1));
        const before = cart[item.id] || 0;
        const after = Math.min(item.available, MAX_QTY, before + wanted);
        cart[item.id] = after;
        delete lineErrors[item.id];
        save();
        updateCount(true);
        input.value = 1;
        add.classList.add('added');
        add.innerHTML = '<i class="fa-solid fa-check"></i> Added';
        setTimeout(() => {
            add.classList.remove('added');
            add.innerHTML = '<i class="fa-solid fa-basket-shopping"></i> Add';
        }, 1200);
        toast(after > before ? `Added ${after - before} × ${item.name}` : `You already have all ${item.available} available`, true);
        renderBasket();
    });

    /* ---------- Drawer ---------- */
    let lastFocus = null;
    function openBasket() {
        lastFocus = document.activeElement;
        $('#basketView').hidden = false;
        $('#basketDone').hidden = true;
        basket.hidden = false;
        document.body.style.overflow = 'hidden';
        renderBasket();
        setTimeout(() => $('.icon-close', basket).focus(), 30);
    }
    function closeBasket() {
        basket.hidden = true;
        document.body.style.overflow = '';
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }
    document.addEventListener('click', (e) => {
        if (e.target.closest('[data-basket-open]')) {
            e.preventDefault();
            $('#toast').hidden = true;
            openBasket();
        } else if (e.target.closest('[data-basket-close]')) {
            closeBasket();
        }
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !basket.hidden) closeBasket();
    });

    function renderBasket() {
        const ids = Object.keys(cart);
        $('#basketEmpty').hidden = ids.length > 0;
        $('#basketCheckout').hidden = ids.length === 0;

        let regular = 0;
        let total = 0;
        $('#basketList').innerHTML = ids.map((id) => {
            const item = catalog.get(id);
            const qty = cart[id];
            regular += item.regular * qty;
            total += item.price * qty;
            return `<li data-id="${esc(id)}" class="${lineErrors[id] ? 'has-error' : ''}">
                <div>
                    <div class="bl-name">${item.emoji ? `<span class="bl-emoji" aria-hidden="true">${esc(item.emoji)}</span>` : ''}${esc(item.name)}</div>
                    <div class="bl-meta">${peso(item.price)} / ${esc(item.unit)}${item.discount ? ` · <span class="deal">${item.discount}% off</span>` : ''}</div>
                </div>
                <div class="bl-total">${peso(item.price * qty)}</div>
                <div class="bl-controls">
                    <div class="qty">
                        <button type="button" data-bstep="-1" aria-label="Less">−</button>
                        <input type="number" value="${qty}" min="1" max="${Math.min(item.available, MAX_QTY)}" data-bqty aria-label="Quantity of ${esc(item.name)}">
                        <button type="button" data-bstep="1" aria-label="More">+</button>
                    </div>
                    <button type="button" class="bl-remove" data-bremove>Remove</button>
                </div>
                ${lineErrors[id] ? `<div class="bl-error"><i class="fa-solid fa-circle-exclamation"></i> ${esc(lineErrors[id])}</div>` : ''}
            </li>`;
        }).join('');

        $('#sumRegular').textContent = peso(regular);
        $('#sumSave').textContent = '−' + peso(regular - total);
        $('#sumSaveRow').hidden = regular - total < 0.005;
        $('#sumTotal').textContent = peso(total);
    }

    const list = $('#basketList');
    list.addEventListener('click', (e) => {
        const li = e.target.closest('li[data-id]');
        if (!li) return;
        const id = li.dataset.id;
        if (e.target.closest('[data-bremove]')) setQty(id, 0);
        const step = e.target.closest('[data-bstep]');
        if (step) setQty(id, (cart[id] || 0) + Number(step.dataset.bstep));
    });
    list.addEventListener('change', (e) => {
        if (!e.target.matches('[data-bqty]')) return;
        setQty(e.target.closest('li').dataset.id, Number(e.target.value));
    });

    /* ---------- Pickup form ---------- */
    const form = $('#reserveForm');
    if (form) {
        const slots = JSON.parse($('#pickupSlots')?.textContent || '{}');
        const date = $('#fDate');
        const time = $('#fTime');
        const fillTimes = () => {
            const day = slots[date.value];
            time.innerHTML = day ? Object.entries(day.times).map(([v, label]) => `<option value="${esc(v)}">${esc(label)}</option>`).join('') : '';
        };
        date.innerHTML = Object.entries(slots).map(([v, day]) => `<option value="${esc(v)}">${esc(day.label)}</option>`).join('');
        fillTimes();
        date.addEventListener('change', fillTimes);

        // Remember contact details on this device for next time.
        try {
            const profile = JSON.parse(localStorage.getItem(PROFILE_KEY) || '{}');
            ['name', 'phone', 'email'].forEach((k) => { if (profile[k]) form.elements[k].value = profile[k]; });
            const savedPay = form.querySelector(`input[name="payment_method"][value="${CSS.escape(profile.payment || '')}"]`);
            if (savedPay) savedPay.checked = true;
        } catch (e) { /* storage unavailable */ }

        // What the chosen payment method means for the customer (e.g. where to send an e-wallet payment).
        const payNotes = JSON.parse($('#paymentNotes')?.textContent || '{}');
        const showPayNote = () => {
            const chosen = form.querySelector('input[name="payment_method"]:checked');
            $('#payNote').textContent = chosen ? payNotes[chosen.value] || '' : '';
        };
        form.querySelectorAll('input[name="payment_method"]').forEach((r) => r.addEventListener('change', showPayNote));
        showPayNote();

        const error = $('#formError');
        const button = $('#reserveBtn');
        const showError = (msg) => { error.textContent = msg; error.hidden = false; };

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            error.hidden = true;
            const f = form.elements;
            if (f.name.value.trim().length < 2) return showError('Please enter your name.');
            const digits = f.phone.value.replace(/\D/g, '');
            if (!/^(09\d{9}|639\d{9}|9\d{9})$/.test(digits)) return showError('Please enter a mobile number like 0917 123 4567.');
            if (!Object.keys(cart).length) return showError('Your basket is empty.');
            const payment = form.querySelector('input[name="payment_method"]:checked');
            if (!payment) return showError('Please choose how you will pay.');

            const body = new URLSearchParams();
            body.set('action', 'reserve');
            body.set('csrf_token', token);
            Object.entries(cart).forEach(([id, qty]) => body.set(`items[${id}]`, qty));
            // Radio buttons: only send the one that is selected.
            Array.from(f).forEach((el) => { if (el.name && !(el.type === 'radio' && !el.checked)) body.set(el.name, el.value); });

            const label = button.innerHTML;
            button.disabled = true;
            button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Reserving…';
            try {
                const res = await fetch('store_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch', Accept: 'application/json' },
                    body,
                });
                const data = await res.json();
                if (!data.success) {
                    lineErrors = data.item_errors || {};
                    renderBasket();
                    showError(data.message || 'Something went wrong. Please try again.');
                    return;
                }
                try {
                    localStorage.setItem(PROFILE_KEY, JSON.stringify({ name: f.name.value.trim(), phone: f.phone.value.trim(), email: f.email.value.trim(), payment: payment.value }));
                } catch (err) { /* storage unavailable */ }
                cart = {};
                lineErrors = {};
                save();
                updateCount();
                f.notes.value = '';
                showDone(data.order);
            } catch (err) {
                showError('Network error. Please check your connection and try again.');
            } finally {
                button.disabled = false;
                button.innerHTML = label;
            }
        });
    }

    function showDone(order) {
        $('#basketView').hidden = true;
        $('#basketDone').hidden = false;
        $('#doneCode').textContent = order.code;
        $('#donePickup').textContent = order.pickup;
        $('#doneTotal').textContent = peso(order.total);
        $('#donePayment').textContent = order.payment || '';
        $('#donePayNote').textContent = order.payment_note || '';
        $('#doneSave').textContent = peso(order.savings);
        $('#doneSaveRow').hidden = !(order.savings > 0);
        $('#doneTrack').href = order.track_url;
    }

    updateCount();
    renderBasket();
})();
