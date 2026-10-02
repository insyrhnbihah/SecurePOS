(function () {
    'use strict';

    var passwordInput = document.getElementById('password');
    var toggleButton = document.getElementById('toggle-password');
    if (!passwordInput || !toggleButton) {
        return;
    }

    toggleButton.addEventListener('click', function () {
        var showing = passwordInput.type === 'text';
        passwordInput.type = showing ? 'password' : 'text';
        toggleButton.setAttribute('aria-pressed', showing ? 'false' : 'true');
        toggleButton.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
        passwordInput.focus({ preventScroll: true });
    });
}());
