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
