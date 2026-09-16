const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content;

const request = async (url, options = {}) => {
    const response = await fetch(url, {
        ...options,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            ...options.headers,
        },
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(data.message || 'The push request failed.');
    }

    return data;
};

const urlBase64ToUint8Array = (value) => {
    const padding = '='.repeat((4 - (value.length % 4)) % 4);
    const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(base64);

    return Uint8Array.from([...raw].map((character) => character.charCodeAt(0)));
};

const setupPushNotifications = async () => {
    const panel = document.querySelector('[data-push-panel]');

    if (!panel || panel.dataset.initialized === 'true') {
        return;
    }

    panel.dataset.initialized = 'true';

    const status = panel.querySelector('[data-push-status]');
    const enableButton = panel.querySelector('[data-push-enable]');
    const disableButton = panel.querySelector('[data-push-disable]');
    const sendButton = panel.querySelector('[data-push-send]');
    const titleInput = panel.querySelector('[data-push-title]');
    const bodyInput = panel.querySelector('[data-push-body]');
    const isIOS = /iPhone|iPad|iPod/.test(navigator.userAgent);
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    const isBrave = Boolean(await navigator.brave?.isBrave?.());
    const missingApis = [
        !('serviceWorker' in navigator) && 'Service Worker',
        !('PushManager' in window) && 'PushManager',
        !('Notification' in window) && 'Notifications',
    ].filter(Boolean);

    let registration;
    let subscription;

    const setStatus = (message, type = 'info') => {
        status.textContent = message;
        status.dataset.type = type;
    };

    const refreshButtons = () => {
        enableButton.hidden = Boolean(subscription);
        disableButton.hidden = !subscription;
        sendButton.disabled = !subscription;
    };

    if (!window.isSecureContext) {
        setStatus('Push requires HTTPS. Open the HTTPS Servo URL on your iPhone, then reinstall the Home Screen app.', 'error');
        enableButton.disabled = true;
        sendButton.disabled = true;
        return;
    }

    if (isIOS && !isStandalone) {
        setStatus('This is open as a Safari tab or Home Screen bookmark. Remove the old icon, add this site to the Home Screen again, then launch it from the new icon.', 'warning');
        enableButton.disabled = true;
        sendButton.disabled = true;
        return;
    }

    if (missingApis.length > 0) {
        setStatus(`Web Push is unavailable in this app context. Missing: ${missingApis.join(', ')}.`, 'error');
        enableButton.disabled = true;
        sendButton.disabled = true;
        return;
    }

    setStatus('Checking this device…');

    try {
        registration = await navigator.serviceWorker.register('/service-worker.js', { scope: '/' });
        registration = await navigator.serviceWorker.ready;
        subscription = await registration.pushManager.getSubscription();

        if (subscription) {
            setStatus('Notifications are enabled on this device.', 'success');
        } else if (Notification.permission === 'denied') {
            setStatus('Notifications are blocked. Enable them for this web app in iPhone Settings.', 'error');
        } else {
            setStatus('This device is ready. Tap Enable notifications.');
        }

        refreshButtons();
    } catch (error) {
        setStatus(error.message || 'The service worker could not be registered.', 'error');
        enableButton.disabled = true;
        return;
    }

    enableButton.addEventListener('click', async () => {
        enableButton.disabled = true;
        setStatus('Waiting for notification permission…');

        try {
            const permission = await Notification.requestPermission();

            if (permission !== 'granted') {
                throw new Error('Notification permission was not granted.');
            }

            subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(panel.dataset.vapidKey),
            });

            const contentEncoding = PushManager.supportedContentEncodings?.[0] || 'aes128gcm';
            await request(panel.dataset.subscribeUrl, {
                method: 'POST',
                body: JSON.stringify({ ...subscription.toJSON(), contentEncoding }),
            });

            setStatus('Notifications are enabled on this device.', 'success');
            refreshButtons();
        } catch (error) {
            console.error('Push subscription failed:', error);

            if (isBrave && /push service error/i.test(error.message || '')) {
                setStatus('Brave\'s push transport is disabled or unavailable. Open brave://settings/privacy, enable “Use Google services for push messaging,” relaunch Brave, and try again.', 'error');
            } else {
                setStatus(error.message || 'Notifications could not be enabled.', 'error');
            }
        } finally {
            enableButton.disabled = false;
        }
    });

    disableButton.addEventListener('click', async () => {
        disableButton.disabled = true;

        try {
            await request(panel.dataset.unsubscribeUrl, {
                method: 'DELETE',
                body: JSON.stringify({ endpoint: subscription.endpoint }),
            });
            await subscription.unsubscribe();
            subscription = null;
            setStatus('Notifications are disabled on this device.');
            refreshButtons();
        } catch (error) {
            setStatus(error.message || 'Notifications could not be disabled.', 'error');
        } finally {
            disableButton.disabled = false;
        }
    });

    sendButton.addEventListener('click', async () => {
        sendButton.disabled = true;
        setStatus('Sending a push…');

        try {
            const data = await request(panel.dataset.sendUrl, {
                method: 'POST',
                body: JSON.stringify({ title: titleInput.value, body: bodyInput.value }),
            });
            setStatus(`${data.message} Sent to ${data.subscriptions} subscribed device${data.subscriptions === 1 ? '' : 's'}.`, 'success');
        } catch (error) {
            setStatus(error.message || 'The push could not be sent.', 'error');
        } finally {
            sendButton.disabled = !subscription;
        }
    });
};

document.addEventListener('DOMContentLoaded', setupPushNotifications);
document.addEventListener('livewire:navigated', setupPushNotifications);
