import { Passkeys } from '@laravel/passkeys';
import Cropper from 'cropperjs';

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

// ---- Suppress edge-swipe back/forward navigation -----------------------
// app.css's `overscroll-behavior-x: none` only stops the rubber-band/
// scroll-chaining style of swipe navigation (mainly Chrome). It does
// nothing for iOS Safari's edge-swipe-back gesture, which is a separate
// system gesture recognizer that isn't governed by overscroll-behavior at
// all -- confirmed still navigating away with that CSS alone in place.
// The recognizer only arms when a touch starts within a narrow band of
// the screen edge, so intercepting touchmove there (and only there, and
// only once the drag is clearly horizontal rather than a vertical scroll
// that happens to start near the edge) blocks the gesture without
// affecting normal scrolling or any other touch interaction elsewhere on
// the page. Most disruptive on Word Hive (see docs/DEVLOG.md), whose
// honeycomb runs edge-to-edge on narrow screens, but the same drag can
// start from any page, hence handling it globally rather than per-game.
(() => {
    const EDGE_PX = 24;
    let watching = false;
    let startX = 0;
    let startY = 0;

    document.addEventListener('touchstart', (e) => {
        const touch = e.touches[0];

        if (!touch) {
            watching = false;

            return;
        }

        startX = touch.clientX;
        startY = touch.clientY;
        watching = startX <= EDGE_PX || startX >= window.innerWidth - EDGE_PX;
    }, { passive: true });

    document.addEventListener('touchmove', (e) => {
        if (!watching) {
            return;
        }

        const touch = e.touches[0];

        if (!touch) {
            return;
        }

        const dx = touch.clientX - startX;
        const dy = touch.clientY - startY;

        if (Math.abs(dx) > Math.abs(dy)) {
            e.preventDefault();
        }
    }, { passive: false });

    document.addEventListener('touchend', () => {
        watching = false;
    });
})();

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

    // ---- App sidebar navigation (site-wide menu drawer, every page) ------
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

    // ---- Stats menu drawer (stats pages only) -----------------------------
    // Same shape as the site menu drawer above -- a second, independent
    // off-canvas panel, only rendered (toggle button included) on pages
    // that opt into `<x-slot:sidebar>1</x-slot:sidebar>` (see
    // layouts/app.blade.php and resources/views/components/stats-sidebar.
    // blade.php). Bound directly, not delegated, for the same reason the
    // site menu drawer above is.
    const statsSidebarToggle = document.getElementById('stats-sidebar-toggle');
    const statsSidebar = document.getElementById('stats-sidebar');
    const statsSidebarBackdrop = document.getElementById('stats-sidebar-backdrop');

    if (statsSidebarToggle && statsSidebar && statsSidebarBackdrop) {
        const closeStatsSidebar = () => {
            statsSidebar.classList.remove('open');
            statsSidebarBackdrop.classList.remove('open');
        };

        statsSidebarToggle.addEventListener('click', () => {
            statsSidebar.classList.toggle('open');
            statsSidebarBackdrop.classList.toggle('open');
        });

        statsSidebarBackdrop.addEventListener('click', closeStatsSidebar);

        document.querySelectorAll('[data-stats-sidebar-close]').forEach((link) => {
            link.addEventListener('click', closeStatsSidebar);
        });
    }

    // ---- Game Menu drawer (game/room pages only) ---------------------------
    // Same shape as the site menu/stats menu drawers above -- a third,
    // independent off-canvas panel, anchored right, only rendered (toggle
    // button included) on pages that set $rules or $fullscreenToggle (see
    // layouts/app.blade.php). Previously a real <x-dialog> modal (docs/
    // TODO.md's former Task 33; adopted specifically to stop it
    // overlapping the score/timer HUD -- see the navbar redesign entries
    // in docs/DEVLOG.md) -- converted to a drawer to match the site's
    // other two off-canvas menus instead of being the one holdout modal.
    const gameMenuToggle = document.getElementById('game-menu-toggle');
    const gameMenuSidebar = document.getElementById('game-menu-sidebar');
    const gameMenuBackdrop = document.getElementById('game-menu-backdrop');

    if (gameMenuToggle && gameMenuSidebar && gameMenuBackdrop) {
        const closeGameMenu = () => {
            gameMenuSidebar.classList.remove('open');
            gameMenuBackdrop.classList.remove('open');
        };

        gameMenuToggle.addEventListener('click', () => {
            gameMenuSidebar.classList.toggle('open');
            gameMenuBackdrop.classList.toggle('open');
        });

        gameMenuBackdrop.addEventListener('click', closeGameMenu);
    }

    // ---- Navbar dropdown (Account, every page) -----------------------------
    // Delegated, not bound directly like the sidebar toggles above -- the
    // Account menu (<x-user-menu>) isn't a Livewire component at all, but
    // this is still the most robust place for its open/close/position
    // behavior regardless. (Rooms used to be another consumer of this same
    // helper -- it moved into the site menu drawer itself.)
    const registerNavbarDropdown = (toggleId, panelId) => {
        document.addEventListener('click', (event) => {
            const toggle = event.target.closest(`#${toggleId}`);
            const panel = document.getElementById(panelId);

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
                document.getElementById(toggleId)?.setAttribute('aria-expanded', 'false');
            }
        });
    };

    registerNavbarDropdown('user-menu-toggle', 'user-menu-panel');

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

    // ---- Close the Game Menu drawer after a real action (game pages only) --
    // Originally needed because #game-menu-dialog was a real modal that
    // blocked interaction with the rest of the page while open -- confirmed
    // live as a real bug, not just a theoretical one: Word Grid's own
    // post-game "Challenge a Friend" button renders in the page body, not
    // inside that dialog, and with it left open on top after End Game,
    // Playwright's click() on "Challenge a Friend" hung waiting for an
    // element that was present but non-interactive behind the modal's own
    // backdrop. Kept the same auto-close behavior now that the Game Menu
    // is a non-modal drawer instead (docs/TODO.md's former Task 33) --
    // leaving it open after an action still hides page content behind its
    // own backdrop, even though nothing blocks interaction with it anymore.
    //
    // Only closes on a click with a wire:click ancestor within
    // #game-actions -- not on *every* click there. Farkle/Family Feud's
    // own End Game/Reveal route through a <x-confirm-dialog> that's
    // teleported *inside* #game-actions alongside its trigger, and a plain
    // data-dialog-close Cancel button inside that confirm dialog has no
    // wire:click -- so canceling returns to the Game Menu's own options
    // instead of closing it. Only the confirm button itself (wire:click +
    // data-dialog-close together) actually takes the action, so that's
    // what closes the drawer.
    document.addEventListener('click', (event) => {
        if (event.target.closest('#game-actions') && event.target.closest('[wire\\:click]')) {
            document.getElementById('game-menu-sidebar')?.classList.remove('open');
            document.getElementById('game-menu-backdrop')?.classList.remove('open');
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

    // ---- Two-factor challenge: toggle between code and recovery-code forms ----
    const twoFactorToggle = document.getElementById('two-factor-toggle-recovery');
    const twoFactorCodeForm = document.getElementById('two-factor-code-form');
    const twoFactorRecoveryForm = document.getElementById('two-factor-recovery-form');

    if (twoFactorToggle && twoFactorCodeForm && twoFactorRecoveryForm) {
        twoFactorToggle.addEventListener('click', () => {
            twoFactorCodeForm.classList.toggle('hidden');
            twoFactorRecoveryForm.classList.toggle('hidden');
            twoFactorRecoveryForm.classList.toggle('flex');
            twoFactorToggle.textContent = twoFactorRecoveryForm.classList.contains('hidden')
                ? 'Use a recovery code instead'
                : 'Use an authentication code instead';
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

        // ---- Two-factor authentication (enable/confirm/regenerate/disable) ----
        // Reuses the same confirmPassword()/csrfHeaders() helpers defined
        // above for passkey management -- Fortify's 2FA endpoints require
        // password confirmation the same way (config/fortify.php's
        // Features::twoFactorAuthentication(['confirmPassword' => true])),
        // returning the same 423 status on an unconfirmed session.
        const twoFactorEnableTrigger = document.getElementById('two-factor-enable-trigger');
        const twoFactorSetupDialog = document.getElementById('two-factor-setup-dialog');
        const twoFactorQrCode = document.getElementById('two-factor-qr-code');
        const twoFactorConfirmForm = document.getElementById('two-factor-confirm-form');
        const twoFactorConfirmCode = document.getElementById('two-factor-confirm-code');
        const twoFactorConfirmError = document.getElementById('two-factor-confirm-error');
        const twoFactorRecoveryCodesTrigger = document.getElementById('two-factor-recovery-codes-trigger');
        const twoFactorRecoveryCodesDialog = document.getElementById('two-factor-recovery-codes-dialog');
        const twoFactorRecoveryCodesList = document.getElementById('two-factor-recovery-codes-list');
        const twoFactorRecoveryCodesError = document.getElementById('two-factor-recovery-codes-error');
        const twoFactorRegenerateBtn = document.getElementById('two-factor-regenerate-recovery-codes');
        const twoFactorDisableConfirmBtn = document.getElementById('two-factor-disable-confirm');

        if (twoFactorSetupDialog && twoFactorRecoveryCodesDialog) {
            const loadQrCode = async () => {
                const response = await fetch('/user/two-factor-qr-code', {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                const body = await response.json();
                twoFactorQrCode.innerHTML = body.svg;
            };

            const loadRecoveryCodes = async () => {
                const response = await fetch('/user/two-factor-recovery-codes', {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                const codes = await response.json();
                twoFactorRecoveryCodesList.innerHTML = '';
                codes.forEach((code) => {
                    const li = document.createElement('li');
                    li.textContent = code;
                    twoFactorRecoveryCodesList.append(li);
                });
            };

            twoFactorEnableTrigger?.addEventListener('click', async () => {
                twoFactorEnableTrigger.disabled = true;

                try {
                    const response = await fetch('/user/two-factor-authentication', {
                        method: 'POST',
                        headers: { Accept: 'application/json', ...csrfHeaders() },
                        credentials: 'same-origin',
                    });

                    if (response.status === 423) {
                        await confirmPassword();
                        twoFactorEnableTrigger.disabled = false;
                        twoFactorEnableTrigger.click();
                        return;
                    }

                    if (!response.ok) {
                        throw new Error('Could not enable two-factor authentication.');
                    }

                    await loadQrCode();
                    twoFactorConfirmCode.value = '';
                    twoFactorConfirmError.classList.add('hidden');
                    twoFactorSetupDialog.showModal();
                } catch (error) {
                    if (error.message !== 'Password confirmation cancelled.') {
                        alert(error.message ?? 'Could not enable two-factor authentication.');
                    }
                } finally {
                    twoFactorEnableTrigger.disabled = false;
                }
            });

            twoFactorConfirmForm?.addEventListener('submit', async (event) => {
                event.preventDefault();
                twoFactorConfirmError.classList.add('hidden');

                try {
                    const response = await fetch('/user/confirmed-two-factor-authentication', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders() },
                        credentials: 'same-origin',
                        body: JSON.stringify({ code: twoFactorConfirmCode.value }),
                    });

                    if (!response.ok) {
                        const body = await response.json().catch(() => null);
                        twoFactorConfirmError.textContent = body?.errors?.code?.[0] ?? body?.message ?? 'That code is invalid.';
                        twoFactorConfirmError.classList.remove('hidden');
                        return;
                    }

                    twoFactorSetupDialog.close();
                    await loadRecoveryCodes();
                    twoFactorRecoveryCodesError.classList.add('hidden');
                    twoFactorRecoveryCodesDialog.showModal();
                } catch {
                    twoFactorConfirmError.textContent = 'Something went wrong confirming your code.';
                    twoFactorConfirmError.classList.remove('hidden');
                }
            });

            // Reloads once the recovery-codes dialog closes, whether via the
            // "Done" button or a backdrop click -- the page's server-rendered
            // "enabled"/"disabled" panel state (mount()'s $twoFactorEnabled)
            // only reflects reality after a fresh load, the same reload
            // pattern the passkey add/remove flows below already use.
            twoFactorRecoveryCodesDialog.addEventListener('close', () => window.location.reload());

            twoFactorRecoveryCodesTrigger?.addEventListener('click', async () => {
                twoFactorRecoveryCodesTrigger.disabled = true;
                twoFactorRecoveryCodesError.classList.add('hidden');

                try {
                    await loadRecoveryCodes();
                    twoFactorRecoveryCodesDialog.showModal();
                } catch {
                    alert('Could not load recovery codes.');
                } finally {
                    twoFactorRecoveryCodesTrigger.disabled = false;
                }
            });

            twoFactorRegenerateBtn?.addEventListener('click', async () => {
                twoFactorRegenerateBtn.disabled = true;
                twoFactorRecoveryCodesError.classList.add('hidden');

                try {
                    const response = await fetch('/user/two-factor-recovery-codes', {
                        method: 'POST',
                        headers: { Accept: 'application/json', ...csrfHeaders() },
                        credentials: 'same-origin',
                    });

                    if (response.status === 423) {
                        await confirmPassword();
                        twoFactorRegenerateBtn.disabled = false;
                        twoFactorRegenerateBtn.click();
                        return;
                    }

                    if (!response.ok) {
                        throw new Error('Could not generate new recovery codes.');
                    }

                    await loadRecoveryCodes();
                } catch (error) {
                    if (error.message !== 'Password confirmation cancelled.') {
                        twoFactorRecoveryCodesError.textContent = error.message ?? 'Could not generate new recovery codes.';
                        twoFactorRecoveryCodesError.classList.remove('hidden');
                    }
                } finally {
                    twoFactorRegenerateBtn.disabled = false;
                }
            });

            twoFactorDisableConfirmBtn?.addEventListener('click', async () => {
                twoFactorDisableConfirmBtn.disabled = true;

                try {
                    const response = await fetch('/user/two-factor-authentication', {
                        method: 'DELETE',
                        headers: { Accept: 'application/json', ...csrfHeaders() },
                        credentials: 'same-origin',
                    });

                    if (response.status === 423) {
                        await confirmPassword();
                        twoFactorDisableConfirmBtn.disabled = false;
                        twoFactorDisableConfirmBtn.click();
                        return;
                    }

                    if (!response.ok) {
                        throw new Error('Could not disable two-factor authentication.');
                    }

                    window.location.reload();
                } catch (error) {
                    twoFactorDisableConfirmBtn.disabled = false;

                    if (error.message !== 'Password confirmation cancelled.') {
                        alert(error.message ?? 'Could not disable two-factor authentication.');
                    }
                }
            });
        }

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

    // ---- Avatar crop-before-upload (docs/concerns.md) ---------------------
    // The <input type="file"> deliberately has no wire:model -- the raw
    // file the user picked never reaches Livewire. Selecting a file only
    // opens this dialog against a local blob: URL (no network round trip);
    // the only thing ever handed to Livewire is the cropped square the
    // user confirms, via $wire.$upload() -- the JS upload API is the only
    // entry point that accepts a File Livewire didn't itself receive from
    // the raw <input>, which a normal wire:model upload requires.
    const avatarInput = document.getElementById('avatar-upload-input');
    const avatarCropDialog = document.getElementById('avatar-crop-dialog');

    if (avatarInput && avatarCropDialog) {
        const cropImage = document.getElementById('avatar-crop-image');
        const cropCanvasWrapper = document.getElementById('avatar-crop-canvas-wrapper');
        const cropConfirm = document.getElementById('avatar-crop-confirm');
        const cropCancel = document.getElementById('avatar-crop-cancel');
        const cropError = document.getElementById('avatar-crop-error');
        let objectUrl = null;
        let cropper = null;

        // Same tree Cropper.js's own DEFAULT_TEMPLATE builds (see
        // node_modules/cropperjs/dist/cropper.esm.raw.js), plus a fixed
        // 1:1 aspect ratio on <cropper-selection> for a square avatar.
        // Not the library's default -- passed explicitly so the only
        // difference from upstream is that one attribute, not a
        // hand-guessed rebuild of the whole tree (which previously left
        // <cropper-selection> stuck at 0x0; see the profile.blade.php
        // comment above this dialog for how that was confirmed).
        const cropperTemplate = '<cropper-canvas background style="display:block;width:100%;height:280px;border-radius:0.25rem;overflow:hidden;">'
            + '<cropper-image rotatable scalable skewable translatable></cropper-image>'
            + '<cropper-shade hidden></cropper-shade>'
            + '<cropper-handle action="select" plain></cropper-handle>'
            + '<cropper-selection aspect-ratio="1" initial-coverage="0.8" movable resizable>'
            + '<cropper-grid role="grid" bordered covered></cropper-grid>'
            + '<cropper-crosshair centered></cropper-crosshair>'
            + '<cropper-handle action="move" theme-color="rgba(255, 255, 255, 0.35)"></cropper-handle>'
            + '<cropper-handle action="n-resize"></cropper-handle>'
            + '<cropper-handle action="e-resize"></cropper-handle>'
            + '<cropper-handle action="s-resize"></cropper-handle>'
            + '<cropper-handle action="w-resize"></cropper-handle>'
            + '<cropper-handle action="ne-resize"></cropper-handle>'
            + '<cropper-handle action="nw-resize"></cropper-handle>'
            + '<cropper-handle action="se-resize"></cropper-handle>'
            + '<cropper-handle action="sw-resize"></cropper-handle>'
            + '</cropper-selection>'
            + '</cropper-canvas>';

        const destroyCropper = () => {
            cropper?.destroy();
            cropper = null;
        };

        const closeCropDialog = () => {
            avatarCropDialog.close();
            avatarInput.value = '';
            destroyCropper();

            if (objectUrl) {
                URL.revokeObjectURL(objectUrl);
                objectUrl = null;
            }
        };

        avatarInput.addEventListener('change', () => {
            const file = avatarInput.files[0];

            if (!file) {
                return;
            }

            if (objectUrl) {
                URL.revokeObjectURL(objectUrl);
            }

            destroyCropper();
            objectUrl = URL.createObjectURL(file);
            cropImage.src = objectUrl;
            cropError.classList.add('hidden');
            avatarCropDialog.showModal();
            cropper = new Cropper(cropImage, { container: cropCanvasWrapper, template: cropperTemplate });
        });

        cropCancel.addEventListener('click', () => closeCropDialog());

        avatarCropDialog.addEventListener('click', (event) => {
            if (event.target === avatarCropDialog) {
                closeCropDialog();
            }
        });

        cropConfirm.addEventListener('click', async () => {
            cropError.classList.add('hidden');
            cropConfirm.disabled = true;

            try {
                const selection = cropper.getCropperSelection();
                const canvas = await selection.$toCanvas({ width: 512, height: 512 });
                const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9));
                const croppedFile = new File([blob], 'avatar.jpg', { type: 'image/jpeg' });
                const wireRoot = avatarInput.closest('[wire\\:id]');
                const component = window.Livewire.find(wireRoot.getAttribute('wire:id'));

                await new Promise((resolve, reject) => {
                    component.$upload('avatarUpload', croppedFile, resolve, reject);
                });

                closeCropDialog();
            } catch (error) {
                cropError.textContent = 'Could not crop that image. Please try again.';
                cropError.classList.remove('hidden');
            } finally {
                cropConfirm.disabled = false;
            }
        });
    }
});
