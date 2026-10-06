'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('assets/js/theme.js', 'utf8');
function boot(saved, blocked = false, header = true) {
    const events = {}, clicks = {}, storage = new Map(saved === undefined ? [] : [['securepos.theme', saved]]);
    const button = { classList: { add() {} }, setAttribute(k, v) { this[k] = v; }, addEventListener(k, v) { clicks[k] = v; } };
    const root = { dataset: {} };
    const target = { querySelector() { return null; }, insertBefore(b) { assert.equal(b, button); } };
    const window = { localStorage: {
        getItem(k) { if (blocked) throw Error('blocked'); return storage.get(k) || null; },
        setItem(k, v) { if (blocked) throw Error('blocked'); storage.set(k, v); }
    }, addEventListener(k, v) { events[k] = v; } };
    const document = { documentElement: root, body: { appendChild() {} },
        createElement() { return button; }, querySelector() { return header ? target : null; },
        addEventListener(k, v) { events[k] = v; } };
    vm.runInNewContext(source, { window, document });
    return { root, button, storage, events, clicks };
}
for (const saved of [undefined, 'invalid', 'light', 'dark']) {
    const app = boot(saved);
    const expected = saved === 'light' ? 'light' : 'dark';
    assert.equal(app.root.dataset.theme, expected, 'pre-paint preference');
    app.events.DOMContentLoaded();
    assert.equal(app.button.title, expected === 'light' ? 'Switch to Dark Mode' : 'Switch to Light Mode');
    assert.equal(app.button['aria-pressed'], String(expected === 'light'));
    app.clicks.click();
    const next = expected === 'light' ? 'dark' : 'light';
    assert.equal(app.storage.get('securepos.theme'), next);
    assert.equal(boot(app.storage.get('securepos.theme')).root.dataset.theme, next, 'navigation/refresh persistence');
    app.events.storage({ key: 'securepos.theme', newValue: 'light' });
    assert.equal(app.root.dataset.theme, 'light', 'cross-tab synchronization');
    app.events.storage({ key: null, newValue: null });
    assert.equal(app.root.dataset.theme, 'dark', 'cleared preference');
}
const blocked = boot(undefined, true, false);
blocked.events.DOMContentLoaded(); blocked.clicks.click();
assert.equal(blocked.root.dataset.theme, 'light', 'toggle with unavailable storage');
console.log('PASS: early dark default, saved preferences, toggle, accessible labels, persistence, cross-tab sync, and disabled-storage fallback');
