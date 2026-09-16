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

document.addEventListener('DOMContentLoaded', () => {
    animatePageEntry();
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

    requestAnimationFrame(animatePageEntry);
    navigationInProgress = false;
});

document.addEventListener('app-background-updated', (event) => {
    if (!event.detail?.url) {
        document.body.style.removeProperty('--app-background-image');
        document.body.classList.remove('app-photo-background');

        return;
    }

    document.body.style.setProperty('--app-background-image', `url("${event.detail.url}")`);
    document.body.classList.add('app-photo-background');
});
