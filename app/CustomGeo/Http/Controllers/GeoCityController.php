<?php

namespace App\CustomGeo\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * 城市候选接口：供地址表单做城市输入建议（不改变原有 city 文本字段与提交结构）。
 *
 * 访问：/customgeo/cities?country=US&state=CA&q=Los&limit=200
 */
class GeoCityController
{
    public function __invoke(Request $request): JsonResponse
    {
        $country = strtoupper((string) $request->query('country', ''));
        $state   = (string) $request->query('state', '');
        $keyword = trim((string) $request->query('q', ''));
        $limit   = min(500, max(1, (int) $request->query('limit', 200)));

        if ($country === '' || ! preg_match('/^[A-Z]{2}$/', $country)) {
            return response()->json([]);
        }

        try {
            if (! Schema::hasTable('geo_cities')) {
                return response()->json([]);
            }

            $query = DB::table('geo_cities')
                ->select('geoname_id', 'name', 'name_local', 'admin1_code', 'timezone')
                ->where('country_code', $country)
                ->where('active', true);

            if ($state !== '') {
                $query->where('admin1_code', $state);
            }

            if ($keyword !== '') {
                $query->where(function ($builder) use ($keyword) {
                    $builder->where('name', 'like', $keyword.'%')
                        ->orWhere('name_local', 'like', $keyword.'%');
                });
            }

            $rows = $query->orderByDesc('population')->orderBy('name')->limit($limit)->get();

            return response()->json($rows->map(static fn ($row): array => [
                'id'         => (int) $row->geoname_id,
                'name'       => (string) $row->name,
                'name_local' => (string) $row->name_local,
                'state_code' => (string) $row->admin1_code,
                'timezone'   => (string) $row->timezone,
            ])->all());
        } catch (Throwable) {
            return response()->json([]);
        }
    }
}
