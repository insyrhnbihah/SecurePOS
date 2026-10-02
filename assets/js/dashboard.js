// dashboard.js
// Shared header timestamp logic for SecurePOS pages.

function formatDateTime() {
    const now = new Date();
    const formatter = new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Asia/Kuala_Lumpur',
        year: 'numeric',
        month: 'long',
        day: '2-digit',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    });
    return formatter.format(now);
}

function updateHeaderDateTime() {
    const datetimeElement = document.getElementById('current-datetime');
    if (datetimeElement) {
        datetimeElement.textContent = formatDateTime();
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', updateHeaderDateTime);
} else {
    updateHeaderDateTime();
}

setInterval(updateHeaderDateTime, 1000);

// Accessibility: provide a basic tooltip fallback for icons.
const iconButton = document.querySelector('.icon-button');
if (iconButton) {
    iconButton.addEventListener('mouseover', () => {
        iconButton.setAttribute('title', 'Notifications');
    });
}
