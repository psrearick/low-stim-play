import { Passkeys } from '@laravel/passkeys';

import './echo';

document.addEventListener('DOMContentLoaded', () => {
    const themeToggle = document.querySelector('[data-theme-toggle]');

    if (themeToggle) {
        const setLabel = () => {
            const isLight = document.documentElement.classList.contains('dark') === false;
            themeToggle.textContent = isLight ? 'Dark theme' : 'Light theme';
        };

        setLabel();

        themeToggle.addEventListener('click', () => {
            const isDark = document.documentElement.classList.toggle('dark');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            setLabel();
        });
    }

    document.querySelectorAll('[data-passkey-login]').forEach((button) => {
        button.addEventListener('click', async () => {
            button.disabled = true;

            try {
                const response = await Passkeys.verify();
                window.location.href = response.redirect ?? '/home';
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
                window.location.href = response.redirect ?? '/home';
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
