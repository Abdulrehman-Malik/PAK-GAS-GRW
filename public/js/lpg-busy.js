/*
 * Global "busy" guard: while a save is running, a full-screen spinner blocks every click,
 * and a second submit / save request is refused. Prevents duplicate postings from double
 * clicks, Enter key repeats and impatient re-clicks on a slow response.
 *
 * Covers: normal (non-GET) form submits, fetch() and jQuery $.ajax() writes (POST/PUT/PATCH/DELETE).
 * Opt out of a form with data-no-busy. Forms that already stopped the submit themselves
 * (e.g. failed validation) are not locked.
 */
(function () {
    'use strict';

    var pending = 0;          // running write requests (fetch / ajax)
    var formLocked = false;   // a form submit is in flight (the page is about to navigate)
    var releaseTimer = null;
    var FORM_FALLBACK_MS = 20000; // downloads (e.g. exports) never navigate away; release the lock

    var overlay = null;

    function ensureOverlay() {
        if (overlay) { return overlay; }
        var style = document.createElement('style');
        style.textContent =
            '#lpgBusy{position:fixed;inset:0;z-index:20000;display:none;align-items:center;justify-content:center;' +
            'background:rgba(255,255,255,.55);cursor:wait;backdrop-filter:blur(1px)}' +
            '#lpgBusy.is-on{display:flex}' +
            '#lpgBusy .lpg-busy-box{background:#fff;border-radius:14px;padding:18px 22px;box-shadow:0 8px 30px rgba(0,0,0,.2)}' +
            '#lpgBusy .spinner-border{width:2.6rem;height:2.6rem;color:#0d3b2e}';
        document.head.appendChild(style);
        overlay = document.createElement('div');
        overlay.id = 'lpgBusy';
        overlay.setAttribute('role', 'status');
        overlay.setAttribute('aria-live', 'polite');
        overlay.setAttribute('aria-busy', 'true');
        overlay.innerHTML = '<div class="lpg-busy-box"><div class="spinner-border" aria-hidden="true"></div></div>';
        // Swallow every pointer / keyboard interaction while visible.
        ['click', 'mousedown', 'mouseup', 'touchstart', 'contextmenu'].forEach(function (type) {
            overlay.addEventListener(type, function (e) { e.preventDefault(); e.stopPropagation(); }, true);
        });
        document.body.appendChild(overlay);
        return overlay;
    }

    function busy() { return formLocked || pending > 0; }

    function render() {
        if (!document.body) { return; }
        var el = ensureOverlay();
        el.classList.toggle('is-on', busy());
        if (busy()) {
            if (document.activeElement && document.activeElement !== document.body && document.activeElement.blur) {
                document.activeElement.blur(); // stop Enter / Space from re-triggering a focused button
            }
        }
    }

    function isWrite(method) {
        return /^(POST|PUT|PATCH|DELETE)$/i.test(method || 'GET');
    }

    function beginRequest() { pending++; render(); }
    function endRequest() { pending = Math.max(0, pending - 1); render(); }

    // ---- Form submits ------------------------------------------------------------------
    function lockForm() {
        formLocked = true;
        render();
        clearTimeout(releaseTimer);
        releaseTimer = setTimeout(function () { formLocked = false; render(); }, FORM_FALLBACK_MS);
    }

    // Capture phase: refuse a second submit while one is running.
    document.addEventListener('submit', function (e) {
        if (busy()) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);

    // Bubble phase on document runs after the form's own handlers: if none stopped the submit, lock.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (e.defaultPrevented || !form || form.hasAttribute('data-no-busy')) { return; }
        var method = (e.submitter && e.submitter.getAttribute('formmethod')) || form.getAttribute('method') || 'GET';
        if (!isWrite(method)) { return; }
        var target = (e.submitter && e.submitter.getAttribute('formtarget')) || form.getAttribute('target') || '';
        if (target && target !== '_self') { return; } // opens elsewhere; this page stays usable
        lockForm();
    }, false);

    // Back/forward cache restores the old DOM: never come back to a locked page.
    window.addEventListener('pageshow', function () {
        formLocked = false;
        pending = 0;
        clearTimeout(releaseTimer);
        render();
    });

    // ---- fetch() -------------------------------------------------------------------------
    if (window.fetch) {
        var nativeFetch = window.fetch.bind(window);
        window.fetch = function (input, init) {
            var method = (init && init.method) || (input && input.method) || 'GET';
            if (!isWrite(method)) { return nativeFetch(input, init); }
            if (busy()) { return Promise.reject(new Error('A save is already in progress.')); }
            beginRequest();
            var p;
            try { p = nativeFetch(input, init); } catch (err) { endRequest(); throw err; }
            return p.then(
                function (res) { endRequest(); return res; },
                function (err) { endRequest(); throw err; }
            );
        };
    }

    // ---- jQuery ajax ---------------------------------------------------------------------
    function hookJquery() {
        if (!window.jQuery || window.jQuery.__lpgBusy) { return; }
        var $ = window.jQuery;
        $.__lpgBusy = true;
        $(document).on('ajaxSend', function (e, xhr, settings) {
            if (isWrite(settings && settings.type)) { xhr.__lpgBusy = true; beginRequest(); }
        });
        $(document).on('ajaxComplete', function (e, xhr) {
            if (xhr && xhr.__lpgBusy) { xhr.__lpgBusy = false; endRequest(); }
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { hookJquery(); ensureOverlay(); });
    } else {
        hookJquery(); ensureOverlay();
    }
})();
