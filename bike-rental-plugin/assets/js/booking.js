/* Public selection only. The server owns schedules, prices, quantity limits and holds. */
(() => {
    'use strict';
    const sessions = new Map();
    document.querySelectorAll('.brp-booking').forEach((root) => {
        if (root.dataset.ready) return;
        root.dataset.ready = '1';
        const form = root.querySelector('form');
        const fields = form.elements;
        const status = root.querySelector('.brp-status');
        const summary = root.querySelector('.brp-summary');
        const submit = root.querySelector('.brp-submit');
        const result = root.querySelector('.brp-result');
        const restart = root.querySelector('.brp-restart');
        const storageKey = 'brp-booking:' + root.dataset.api + location.pathname;
        let packages = [], selection = null, generation = 0, requestKey = '', timer = null;
        const message = (text) => { status.textContent = text; };
        const store = (value) => { try { value ? sessionStorage.setItem(storageKey, value) : sessionStorage.removeItem(storageKey); } catch (_) { /* In-memory retries remain available. */ } };
        const api = async (route, data = {}, mutation = false, token = '') => {
            const url = new URL(root.dataset.api);
            if (url.searchParams.has('rest_route')) url.searchParams.set('rest_route', url.searchParams.get('rest_route') + route);
            else url.pathname += route;
            const options = { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } };
            if (mutation) {
                options.method = 'POST';
                options.headers['Content-Type'] = 'application/json';
                options.headers['X-BRP-Request'] = '1';
                if (token) options.headers['X-BRP-Token'] = token;
                options.body = JSON.stringify(data);
            } else Object.entries(data).forEach(([key, value]) => url.searchParams.set(key, value));
            let response, body;
            try { response = await fetch(url, options); body = await response.json(); }
            catch (_) { throw new Error('Unable to connect. Please try again; a repeated reservation request will not reserve twice.'); }
            if (!response.ok || body.valid === false) throw new Error(body.message || 'Unable to complete this request. Please try again.');
            return body;
        };
        const session = () => {
            if (!sessions.has(root.dataset.api)) sessions.set(root.dataset.api, api('session', {}, true).catch((error) => { sessions.delete(root.dataset.api); throw error; }));
            return sessions.get(root.dataset.api);
        };
        const line = (target, label, value) => {
            const p = document.createElement('p');
            const strong = document.createElement('strong'); strong.textContent = label + ': ';
            p.append(strong, document.createTextNode(String(value))); target.append(p);
        };
        const price = (target, item) => {
            const p = document.createElement('p'); p.append(document.createTextNode('Price per bike: '));
            const span = document.createElement('span'); span.innerHTML = item.price_html; p.append(span); target.append(p);
        };
        const timeLabel = (value) => {
            const [hour, minute] = value.split(':');
            return `${Number(hour) % 12 || 12}:${minute} ${Number(hour) < 12 ? 'AM' : 'PM'}`;
        };
        const dateLabel = (value) => value.replace('T', ' ');
        const resetSelection = () => {
            generation++; selection = null; submit.disabled = true; fields.quantity.disabled = true;
            root.removeAttribute('aria-busy');
            summary.replaceChildren(); requestKey = ''; store('');
        };
        const selectionInput = () => ({ package_id: fields.package_id.value, date: fields.date.value, time: fields.time.value });
        const showHold = (hold) => {
            clearInterval(timer); form.hidden = true; result.hidden = false; restart.hidden = hold.reserved;
            const receipt = root.querySelector('.brp-receipt'); receipt.replaceChildren();
            line(receipt, 'Reference', hold.reference); line(receipt, 'Package', hold.package.name);
            line(receipt, 'Bikes', hold.quantity); line(receipt, 'Start', dateLabel(hold.rental_start));
            line(receipt, 'Pickup / end', dateLabel(hold.rental_end)); line(receipt, 'Timezone', hold.timezone); price(receipt, hold.package);
            line(receipt, 'Hold expires', new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(hold.expires_at)) + ' (your device time)');
            message(hold.message);
            const remaining = new Date(hold.expires_at).getTime() - new Date(hold.server_time).getTime();
            const began = performance.now(); const expiry = root.querySelector('.brp-expiry');
            expiry.setAttribute('aria-live', 'off');
            const tick = () => {
                const seconds = Math.max(0, Math.ceil((remaining - (performance.now() - began)) / 1000));
                if (!hold.reserved || !seconds) {
                    clearInterval(timer); restart.hidden = false;
                    expiry.textContent = 'Your temporary reservation has expired or is no longer held.';
                    message('Bikes are no longer reserved by this form. Start over to check availability.');
                } else expiry.textContent = 'Temporary hold remaining: ' + Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
            };
            timer = setInterval(tick, 1000); tick(); result.focus();
        };
        const loadTimes = async () => {
            resetSelection(); fields.time.disabled = true; fields.time.replaceChildren(new Option('Choose a date first', ''));
            if (!fields.package_id.value || !fields.date.value || !fields.date.checkValidity()) return;
            const current = generation; message('Checking available start times…'); root.setAttribute('aria-busy', 'true');
            try {
                const data = await api('times', { package_id: fields.package_id.value, date: fields.date.value });
                if (current !== generation) return;
                fields.time.replaceChildren(new Option('Select a start time', ''));
                data.times.forEach((item) => fields.time.add(new Option(timeLabel(item.time), item.time)));
                fields.time.disabled = !data.times.length; message(data.message);
            } catch (error) { if (current === generation) message(error.message); }
            finally { if (current === generation) root.removeAttribute('aria-busy'); }
        };
        fields.package_id.addEventListener('change', () => {
            const target = root.querySelector('.brp-package'); target.replaceChildren();
            const item = packages.find((p) => String(p.product_id) === fields.package_id.value);
            if (item) { line(target, 'Duration', item.duration_amount + (item.duration_type === 'hours' ? ' hours' : ' calendar days')); price(target, item); if (item.promotional_label) line(target, 'Offer', item.promotional_label); }
            fields.date.disabled = !item; loadTimes();
        });
        fields.date.addEventListener('change', loadTimes);
        fields.time.addEventListener('change', async () => {
            resetSelection(); if (!fields.time.value) return;
            const current = generation; message('Checking availability…'); root.setAttribute('aria-busy', 'true');
            try {
                const data = await api('availability', selectionInput());
                if (current !== generation) return;
                selection = data;
                fields.quantity.max = String(data.available_quantity);
                fields.quantity.value = String(Math.max(1, Math.min(Number(fields.quantity.value), data.available_quantity)));
                fields.quantity.disabled = !data.available_quantity; submit.disabled = !data.available_quantity;
                line(summary, 'Start', dateLabel(data.rental_start)); line(summary, 'Pickup / end', dateLabel(data.rental_end));
                line(summary, 'Timezone', data.timezone); price(summary, data.package); message(data.message);
            } catch (error) { if (current === generation) message(error.message); }
            finally { if (current === generation) root.removeAttribute('aria-busy'); }
        });
        fields.quantity.addEventListener('input', () => { requestKey = ''; store(''); submit.disabled = !selection || !fields.quantity.checkValidity(); });
        form.addEventListener('submit', async (event) => {
            event.preventDefault(); if (!selection || !form.reportValidity()) return;
            if (!requestKey) { const bytes = crypto.getRandomValues(new Uint8Array(24)); requestKey = Array.from(bytes, (v) => v.toString(16).padStart(2, '0')).join(''); }
            const input = { ...selectionInput(), quantity: fields.quantity.value, request_key: requestKey };
            store(requestKey); submit.disabled = true; fields[0].disabled = true; message('Reserving your bikes…');
            try { const identity = await session(); showHold(await api('holds', input, true, identity.token)); }
            catch (error) { message(error.message); }
            finally { fields[0].disabled = false; submit.disabled = false; }
        });
        restart.addEventListener('click', () => {
            clearInterval(timer); result.hidden = true; form.hidden = false; resetSelection(); form.reset();
            fields.time.disabled = true; fields.date.disabled = true; fields.package_id.focus();
            root.querySelector('.brp-package').replaceChildren(); message('Choose a package to check availability again.');
        });
        (async () => {
            try {
                const data = await api('packages'); packages = data.packages;
                fields.package_id.replaceChildren(new Option(packages.length ? 'Select a rental package' : 'No rental packages available', ''));
                packages.forEach((item) => fields.package_id.add(new Option(item.name, item.product_id)));
                fields.package_id.disabled = !packages.length; fields.date.min = data.min_date; fields.date.max = data.max_date;
                message(packages.length ? 'Choose a package to get started.' : 'No rental packages are currently available.');
                let previous = ''; try { previous = sessionStorage.getItem(storageKey) || ''; } catch (_) { /* Optional reload recovery. */ }
                if (previous) { requestKey = previous; const identity = await session(); showHold(await api('hold-status', { request_key: previous }, true, identity.token)); }
            } catch (error) { message(error.message); }
        })();
    });
})();
