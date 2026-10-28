// Runs public/js/lpg-i18n.js against a minimal fake DOM and prints what translate() returns
// for the strings given on stdin. Used by tests/unit/TranslatorScriptTest.php.
//   stdin:  {"payload": {...UiLanguage::appPayload('ur')}, "inputs": ["text", ...]}
//   stdout: {"results": {"text": "translation", ...}, "alerts": [...], "revealed": true}
import fs from 'node:fs';
import vm from 'node:vm';

const input = JSON.parse(fs.readFileSync(0, 'utf8'));
const source = fs.readFileSync(new URL('../../public/js/lpg-i18n.js', import.meta.url), 'utf8');

const alerts = [];
let revealed = false;

const root = {
    nodeType: 1,
    tagName: 'HTML',
    classList: { remove: () => { revealed = true; }, add: () => {} },
    hasAttribute: () => false,
    getAttribute: () => null,
    querySelectorAll: () => [],
};

// Text nodes the page "contains": counts every write, because each write re-triggers the
// page's MutationObserver (a write of an unchanged value must never happen).
let writes = 0;
const textNodes = (input.nodes || []).map((value) => ({
    nodeType: 3,
    _value: value,
    get nodeValue() { return this._value; },
    set nodeValue(next) { writes += 1; this._value = next; },
}));

const sandbox = {
    document: {
        documentElement: root,
        readyState: 'complete',
        addEventListener: () => {},
        createTreeWalker: () => {
            let index = 0;
            return {
                currentNode: null,
                nextNode() {
                    if (index >= textNodes.length) { return false; }
                    this.currentNode = textNodes[index++];
                    return true;
                },
            };
        },
    },
    NodeFilter: { SHOW_ELEMENT: 1, SHOW_TEXT: 4, FILTER_ACCEPT: 1, FILTER_REJECT: 2, FILTER_SKIP: 3 },
    MutationObserver: class { observe() {} },
    alert: (message) => alerts.push(message),
    confirm: (message) => { alerts.push(message); return true; },
    prompt: () => null,
    console,
};
sandbox.window = sandbox;
sandbox.LPG_I18N = input.payload;
vm.createContext(sandbox);
vm.runInContext(source, sandbox);

const results = {};
for (const text of input.inputs) {
    results[text] = sandbox.LPG_I18N.translate(text);
}
sandbox.alert('Customer not found.\nSale History');

process.stdout.write(JSON.stringify({
    results,
    alerts,
    revealed,
    writes,
    nodes: textNodes.map((node) => node.nodeValue),
}));
