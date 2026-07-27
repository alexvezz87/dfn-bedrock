/**
 * DFN Theme Header JavaScript
 * Gestione interazioni mobile menu e accessibilità header
 */

document.addEventListener('DOMContentLoaded', function () {
    const mobileToggle = document.querySelector('.dfn-mobile-toggle');
    const headerNav = document.querySelector('.dfn-header-nav');

    if (!mobileToggle || !headerNav) {
        return;
    }

    mobileToggle.addEventListener('click', function () {
        const isExpanded = mobileToggle.getAttribute('aria-expanded') === 'true';
        mobileToggle.setAttribute('aria-expanded', !isExpanded);
        mobileToggle.classList.toggle('is-active');
        headerNav.classList.toggle('is-open');
    });

    // Chiudi il menu quando si clicca su un link (in caso di ancore interne)
    const navLinks = headerNav.querySelectorAll('a');
    navLinks.forEach(function (link) {
        link.addEventListener('click', function () {
            if (headerNav.classList.contains('is-open')) {
                mobileToggle.setAttribute('aria-expanded', 'false');
                mobileToggle.classList.remove('is-active');
                headerNav.classList.remove('is-open');
            }
        });
    });

    // Chiudi il menu quando si ridimensiona la finestra oltre il breakpoint mobile
    window.addEventListener('resize', function () {
        if (window.innerWidth >= 992 && headerNav.classList.contains('is-open')) {
            mobileToggle.setAttribute('aria-expanded', 'false');
            mobileToggle.classList.remove('is-active');
            headerNav.classList.remove('is-open');
        }
    });
});
