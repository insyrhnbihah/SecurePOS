/* Load synchronously in the head so the saved palette is selected before paint. */
(function () {
    'use strict';
    var key = 'securepos.theme';
    var root = document.documentElement;
    var button;
    var icons = {
        light: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/>',
        dark: '<path d="M20.5 13A9 9 0 0 1 11 3.5 9 9 0 1 0 20.5 13Z"/>'
    };
    function apply(theme) {
        theme = theme === 'light' ? 'light' : 'dark';
        root.dataset.theme = theme;
        if (button) {
            var label = 'Switch to ' + (theme === 'dark' ? 'Light' : 'Dark') + ' Mode';
            button.title = label;
            button.setAttribute('aria-label', label);
            button.setAttribute('aria-pressed', String(theme === 'light'));
            button.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + icons[theme] + '</svg>';
        }
    }
    var saved;
    try { saved = window.localStorage.getItem(key); } catch (error) { /* Storage may be disabled. */ }
    apply(saved);
    document.addEventListener('DOMContentLoaded', function () {
        button = document.createElement('button');
        button.type = 'button';
        button.className = 'icon-button theme-toggle';
        var header = document.querySelector('.topbar-actions');
        if (header) {
            var anchor = header.querySelector('.notification-wrapper, .topbar-profile');
            header.insertBefore(button, anchor);
        } else {
            button.classList.add('theme-toggle-corner');
            document.body.appendChild(button);
        }
        apply(root.dataset.theme);
        button.addEventListener('click', function () {
            var next = root.dataset.theme === 'light' ? 'dark' : 'light';
            apply(next);
            try { window.localStorage.setItem(key, next); } catch (error) { /* Keep working for this page. */ }
        });
    });
    window.addEventListener('storage', function (event) {
        if (event.key === key || event.key === null) apply(event.newValue);
    });
}());
