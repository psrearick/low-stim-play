<?php

use Livewire\Component;

new class extends Component
{
    //
};
?>

<x-slot:title>Privacy Policy — Low Stim Play</x-slot>
<x-slot:description>What Low Stim Play collects, why, and what it doesn't: no tracking cookies, no third-party analytics, and self-service tools to clear your stats or delete your account.</x-slot>

<div class="flex flex-col items-center" style="gap: inherit;">
    <div class="heading">Privacy Policy</div>

    <div class="about-content">
        <p>This is a small, single-developer hobby app, and this policy is written in plain language rather than legal boilerplate. If anything here is unclear, the <a href="{{ route('about') }}">About page</a> has more on why this app exists.</p>

        <h2>Account information</h2>
        <p>Creating an account stores your name, email address, and a hashed (never plaintext) password. A username and public/private profile visibility toggle are both optional, and can be changed or removed at any time from your profile page. If you upload an avatar image, it's stored on a private object storage bucket and served back only as an image, never shared elsewhere.</p>

        <h2>Signing in</h2>
        <p>You can add a passkey (a device-based credential using WebAuthn) or two-factor authentication as extra ways to sign in securely. Passkey public keys and two-factor recovery codes are stored to make this work, and are never visible to anyone but you.</p>

        <h2>Game history</h2>
        <p>Games you play are recorded against your account so your stats page can show your history. In an online room with other players, each player's own result is recorded separately — clearing your stats or deleting your account only ever affects your own rows, never anyone else's.</p>

        <h2>Cookies and sessions</h2>
        <p>We use a single first-party session cookie to keep you signed in. There are no advertising or tracking cookies, and no third-party analytics scripts anywhere on this site.</p>

        <h2>Analytics</h2>
        <p>We count visits to understand which pages are useful. We do this ourselves, on our own servers, without cookies or any other storage on your device, and without any third-party analytics service.</p>
        <p>When you load a page we record the page address, the country your request came from, and the site that linked you, if any. To estimate how many separate people visited on a given day, we combine your IP address, browser identifier, and a secret value that we replace every day into a one-way fingerprint. Your IP address is never written to disk. The daily secret is destroyed after 48 hours, at which point the fingerprints cannot be linked to each other or to you, even by us.</p>
        <p>This means we cannot recognise you when you return, cannot follow you to other sites, and hold nothing that identifies you. Country lookups are done against a database we download and keep on our own server — your IP address is never sent to a third party to determine your country.</p>

        <h2>Other services we use</h2>
        <p>Account emails (verification, password reset) are sent through Resend, a transactional email provider — your email address and the message content pass through their systems to reach your inbox. If you play Survey Says (Family Feud) and type a guess, that guess text (and only that text — never your name, email, or any other account information) is sent to an AI service to check it against the round's answer list.</p>

        <h2>Your control over your data</h2>
        <p>Your profile page has two self-service tools: "Clear All Stats" permanently deletes your own game history without touching your account, and "Delete Account" permanently deletes your account and everything tied to it. Both take effect immediately and can't be undone.</p>

        <h2>Changes</h2>
        <p>If this policy changes in any meaningful way, this page will be updated with a new date below.</p>

        <p class="text-sm text-text/65">Last updated 2026-08-18.</p>
    </div>
</div>
