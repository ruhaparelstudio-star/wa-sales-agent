const bootGlobalLoading = () => {
    const overlay = document.getElementById('global-loading-overlay');

    if (!overlay) {
        return;
    }

    const SHOW_DELAY_MS = 150;
    const SAFETY_TIMEOUT_MS = 30000;

    let activeRequests = 0;
    let showTimer = null;
    let safetyTimer = null;

    const hide = () => {
        overlay.classList.add('hidden');
        if (showTimer) {
            clearTimeout(showTimer);
            showTimer = null;
        }
        if (safetyTimer) {
            clearTimeout(safetyTimer);
            safetyTimer = null;
        }
    };

    const show = () => {
        overlay.classList.remove('hidden');
        if (safetyTimer) {
            clearTimeout(safetyTimer);
        }
        safetyTimer = window.setTimeout(() => {
            activeRequests = 0;
            hide();
        }, SAFETY_TIMEOUT_MS);
    };

    const start = () => {
        activeRequests += 1;

        if (showTimer || !overlay.classList.contains('hidden')) {
            return;
        }

        showTimer = window.setTimeout(() => {
            showTimer = null;
            if (activeRequests > 0) {
                show();
            }
        }, SHOW_DELAY_MS);
    };

    const finish = () => {
        activeRequests = Math.max(0, activeRequests - 1);

        if (activeRequests === 0) {
            hide();
        }
    };

    const hasUserAction = (payload) => {
        if (!payload || !Array.isArray(payload.components)) {
            return false;
        }

        return payload.components.some((component) => {
            const calls = component?.calls;
            return Array.isArray(calls) && calls.length > 0;
        });
    };

    document.addEventListener('submit', (event) => {
        if (!(event.target instanceof HTMLFormElement)) {
            return;
        }

        if (event.target.dataset.loading === 'off') {
            return;
        }

        start();
    });

    document.addEventListener('livewire:init', () => {
        Livewire.hook('request', ({ payload, succeed, fail }) => {
            if (!hasUserAction(payload)) {
                return;
            }

            start();

            let settled = false;
            const done = () => {
                if (settled) return;
                settled = true;
                finish();
            };

            succeed(done);
            fail(done);
        });
    });

    window.addEventListener('pageshow', () => {
        activeRequests = 0;
        hide();
    });

    window.addEventListener('beforeunload', () => {
        activeRequests = 0;
        hide();
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootGlobalLoading, { once: true });
} else {
    bootGlobalLoading();
}
