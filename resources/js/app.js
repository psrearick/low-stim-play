import { Passkeys } from '@laravel/passkeys';

import './echo';

// ---- Global Livewire error handling (docs/concerns.md) -----------------
// Without this, any failed Livewire request (e.g. a room action that
// outlived the room itself -- see Room::pages::room.show's endRoom(),
// which deletes the Room row while other players may still be mid-game on
// a page that queries it) falls through to Livewire's own default
// showHtmlModal() behavior: it dumps the raw, unstyled HTML error response
// into an <iframe> overlay right on top of whatever the player was
// looking at -- exactly the "500 error briefly flashed on the screen"
// report. preventDefault() suppresses that modal; redirecting home is the
// same recovery pages::room.show's own onRoomEnded() already uses for the
// one case it *does* know how to detect. Deliberately excludes 422 --
// that's Livewire's own validation-error status (e.g. the Rooms menu's
// join-by-code form), which must keep re-rendering inline via $errors
// rather than bouncing the user away from a form they're actively filling
// in.
document.addEventListener('livewire:init', () => {
    window.Livewire.interceptRequest(({ onError }) => {
        onError(({ response, preventDefault }) => {
            if (response.status === 422) {
                return;
            }

            preventDefault();
            window.location.href = '/';
        });
    });
});

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

    // ---- App sidebar navigation (Dashboard/Profile/Stats pages only) -----
    // Bound directly (like the Rules dialog above), not delegated -- the
    // sidebar and its toggle button live in the layout itself, rendered
    // once per page load, never inside a Livewire component's own
    // re-rendered root.
    const sidebarToggle = document.getElementById('sidebar-menu-toggle');
    const appSidebar = document.getElementById('app-sidebar');
    const appSidebarBackdrop = document.getElementById('app-sidebar-backdrop');

    if (sidebarToggle && appSidebar && appSidebarBackdrop) {
        const closeSidebar = () => {
            appSidebar.classList.remove('open');
            appSidebarBackdrop.classList.remove('open');
        };

        sidebarToggle.addEventListener('click', () => {
            appSidebar.classList.toggle('open');
            appSidebarBackdrop.classList.toggle('open');
        });

        appSidebarBackdrop.addEventListener('click', closeSidebar);

        document.querySelectorAll('[data-sidebar-close]').forEach((link) => {
            link.addEventListener('click', closeSidebar);
        });
    }

    // ---- Rooms menu (navbar, every page) ----------------------------------
    // Delegated, not bound directly like the sidebar toggle above -- unlike
    // the sidebar, <livewire:rooms-menu>'s own root re-renders on every
    // action it takes (joinByCode's error message, for one), and a direct
    // binding would silently stop working the first time that happens.
    // The toggle button and panel themselves carry wire:ignore.self so
    // Livewire's morph never resets the open/closed state JS manages here
    // out from under a re-render -- the same fix class as the scoreboard-
    // drawer's dialog[open] bug documented in docs/DEVLOG.md, applied
    // proactively here instead of after the fact.
    document.addEventListener('click', (event) => {
        const toggle = event.target.closest('#rooms-menu-toggle');
        const panel = document.getElementById('rooms-menu-panel');

        if (!panel) {
            return;
        }

        if (toggle) {
            const willOpen = panel.hasAttribute('hidden');
            panel.toggleAttribute('hidden', !willOpen);
            toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');

            // Positioned in JS, not left to the CSS right-0/top-full
            // classes alone -- those assume the toggle button sits at
            // the screen's right edge, which is only true when
            // .button-bar .right fits on one line. On a narrow viewport
            // it wraps, the button can land anywhere on that second
            // line, and a fixed right-0 offset then pushed the panel
            // partly off the left edge of the screen entirely (confirmed
            // live: negative getBoundingClientRect().left). Measuring the
            // toggle's real position and clamping the panel to the
            // viewport works regardless of where wrapping puts it.
            if (willOpen) {
                const toggleRect = toggle.getBoundingClientRect();
                const margin = 8;
                const panelWidth = panel.offsetWidth;
                const left = Math.max(
                    margin,
                    Math.min(toggleRect.right - panelWidth, window.innerWidth - panelWidth - margin),
                );

                panel.style.position = 'fixed';
                panel.style.top = `${toggleRect.bottom + margin}px`;
                panel.style.left = `${left}px`;
                panel.style.right = 'auto';
            }

            return;
        }

        if (!panel.hasAttribute('hidden') && !panel.contains(event.target)) {
            panel.setAttribute('hidden', '');
            document.getElementById('rooms-menu-toggle')?.setAttribute('aria-expanded', 'false');
        }
    });

    // ---- Generic low-stim dialogs (<x-dialog>/<x-confirm-dialog>) --------
    // Delegated on `document`, not bound per-element like the Rules dialog
    // above, because these dialogs and their trigger buttons commonly live
    // inside Livewire-managed markup that gets morphed/replaced after a
    // network round trip (e.g. Family Feud's "Options" panel). A listener
    // bound directly to one of those elements at DOMContentLoaded time
    // would silently stop working the first time Livewire re-renders it;
    // delegating on `document` keeps working regardless of how many times
    // the underlying elements get replaced.
    document.addEventListener('click', (event) => {
        const opener = event.target.closest('[data-dialog-open]');

        if (opener) {
            const dialog = document.getElementById(opener.dataset.dialogOpen);
            dialog?.showModal();
            dialog?.focus();
            return;
        }

        const closer = event.target.closest('[data-dialog-close]');

        if (closer) {
            closer.closest('dialog')?.close();
            return;
        }

        if (event.target instanceof HTMLDialogElement && event.target.classList.contains('js-dialog')) {
            event.target.close();
        }
    });

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
        const errorEl = document.querySelector('[data-passkey-login-error]');

        button.addEventListener('click', async () => {
            button.disabled = true;
            errorEl?.classList.add('hidden');

            try {
                const response = await Passkeys.verify();
                window.location.href = response.redirect ?? '/dashboard';
            } catch (error) {
                button.disabled = false;

                if (errorEl) {
                    errorEl.textContent = error.message ?? 'Passkey sign-in failed.';
                    errorEl.classList.remove('hidden');
                }
            }
        });
    });

    document.querySelectorAll('[data-passkey-confirm]').forEach((button) => {
        const errorEl = document.querySelector('[data-passkey-confirm-error]');

        button.addEventListener('click', async () => {
            button.disabled = true;
            errorEl?.classList.add('hidden');

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

                if (errorEl) {
                    errorEl.textContent = error.message ?? 'Passkey confirmation failed.';
                    errorEl.classList.remove('hidden');
                }
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

    // ---- Passkey management (add/remove on /profile) --------------------
    //
    // All three flows (naming a new passkey, confirming a password,
    // removing one) use custom <dialog>s instead of window.prompt()/
    // confirm()/alert() -- native OS-chrome dialogs are exactly what this
    // app's low-stim design avoids everywhere else (see the "Rules" dialog
    // in layouts/app.blade.php for the same pattern).
    const passkeyNameDialog = document.getElementById('passkey-name-dialog');
    const passkeyPasswordDialog = document.getElementById('passkey-password-dialog');
    const passkeyRemoveDialog = document.getElementById('passkey-remove-dialog');

    if (passkeyNameDialog && passkeyPasswordDialog && passkeyRemoveDialog) {
        document.querySelectorAll('[data-passkey-dialog-close]').forEach((button) => {
            button.addEventListener('click', () => button.closest('dialog')?.close());
        });

        document.querySelectorAll('dialog.passkey-dialog').forEach((dialog) => {
            dialog.addEventListener('click', (event) => {
                if (event.target === dialog) {
                    dialog.close();
                }
            });
        });

        // Laravel's CSRF cookie, read the same way @laravel/passkeys itself
        // falls back to reading it (there's no csrf-token <meta> tag in
        // layouts/app.blade.php) -- needed here because the passkey removal
        // endpoint is called directly, outside that package's own fetch
        // helpers.
        const csrfHeaders = () => {
            const match = document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='));

            return match ? { 'X-XSRF-TOKEN': decodeURIComponent(match.slice('XSRF-TOKEN='.length)) } : {};
        };

        // Opens the password-confirmation dialog and resolves once the
        // user's session is actually confirmed (by typed password or by
        // passkey) -- rejects if they back out instead. Confirming still
        // counts as the user gesture WebAuthn requires for whatever passkey
        // action gets retried right after this resolves, since that retry
        // happens synchronously in the same call chain as the "Confirm"
        // click, not after a fresh page load.
        const confirmPassword = () => new Promise((resolve, reject) => {
            const form = passkeyPasswordDialog.querySelector('[data-passkey-password-form]');
            const passwordInput = document.getElementById('passkey-password-input');
            const passkeyButton = passkeyPasswordDialog.querySelector('[data-passkey-password-confirm-with-passkey]');
            const errorEl = passkeyPasswordDialog.querySelector('[data-passkey-password-error]');

            const showError = (message) => {
                errorEl.textContent = message;
                errorEl.classList.remove('hidden');
            };

            const cleanup = () => {
                form.removeEventListener('submit', onSubmit);
                passkeyButton.removeEventListener('click', onPasskeyConfirm);
                passkeyPasswordDialog.removeEventListener('close', onClose);
            };

            const succeed = () => {
                cleanup();
                passwordInput.value = '';
                passkeyPasswordDialog.close('confirmed');
                resolve();
            };

            const onSubmit = async (event) => {
                event.preventDefault();
                errorEl.classList.add('hidden');

                try {
                    const response = await fetch('/user/confirm-password', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders() },
                        credentials: 'same-origin',
                        body: JSON.stringify({ password: passwordInput.value }),
                    });

                    if (!response.ok) {
                        const body = await response.json().catch(() => null);
                        showError(body?.errors?.password?.[0] ?? body?.message ?? 'That password is incorrect.');
                        return;
                    }

                    succeed();
                } catch {
                    showError('Something went wrong confirming your password.');
                }
            };

            const onPasskeyConfirm = async () => {
                errorEl.classList.add('hidden');

                try {
                    await Passkeys.verify({
                        routes: { options: '/passkeys/confirm/options', submit: '/passkeys/confirm' },
                    });
                    succeed();
                } catch (error) {
                    showError(error.message ?? 'Passkey confirmation failed.');
                }
            };

            const onClose = () => {
                cleanup();

                if (passkeyPasswordDialog.returnValue !== 'confirmed') {
                    reject(new Error('Password confirmation cancelled.'));
                }
            };

            form.addEventListener('submit', onSubmit);
            passkeyButton.addEventListener('click', onPasskeyConfirm);
            passkeyPasswordDialog.addEventListener('close', onClose);

            errorEl.classList.add('hidden');
            passwordInput.value = '';
            // Reset explicitly -- dialog.close() with no argument (a plain
            // cancel) leaves returnValue untouched, so a stale 'confirmed'
            // from a *previous* successful confirmation would otherwise
            // make the next cancel look like a confirmation too.
            passkeyPasswordDialog.returnValue = '';
            passkeyPasswordDialog.showModal();
            passwordInput.focus();
        });

        // ---- Add a passkey ----
        const nameForm = passkeyNameDialog.querySelector('[data-passkey-name-form]');
        const nameInput = document.getElementById('passkey-name-input');
        const nameError = passkeyNameDialog.querySelector('[data-passkey-name-error]');
        const nameSubmit = nameForm.querySelector('button[type="submit"]');

        const registerPasskey = async (name) => {
            try {
                await Passkeys.register({ name });
                passkeyNameDialog.close();
                window.location.reload();
            } catch (error) {
                // See config/fortify.php's Features::passkeys() -- adding a
                // passkey requires a fresh password confirmation, which
                // @laravel/passkeys surfaces as a plain Error carrying
                // Laravel's own RequirePassword middleware message (no
                // status code survives its fetch wrapper).
                if (error.message === 'Password confirmation required.') {
                    try {
                        await confirmPassword();
                    } catch {
                        return; // Cancelled -- stay on the name dialog, no alarming error for a deliberate back-out.
                    }

                    return registerPasskey(name);
                }

                nameError.textContent = error.message ?? 'Passkey registration failed.';
                nameError.classList.remove('hidden');
            }
        };

        document.querySelectorAll('[data-passkey-add]').forEach((button) => {
            button.addEventListener('click', () => {
                nameError.classList.add('hidden');
                nameInput.value = '';
                passkeyNameDialog.showModal();
                nameInput.focus();
            });
        });

        nameForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            nameError.classList.add('hidden');
            nameSubmit.disabled = true;
            await registerPasskey(nameInput.value.trim());
            nameSubmit.disabled = false;
        });

        // ---- Remove a passkey ----
        const removeNameEl = passkeyRemoveDialog.querySelector('[data-passkey-remove-name]');
        const removeError = passkeyRemoveDialog.querySelector('[data-passkey-remove-error]');
        const removeConfirmBtn = passkeyRemoveDialog.querySelector('[data-passkey-remove-confirm]');
        const removeCancelBtn = passkeyRemoveDialog.querySelector('[data-passkey-remove-cancel]');
        let pendingRemoveId = null;

        const removePasskey = async (id) => {
            const response = await fetch(`/user/passkeys/${id}`, {
                method: 'DELETE',
                headers: { Accept: 'application/json', ...csrfHeaders() },
                credentials: 'same-origin',
            });

            if (response.status === 423) {
                await confirmPassword();

                return removePasskey(id);
            }

            if (!response.ok) {
                const body = await response.json().catch(() => null);

                throw new Error(body?.message ?? 'Could not remove passkey.');
            }
        };

        document.querySelectorAll('[data-passkey-remove-trigger]').forEach((button) => {
            button.addEventListener('click', () => {
                pendingRemoveId = button.dataset.passkeyId;
                removeNameEl.textContent = button.dataset.passkeyName ?? '';
                removeError.classList.add('hidden');
                passkeyRemoveDialog.showModal();
            });
        });

        removeCancelBtn.addEventListener('click', () => passkeyRemoveDialog.close());

        removeConfirmBtn.addEventListener('click', async () => {
            removeError.classList.add('hidden');
            removeConfirmBtn.disabled = true;

            try {
                await removePasskey(pendingRemoveId);
                passkeyRemoveDialog.close();
                window.location.reload();
            } catch (error) {
                if (error.message !== 'Password confirmation cancelled.') {
                    removeError.textContent = error.message ?? 'Could not remove passkey.';
                    removeError.classList.remove('hidden');
                }
            } finally {
                removeConfirmBtn.disabled = false;
            }
        });
    }
});
