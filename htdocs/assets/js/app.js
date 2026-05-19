/* EVEchievements — minimal JS */
document.addEventListener('DOMContentLoaded', () => {
    // Highlight active nav link
    const links = document.querySelectorAll('.topnav__link');
    links.forEach(link => {
        if (link.href === window.location.href ||
            (link.getAttribute('href') !== '/' && window.location.pathname.startsWith(link.getAttribute('href')))) {
            link.classList.add('topnav__link--active');
        }
    });

    // Auto-submit visibility toggle
    const visToggle = document.querySelector('input[name="is_public"]');
    if (visToggle) {
        visToggle.addEventListener('change', function() {
            this.closest('form').submit();
        });
    }
});
