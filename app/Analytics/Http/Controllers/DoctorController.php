<?php

namespace App\Analytics\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Backs `GET /analytics/doctor`, the diagnostic endpoint
 * `analytics:doctor` (App\Analytics\Console\Commands\AnalyticsDoctor)
 * fetches over HTTP against a deployed URL. Deliberately unauthenticated
 * -- it only reflects the requester's own connection info back to them
 * (their resolved IP, their own request headers), nothing about any
 * other visitor, so there is nothing here for a gate to protect. Already
 * excluded from tracking via config('analytics.except').
 */
class DoctorController
{
    public function __invoke(Request $request): JsonResponse
    {
        $forwardedHeaders = [];
        $countryHeaders = [];

        foreach ($request->headers->all() as $name => $values) {
            if (str_starts_with($name, 'x-forwarded-')) {
                $forwardedHeaders[$name] = $values;
            }

            if (preg_match('/country/i', $name)) {
                $countryHeaders[$name] = $values;
            }
        }

        $remoteAddr = $request->server('REMOTE_ADDR');
        $resolvedIp = $request->ip();

        return response()->json([
            'resolved_client_ip' => $resolvedIp,
            'remote_addr' => $remoteAddr,
            'resolved_ip_differs_from_remote_addr' => $resolvedIp !== $remoteAddr,
            'forwarded_headers' => $forwardedHeaders,
            'country_headers' => $countryHeaders,
        ]);
    }
}
