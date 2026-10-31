/*
 * Browser half of the diagnostics log. Loaded only while diagnostics.enabled is on.
 * Records what the user did on the screen and what went wrong: clicks, field changes, JavaScript
 * errors, console warnings, failed files and fetch() calls. Other scripts add their own events
 * with window.lpgDiag(name, data). Password-like fields are never recorded.
 */
(function () {
    'use strict';

    var config = window.lpgDiagConfig;
    if (!config || !config.url) { return; }

    var SECRET = /pass|pwd|secret|token|csrf|key/i;
    var MAX_TEXT = 2000;
    var queue = [];

    function text(value) {
        var out;
        try { out = typeof value === 'string' ? value : (value instanceof Error ? (value.stack || value.message) : JSON.stringify(value)); } catch (e) { out = String(value); }
        out = String(out);
        return out.length > MAX_TEXT ? out.slice(0, MAX_TEXT) + '...' : out;
    }

    function page() {
        var now = new Date();
        return {
            url: location.pathname + location.search,
            server_request: config.rid,
            browser_time: now.toString(),
            utc_time: now.toISOString(),
            utc_offset_minutes: -now.getTimezoneOffset(),
            time_zone: (window.Intl && Intl.DateTimeFormat().resolvedOptions().timeZone) || ''
        };
    }

    function flush() {
        if (!queue.length) { return; }
        var body = JSON.stringify({ page: page(), events: queue.splice(0, queue.length) });
        try {
            navigator.sendBeacon(config.url, new Blob([body], { type: 'application/json' }));
        } catch (e) { /* Logging must never disturb the screen. */ }
    }

    function record(name, data) {
        queue.push({ name: name, at: new Date().toISOString(), data: data === undefined ? null : data });
        if (queue.length >= 50) { flush(); }
    }

    function describe(el) {
        return {
            tag: el.tagName ? el.tagName.toLowerCase() : '',
            id: el.id || '',
            name: el.getAttribute ? (el.getAttribute('name') || '') : '',
            classes: typeof el.className === 'string' ? el.className.slice(0, 120) : '',
            label: (el.innerText || '').toString().trim().slice(0, 80)
        };
    }

    function isSecret(el) {
        return el.type === 'password' || SECRET.test(el.name || '') || SECRET.test(el.id || '');
    }

    window.lpgDiag = function (name, data) {
        try { record(name, data); } catch (e) { /* See above. */ }
    };

    window.addEventListener('error', function (event) {
        var target = event.target;
        if (target && target !== window && (target.src || target.href)) {
            record('resource_failed', { url: target.src || target.href, tag: target.tagName });
            return;
        }
        record('js_error', { message: event.message, file: event.filename, line: event.lineno, column: event.colno, stack: event.error ? text(event.error) : '' });
    }, true);

    window.addEventListener('unhandledrejection', function (event) {
        record('js_rejection', { reason: text(event.reason) });
    });

    ['error', 'warn'].forEach(function (level) {
        var original = console[level];
        console[level] = function () {
            record('console_' + level, { message: Array.prototype.map.call(arguments, text).join(' ') });
            return original.apply(console, arguments);
        };
    });

    document.addEventListener('click', function (event) {
        var el = event.target.closest ? event.target.closest('button,a,[role="button"],[role="tab"],input[type="submit"],input[type="checkbox"],input[type="radio"]') : null;
        if (el) { record('click', describe(el)); }
    }, true);

    document.addEventListener('change', function (event) {
        var el = event.target;
        if (!el || !el.tagName) { return; }
        var info = describe(el);
        delete info.label;
        info.value = isSecret(el) ? '***' : text(el.type === 'checkbox' ? el.checked : el.value);
        if (el.tagName === 'SELECT' && el.selectedIndex >= 0) { info.selected = (el.options[el.selectedIndex].text || '').trim().slice(0, 80); }
        record('change', info);
    }, true);

    document.addEventListener('submit', function (event) {
        var form = event.target;
        record('form_submit', { action: form.getAttribute('action') || '', method: form.method || '', id: form.id || '' });
    }, true);

    if (window.fetch) {
        var originalFetch = window.fetch;
        window.fetch = function (input, init) {
            var url = typeof input === 'string' ? input : (input && input.url) || String(input);
            var method = (init && init.method) || (input && input.method) || 'GET';
            var started = Date.now();
            return originalFetch.apply(this, arguments).then(function (response) {
                record('fetch', { method: method, url: url, status: response.status, redirected: response.redirected, ms: Date.now() - started });
                return response;
            }, function (error) {
                record('fetch_failed', { method: method, url: url, error: text(error), ms: Date.now() - started });
                throw error;
            });
        };
    }

    record('page_open', { referrer: document.referrer, screen: window.innerWidth + 'x' + window.innerHeight, agent: navigator.userAgent });
    setInterval(flush, 2000);
    window.addEventListener('pagehide', flush);
    document.addEventListener('visibilitychange', function () { if (document.hidden) { flush(); } });
})();
