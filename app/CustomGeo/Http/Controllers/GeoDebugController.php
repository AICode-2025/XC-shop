<?php

namespace App\CustomGeo\Http\Controllers;

use App\CustomGeo\Services\GeoDetectService;
use App\CustomGeo\Services\GeoIpLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * 调试路由：返回当前访客定位信息（只读，不写 session）。
 *
 * 访问：/customgeo/debug（可选 ?ip=8.8.8.8 指定 IP，?token=xxx 生产环境校验）
 */
class GeoDebugController
{
    public function __invoke(Request $request, GeoIpLookupService $lookup, GeoDetectService $detect): JsonResponse
    {
        if (! (bool) config('customgeo.debug.enabled', true)) {
            abort(404);
        }

        $token = (string) config('customgeo.debug.token', '');
        if ($token !== '' && ! hash_equals($token, (string) $request->query('token', ''))) {
            abort(403, 'invalid debug token');
        }

        $ip     = (string) ($request->query('ip') ?: $request->ip());
        $result = $detect->detect($ip);

        return response()->json([
            'ip'         => $ip,
            'is_public'  => $lookup->isPublicIp($ip),
            'location'   => $result['location'],
            'suggestion' => [
                'currency' => $result['currency'],
                'locale'   => $result['locale'],
            ],
            'detect'     => $detect->debugInfo(),
            'maxmind'    => $lookup->info(),
            'geonames'   => [
                'countries' => $this->count('countries'),
                'states'    => $this->count('states'),
                'admin2'    => $this->count('geo_admin2'),
                'cities'    => $this->count('geo_cities'),
                'last_run'  => $this->lastImportRun(),
            ],
            'license' => [
                'geonames' => (string) config('customgeo.geonames.license'),
                'maxmind'  => (string) config('customgeo.maxmind.license'),
            ],
        ]);
    }

    private function count(string $table): int
    {
        try {
            return Schema::hasTable($table) ? (int) DB::table($table)->count() : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastImportRun(): ?array
    {
        try {
            if (! Schema::hasTable('geo_import_runs')) {
                return null;
            }

            $row = DB::table('geo_import_runs')->orderByDesc('id')->first();
            if (! $row) {
                return null;
            }

            return [
                'id'       => $row->id,
                'mode'     => $row->mode,
                'status'   => $row->status,
                'lines'    => (int) $row->checkpoint_line,
                'cities'   => (int) $row->city_count,
                'errors'   => (int) $row->error_count,
                'started'  => (string) $row->started_at,
                'finished' => (string) ($row->finished_at ?? ''),
            ];
        } catch (Throwable) {
            return null;
        }
    }
}
