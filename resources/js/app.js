import './push-notifications';

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
    const primaryHeading = container.querySelector('h1:not(.sr-only)');
    const titleCandidate = primaryHeading
        ? uniqueCandidates.find((element) => element === primaryHeading || element.contains(primaryHeading))
        : null;
    const orderedCandidates = titleCandidate
        ? [titleCandidate, ...uniqueCandidates.filter((element) => element !== titleCandidate)]
        : uniqueCandidates;

    orderedCandidates.forEach((element) => element.classList.remove('page-enter-item', 'page-enter-title'));
    void container.offsetWidth;

    orderedCandidates.forEach((element, index) => {
        const isTitle = element === titleCandidate;
        const contentIndex = titleCandidate ? Math.max(0, index - 1) : index;

        element.style.setProperty('--page-enter-index', index);
        element.style.setProperty('--page-enter-delay', isTitle ? '0ms' : `${80 + (Math.min(contentIndex, 8) * 24)}ms`);
        element.classList.add('page-enter-item');

        if (isTitle) {
            element.classList.add('page-enter-title');
        }

        const clearPageEntryStyles = (event) => {
            if (event.target !== element || event.animationName !== 'page-enter') {
                return;
            }

            element.classList.remove('page-enter-item', 'page-enter-title');
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
        || image.hasAttribute('data-no-image-fade')
    ) {
        return;
    }

    const source = image.currentSrc || image.src;

    if (image.dataset.imageFadeSource === source) {
        if (image.complete) {
            image.classList.add('image-load-complete');
        }

        return;
    }

    image.dataset.imageFadeReady = 'true';
    image.dataset.imageFadeSource = source;
    image.classList.add('image-load-fade');
    image.classList.remove('image-load-complete');

    const reveal = async () => {
        try {
            await image.decode();
        } catch {
            // A failed decode will still reveal the browser's fallback rendering.
        }

        requestAnimationFrame(() => requestAnimationFrame(() => image.classList.add('image-load-complete')));
    };

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

let navigationWarmup;

const warmPrimaryNavigation = () => {
    window.clearTimeout(navigationWarmup);

    const warm = () => {
        if (navigator.connection?.saveData) {
            return;
        }

        const currentUrl = new URL(window.location.href);
        const links = [...document.querySelectorAll('[data-mobile-dock] a[wire\\:navigate\\.hover]')]
            .filter((link) => {
                const destination = new URL(link.href, document.baseURI);

                return destination.pathname !== currentUrl.pathname || destination.search !== currentUrl.search;
            });

        links.forEach((link, index) => {
            window.setTimeout(() => {
                link.dispatchEvent(new MouseEvent('mouseenter'));
            }, index * 650);
        });
    };

    if ('requestIdleCallback' in window) {
        window.requestIdleCallback(warm, { timeout: 800 });

        return;
    }

    navigationWarmup = window.setTimeout(warm, 250);
};

let homeScrollFrame;

const syncHomeBackgroundState = () => {
    const homeScreen = document.querySelector('[data-home-screen]');

    if (!homeScreen) {
        document.body.style.removeProperty('--app-background-overlay-strength');

        return;
    }

    if (document.querySelector('dialog[data-modal][open]')) {
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

let postHeaderScrollFrame;

const syncPostHeaderState = () => {
    const page = document.querySelector('[data-post-page]');

    if (!page) {
        return;
    }

    const main = page.closest('[data-flux-main]');
    const documentScrollTop = document.scrollingElement?.scrollTop ?? window.scrollY;
    const scrollTop = Math.max(documentScrollTop, main?.scrollTop ?? 0);

    page.toggleAttribute('data-compact', scrollTop > 120);
};

const queuePostHeaderSync = () => {
    if (postHeaderScrollFrame) {
        return;
    }

    postHeaderScrollFrame = requestAnimationFrame(() => {
        syncPostHeaderState();
        postHeaderScrollFrame = undefined;
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
    syncPostHeaderState();
    syncBrowserChromeTheme();
    warmPrimaryNavigation();
    window.addEventListener('scroll', queueHomeBackgroundSync, { passive: true });
    window.addEventListener('scroll', queuePostHeaderSync, { passive: true });
    document.addEventListener('scroll', queuePostHeaderSync, { passive: true, capture: true });

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
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('morphed', ({ el }) => hydrateImageFades(el));
});
document.addEventListener('livewire:navigate', () => {
    navigationInProgress = true;
    document.documentElement.classList.add('page-navigation-pending');
});
document.addEventListener('livewire:navigating', () => {
    navigationInProgress = true;
    window.Flux?.modals?.()?.close?.();
});
document.addEventListener('livewire:navigated', () => {
    const container = document.querySelector('[data-page-transition]');

    if (container && navigationInProgress) {
        delete container.dataset.pageAnimated;
        container.removeAttribute('data-page-ready');
    }

    requestAnimationFrame(() => {
        document.documentElement.classList.remove('page-navigation-pending');
        animatePageEntry();
        hydrateImageFades();
        syncHomeBackgroundState();
        syncPostHeaderState();
        warmPrimaryNavigation();
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
