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
 * both local dev and any deployment obtain their own copy. Scheduled
 * weekly (bootstrap/app.php).
 *
 * Laravel Cloud has an ephemeral filesystem, so relying on this schedule
 * alone means the .mmdb is missing after every fresh deploy until the
 * next weekly run -- run this as a deploy step too, or move the file to
 * durable storage (S3) and pull it on boot. Not built here; see
 * docs/DEVLOG.md's Analytics section.
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
