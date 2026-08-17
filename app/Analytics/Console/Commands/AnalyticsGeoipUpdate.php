<?php

namespace App\Analytics\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PharData;
use Throwable;

/**
 * Downloads the current GeoLite2-Country database for
 * App\Analytics\Geo\CountryResolver's maxmind driver. MaxMind's license
 * does not permit redistributing the .mmdb, so it is never committed --
 * see .gitignore's storage/app/geoip/ entry -- and this command is how
 * both local dev and any deployment obtain their own copy.
 *
 * Deliberately not scheduled. Laravel Cloud's filesystem is ephemeral
 * and per-replica -- a scheduled run only ever updates whichever single
 * replica happens to catch it, never reaches any sibling replica, and
 * is wiped on the next deploy regardless. Instead this runs as a Laravel
 * Cloud Build Command (not a Deploy Command -- deploy-command filesystem
 * writes are explicitly discarded before the app goes live), so the
 * .mmdb is baked into the image and present on every replica from boot.
 * That means the database only actually refreshes on deploy, not weekly
 * -- acceptable here since GeoLite2 itself only updates roughly monthly
 * and this app deploys at least that often; see docs/DEVLOG.md's
 * Analytics section for the full reasoning.
 */
#[Signature('analytics:geoip-update')]
#[Description('Download the current GeoLite2-Country database for the maxmind geo driver')]
class AnalyticsGeoipUpdate extends Command
{
    private const string EDITION = 'GeoLite2-Country';

    public function handle(): int
    {
        $accountId = config('services.maxmind.account_id');
        $licenseKey = config('services.maxmind.license_key');

        if (! $accountId || ! $licenseKey) {
            $this->error('MAXMIND_ACCOUNT_ID and MAXMIND_LICENSE_KEY must both be set.');

            return self::FAILURE;
        }

        /** @var string $destination */
        $destination = config('analytics.geo.database_path');
        $tempDir = storage_path('app/geoip/.tmp-'.uniqid());
        File::ensureDirectoryExists($tempDir);

        try {
            $archive = $tempDir.'/'.self::EDITION.'.tar.gz';

            $response = Http::withBasicAuth($accountId, $licenseKey)
                ->timeout(60)
                ->get('https://download.maxmind.com/geoip/databases/'.self::EDITION.'/download', [
                    'suffix' => 'tar.gz',
                ]);

            if (! $response->successful()) {
                $this->error("Download failed with status {$response->status()}.");

                return self::FAILURE;
            }

            File::put($archive, $response->body());

            $phar = new PharData($archive);
            $phar->extractTo($tempDir, overwrite: true);

            $mmdbFiles = glob($tempDir.'/*/'.self::EDITION.'.mmdb');

            if ($mmdbFiles === false || $mmdbFiles === []) {
                $this->error('Downloaded archive did not contain a .mmdb file.');

                return self::FAILURE;
            }

            File::ensureDirectoryExists(dirname($destination));

            // Written alongside the destination (same filesystem) then
            // renamed atomically, so a reader can never observe a
            // half-written file mid-update.
            $atomicTarget = $destination.'.new';
            File::copy($mmdbFiles[0], $atomicTarget);
            rename($atomicTarget, $destination);

            $this->info("Updated {$destination}.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error("geoip update failed: {$e->getMessage()}");

            return self::FAILURE;
        } finally {
            File::deleteDirectory($tempDir);
        }
    }
}
