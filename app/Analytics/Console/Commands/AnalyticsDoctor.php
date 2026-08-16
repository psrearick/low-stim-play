<?php

namespace App\Analytics\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * The acceptance gate for the recording pipeline's two most expensive,
 * silently-wrong failure modes: an untrusted proxy (every visitor
 * hashing identically -- see App\Analytics\Http\Middleware\
 * RecordPageview) and a missing/renamed geo header. Run this against a
 * deployed URL before trusting any numbers the dashboard shows.
 *
 * Fetches App\Analytics\Http\Controllers\DoctorController's JSON over a
 * real HTTP request (rather than inspecting the CLI process's own,
 * request-less environment) so what it reports reflects how a real
 * visitor's connection actually resolves in production, load balancer
 * and all.
 */
#[Signature('analytics:doctor {url? : Deployed URL to check, e.g. https://example.com (defaults to app.url)}')]
#[Description('Verify trusted-proxy and country-header resolution against a deployed URL before trusting analytics numbers')]
class AnalyticsDoctor extends Command
{
    public function handle(): int
    {
        $url = rtrim($this->argument('url') ?? config('app.url'), '/');
        $endpoint = $url.'/analytics/doctor';

        $this->info("Fetching {$endpoint} ...");

        try {
            $response = Http::timeout(10)->get($endpoint);
        } catch (\Throwable $e) {
            $this->error("Request failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error("Request failed with status {$response->status()}.");
            $this->line($response->body());

            return self::FAILURE;
        }

        $data = $response->json();

        $this->newLine();
        $this->table(['Field', 'Value'], [
            ['Resolved client IP', $data['resolved_client_ip'] ?? '(none)'],
            ['REMOTE_ADDR', $data['remote_addr'] ?? '(none)'],
            ['Differ?', ($data['resolved_ip_differs_from_remote_addr'] ?? false) ? 'yes' : 'no'],
        ]);

        if (! ($data['resolved_ip_differs_from_remote_addr'] ?? false)) {
            $this->warn(
                'The resolved client IP matches REMOTE_ADDR. If this app sits behind a load '.
                'balancer or proxy in this environment, TrustProxies is not configured -- every '.
                'visitor will hash identically and unique-visitor counts will silently read as 1 '.
                "per day forever. See bootstrap/app.php's trustProxies(at: '*')."
            );
        }

        $forwarded = $data['forwarded_headers'] ?? [];
        $this->newLine();
        $this->line('X-Forwarded-* headers:');
        $this->line($forwarded === [] ? '  (none)' : $this->formatHeaders($forwarded));

        $country = $data['country_headers'] ?? [];
        $this->newLine();
        $this->line('Headers matching /country/i:');
        $this->line($country === [] ? '  (none)' : $this->formatHeaders($country));

        if ($country === []) {
            $this->comment(
                "No country header found -- not a blocker: this app's ANALYTICS_GEO_DRIVER ".
                'defaults to maxmind, which resolves country from a local GeoLite2 database '.
                'rather than a proxy header. This is purely informational.'
            );
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array<int, string>>  $headers
     */
    private function formatHeaders(array $headers): string
    {
        return collect($headers)
            ->map(fn (array $values, string $name) => "  {$name}: ".implode(', ', $values))
            ->implode("\n");
    }
}
