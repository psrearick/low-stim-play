import { Passkeys } from '@laravel/passkeys';

import './echo';

document.addEventListener('DOMContentLoaded', () => {
    const themeToggle = document.querySelector('[data-theme-toggle]');

    if (themeToggle) {
        const meta = document.querySelector('meta[name="theme-color"]');

        const setLabel = () => {
            const isLight = document.documentElement.classList.contains('dark') === false;
            themeToggle.textContent = isLight ? 'Dark Theme' : 'Light Theme';
            themeToggle.setAttribute('aria-label', isLight ? 'Switch to dark theme' : 'Switch to light theme');
            if (meta) {
                meta.setAttribute('content', isLight ? '#e9ebee' : '#2c3035');
            }
        };

        setLabel();

        themeToggle.addEventListener('click', () => {
            const isDark = document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            setLabel();
        });
    }

    const rulesBtn = document.getElementById('rules-btn');
    const rulesDialog = document.getElementById('rules-dialog');
    const rulesDialogClose = document.getElementById('rules-dialog-close');

    if (rulesBtn && rulesDialog) {
        rulesBtn.addEventListener('click', () => {
            rulesDialog.showModal();
            // Avoids an unexplained focus ring landing on the close button --
            // showModal() would otherwise auto-focus it as the first
            // focusable element in the dialog. Focusing the dialog itself
            // keeps Tab cycling through its controls without singling out
            // the close button as the default one.
            rulesDialog.focus();
        });

        rulesDialogClose?.addEventListener('click', () => rulesDialog.close());

        rulesDialog.addEventListener('click', (event) => {
            if (event.target === rulesDialog) {
                rulesDialog.close();
            }
        });
    }

    const chromeToggleBtn = document.getElementById('chrome-toggle-btn');

    if (chromeToggleBtn) {
        let hidden = false;

        const applyChrome = () => {
            document.body.classList.toggle('chrome-hidden', hidden);
            chromeToggleBtn.setAttribute('aria-pressed', hidden ? 'true' : 'false');
            chromeToggleBtn.setAttribute('aria-label', hidden ? 'Show menu bars' : 'Hide menu bars for fullscreen');
        };

        chromeToggleBtn.addEventListener('click', () => {
            hidden = !hidden;
            applyChrome();
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && hidden) {
                hidden = false;
                applyChrome();
            }
        });
    }

    document.querySelectorAll('[data-passkey-login]').forEach((button) => {
        button.addEventListener('click', async () => {
            button.disabled = true;

            try {
                const response = await Passkeys.verify();
                window.location.href = response.redirect ?? '/dashboard';
            } catch (error) {
                button.disabled = false;
                alert(error.message ?? 'Passkey sign-in failed.');
            }
        });
    });

    document.querySelectorAll('[data-passkey-confirm]').forEach((button) => {
        button.addEventListener('click', async () => {
            button.disabled = true;

            try {
                const response = await Passkeys.verify({
                    routes: {
                        options: '/passkeys/confirm/options',
                        submit: '/passkeys/confirm',
                    },
                });
                window.location.href = response.redirect ?? '/dashboard';
            } catch (error) {
                button.disabled = false;
                alert(error.message ?? 'Passkey confirmation failed.');
            }
        });
    });

    // Event-delegated (not querySelectorAll) because this button is only
    // ever added to the page later, by a Livewire re-render (the Boggle
    // reveal screen) rather than being present at initial page load.
    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-copy]');

        if (!button) {
            return;
        }

        try {
            await navigator.clipboard.writeText(button.dataset.copy);
            const original = button.textContent;
            button.textContent = 'Copied!';
            setTimeout(() => {
                button.textContent = original;
            }, 2000);
        } catch {
            alert('Could not copy the link. You can select and copy it from the text field instead.');
        }
    });

    document.querySelectorAll('[data-passkey-register]').forEach((button) => {
        button.addEventListener('click', async () => {
            const name = window.prompt('Name this passkey (e.g. "MacBook Pro"):');

            if (!name) {
                return;
            }

            button.disabled = true;

            try {
                await Passkeys.register({ name });
                window.location.reload();
            } catch (error) {
                button.disabled = false;
                alert(error.message ?? 'Passkey registration failed.');
            }
        });
    });
});
