import './push-notifications';

document.documentElement.classList.add('page-motion-enabled');

let navigationInProgress = false;

const animatePageEntry = () => {
    const container = document.querySelector('[data-page-transition]');

    if (!container || container.dataset.pageAnimated === 'true') {
        return;
    }

    const candidates = [
        ...container.querySelectorAll(':scope > * > *'),
        ...container.querySelectorAll('[data-page-stagger] > *'),
    ];
    const uniqueCandidates = [...new Set(candidates)]
        .filter((element) => !element.closest('[data-page-no-enter]'));

    uniqueCandidates.forEach((element) => element.classList.remove('page-enter-item'));
    void container.offsetWidth;

    uniqueCandidates.forEach((element, index) => {
        element.style.setProperty('--page-enter-index', index);
        element.style.setProperty('--page-enter-delay', `${Math.min(index, 12) * 42}ms`);
        element.classList.add('page-enter-item');

        const clearPageEntryStyles = (event) => {
            if (event.target !== element || event.animationName !== 'page-enter') {
                return;
            }

            element.classList.remove('page-enter-item');
            element.style.removeProperty('--page-enter-index');
            element.style.removeProperty('--page-enter-delay');
            element.removeEventListener('animationend', clearPageEntryStyles);
        };

        element.addEventListener('animationend', clearPageEntryStyles);
    });

    container.dataset.pageAnimated = 'true';
    container.setAttribute('data-page-ready', '');
};

const prepareImageFade = (image) => {
    if (
        !(image instanceof HTMLImageElement)
        || image.dataset.imageFadeReady === 'true'
        || image.hasAttribute('data-no-image-fade')
    ) {
        return;
    }

    image.dataset.imageFadeReady = 'true';
    image.classList.add('image-load-fade');

    const reveal = () => requestAnimationFrame(() => image.classList.add('image-load-complete'));

    if (image.complete) {
        reveal();

        return;
    }

    image.addEventListener('load', reveal, { once: true });
    image.addEventListener('error', reveal, { once: true });
};

const hydrateImageFades = (root = document) => {
    if (root instanceof HTMLImageElement) {
        prepareImageFade(root);
    }

    root.querySelectorAll?.('img').forEach(prepareImageFade);
};

let homeScrollFrame;

const syncHomeBackgroundState = () => {
    const homeScreen = document.querySelector('[data-home-screen]');

    if (!homeScreen) {
        document.body.style.removeProperty('--app-background-overlay-strength');

        return;
    }

    const scrollTop = document.scrollingElement?.scrollTop ?? window.scrollY;
    const fadeDistance = Math.max(160, Math.min(280, window.innerHeight * 0.24));
    const overlayStrength = Math.min(1, Math.max(0, scrollTop / fadeDistance));

    document.body.style.setProperty('--app-background-overlay-strength', overlayStrength.toFixed(3));
};

const queueHomeBackgroundSync = () => {
    if (homeScrollFrame) {
        return;
    }

    homeScrollFrame = requestAnimationFrame(() => {
        syncHomeBackgroundState();
        homeScrollFrame = undefined;
    });
};

const syncBrowserChromeTheme = () => {
    const themeColor = document.querySelector('#app-theme-color');

    if (themeColor) {
        themeColor.setAttribute('content', document.documentElement.classList.contains('dark') ? '#020617' : '#f8fafc');
    }
};

document.addEventListener('DOMContentLoaded', () => {
    animatePageEntry();
    hydrateImageFades();
    syncHomeBackgroundState();
    syncBrowserChromeTheme();
    window.addEventListener('scroll', queueHomeBackgroundSync, { passive: true });

    const appearanceObserver = new MutationObserver(syncBrowserChromeTheme);
    appearanceObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

    const imageFadeObserver = new MutationObserver((records) => {
        records.forEach((record) => {
            record.addedNodes.forEach((node) => {
                if (node instanceof Element) {
                    hydrateImageFades(node);
                }
            });
        });
    });
    imageFadeObserver.observe(document.body, { childList: true, subtree: true });
});
document.addEventListener('livewire:navigating', () => {
    navigationInProgress = true;
});
document.addEventListener('livewire:navigated', () => {
    const container = document.querySelector('[data-page-transition]');

    if (container && navigationInProgress) {
        delete container.dataset.pageAnimated;
        container.removeAttribute('data-page-ready');
    }

    requestAnimationFrame(() => {
        animatePageEntry();
        hydrateImageFades();
        syncHomeBackgroundState();
    });
    navigationInProgress = false;
});

document.addEventListener('app-background-updated', (event) => {
    if (!event.detail?.url) {
        document.body.style.removeProperty('--app-background-image');
        document.body.style.removeProperty('--app-background-overlay-strength');
        document.body.classList.remove('app-photo-background');

        return;
    }

    document.body.style.setProperty('--app-background-image', `url("${event.detail.url}")`);
    document.body.classList.add('app-photo-background');
    syncHomeBackgroundState();
});
