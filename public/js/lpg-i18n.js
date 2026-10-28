/*
 * Perfect LPG interface translator.
 *
 * English is the source language, so this only runs for other languages (Urdu).
 * It swaps text by exact match against the dictionary in window.LPG_I18N, for the
 * server-rendered page and for anything the page builds later (POS rows, modals,
 * DataTables, alerts). Text with no entry is left untouched, so a missing
 * translation can never break a screen.
 *
 * Dictionary shape (see App\Libraries\UiLanguage::appPayload):
 *   exact:    { "Sale History": "سیل ہسٹری", ... }
 *   patterns: [ ["{0} sales", "{0} سیلز"], ... ]   {n} = any text that varies at runtime
 */
(function () {
    'use strict';

    var root = document.documentElement;
    var data = window.LPG_I18N;
    var reveal = function () { root.classList.remove('i18n-pending'); };

    if (!data || !data.exact) { reveal(); return; }

    var ARABIC = /[؀-ۿ]/;
    var SKIP = { SCRIPT: 1, STYLE: 1, TEXTAREA: 1, CODE: 1, PRE: 1, NOSCRIPT: 1, SVG: 1, svg: 1 };
    var ATTRS = ['placeholder', 'title', 'aria-label', 'alt'];
    var ATTR_SELECTOR = '[placeholder],[title],[aria-label],[alt],input[type=submit],input[type=button]';
    var cache = Object.create(null);

    // "{n}" matches any text, except right after a letter ("user{1}"), where it can only be a
    // short plural ending, so "X cylinder{1}" cannot swallow ")" or a whole word.
    var patterns = (data.patterns || []).map(function (entry) {
        var source = entry[0].replace(/[.*+?^${}()|[\]\\]/g, function (c) { return c === '{' || c === '}' ? c : '\\' + c; });
        var regex = source.replace(/(.?)\{\d+\}/g, function (_, before) {
            return before + (/[A-Za-z]/.test(before) ? '([a-z]{0,2})' : '(.+?)');
        });
        return { re: new RegExp('^' + regex + '$'), out: entry[1] };
    });

    function normalize(text) { return text.replace(/\s+/g, ' ').trim(); }

    // Translation for an already-normalized string, or null when there is none.
    function lookup(key) {
        if (!key || ARABIC.test(key)) { return null; }
        if (key in cache) { return cache[key]; }

        var out = Object.prototype.hasOwnProperty.call(data.exact, key) ? data.exact[key] : null;
        for (var i = 0; out === null && i < patterns.length; i++) {
            var match = patterns[i].re.exec(key);
            if (match) {
                out = patterns[i].out.replace(/\{(\d+)\}/g, function (_, n) {
                    var part = match[Number(n) + 1];
                    return part === undefined ? '' : (lookup(normalize(part)) || part);
                });
            }
        }
        cache[key] = out;
        return out;
    }

    // Only write when the text really changes: a write that stores the same value still fires the
    // MutationObserver below, and an entry that maps a text to itself would loop forever.
    // Keep the original leading/trailing whitespace of a text node.
    function translateText(node) {
        var value = node.nodeValue;
        if (!value || !/\S/.test(value)) { return; }
        var out = lookup(normalize(value));
        if (out === null) { return; }
        var next = value.match(/^\s*/)[0] + out + value.match(/\s*$/)[0];
        if (next !== value) { node.nodeValue = next; }
    }

    function translateAttributes(el) {
        for (var i = 0; i < ATTRS.length; i++) {
            var value = el.getAttribute(ATTRS[i]);
            if (value) {
                var out = lookup(normalize(value));
                if (out !== null && out !== value) { el.setAttribute(ATTRS[i], out); }
            }
        }
        if (el.tagName === 'INPUT' && (el.type === 'submit' || el.type === 'button') && el.value) {
            var label = lookup(normalize(el.value));
            if (label !== null && label !== el.value) { el.value = label; }
        }
    }

    // A cell that holds only English data (a date, an amount, a code) has no strong direction, so a
    // right-to-left page would reorder it ("20:28 2026-10-15"). Mark it left-to-right; CSS keeps it
    // right-aligned with the Urdu cells. The mark is removed if the cell later receives Urdu text.
    var LATIN_DATA = /^[\x20-\x7E –—·×]+$/;
    function keepLatinOrder(cell) {
        if (!cell || cell.tagName !== 'TD' || cell.childElementCount !== 0) { return; }
        var text = cell.textContent.trim();
        if (text && LATIN_DATA.test(text)) {
            if (!cell.hasAttribute('dir')) { cell.setAttribute('dir', 'ltr'); cell.setAttribute('data-i18n-ltr', ''); }
        } else if (cell.hasAttribute('data-i18n-ltr')) {
            cell.removeAttribute('dir');
            cell.removeAttribute('data-i18n-ltr');
        }
    }

    // Browsers format date/time inputs for the page language; keep them in the familiar English layout.
    var DATE_INPUT = { date: 1, 'datetime-local': 1, time: 1, month: 1, week: 1 };
    function keepInputFormat(el) {
        if (el.tagName === 'INPUT' && DATE_INPUT[el.type] && !el.hasAttribute('lang')) { el.setAttribute('lang', 'en-GB'); }
    }

    function walk(node) {
        if (node.nodeType === 3) { translateText(node); return; }
        if (node.nodeType !== 1 || SKIP[node.tagName] || node.hasAttribute('data-no-i18n')) { return; }

        translateAttributes(node);
        keepInputFormat(node);
        if (node.querySelectorAll) {
            Array.prototype.forEach.call(node.querySelectorAll(ATTR_SELECTOR), translateAttributes);
            Array.prototype.forEach.call(node.querySelectorAll('input'), keepInputFormat);
        }
        var walker = document.createTreeWalker(node, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT, {
            acceptNode: function (n) {
                if (n.nodeType === 1) {
                    return SKIP[n.tagName] || n.hasAttribute('data-no-i18n') ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_SKIP;
                }
                return NodeFilter.FILTER_ACCEPT;
            }
        });
        while (walker.nextNode()) { translateText(walker.currentNode); }

        // After translating, so a cell that became Urdu is not marked as English data.
        if (node.tagName === 'TD') { keepLatinOrder(node); }
        if (node.querySelectorAll) { Array.prototype.forEach.call(node.querySelectorAll('td'), keepLatinOrder); }
    }

    // Native dialogs are not part of the page, so translate their message too (line by line).
    ['alert', 'confirm', 'prompt'].forEach(function (name) {
        var original = window[name];
        window[name] = function (message) {
            var args = Array.prototype.slice.call(arguments);
            if (typeof message === 'string') {
                args[0] = message.split('\n').map(function (line) { return lookup(normalize(line)) || line; }).join('\n');
            }
            return original.apply(window, args);
        };
    });

    function start() {
        walk(root);
        new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                if (m.type === 'childList') { Array.prototype.forEach.call(m.addedNodes, walk); }
                else if (m.type === 'characterData') { translateText(m.target); keepLatinOrder(m.target.parentNode); }
                else if (m.type === 'attributes') { translateAttributes(m.target); }
            });
        }).observe(root, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ATTRS });
        reveal();
    }

    window.LPG_I18N.translate = function (text) { return lookup(normalize(String(text))) || text; };

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
