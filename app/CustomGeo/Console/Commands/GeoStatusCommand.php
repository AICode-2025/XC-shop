<?php

namespace App\CustomGeo\Console\Commands;

use App\CustomGeo\MaxMind\MaxMindUpdater;
use App\CustomGeo\Services\GeoDetectService;
use App\CustomGeo\Services\GeoIpLookupService;
use App\CustomGeo\Support\GeoPaths;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * 地理数据体检：数据量、MMDB 版本、后台开关、许可提示。
 */
class GeoStatusCommand extends Command
{
    protected $signature = 'innoshop:geo-status {--ip= : 顺带测试一个 IP 查询}';

    protected $description = '查看 GeoNames / GeoLite2 数据状态与自动定位开关';

    public function handle(GeoIpLookupService $lookup, GeoDetectService $detect): int
    {
        $tables = ['countries', 'states', 'geo_admin2', 'geo_cities', 'geo_import_runs'];

        $rows = [];
        foreach ($tables as $table) {
            $count = 0;
            try {
                $count = Schema::hasTable($table) ? (int) DB::table($table)->count() : -1;
            } catch (Throwable) {
                $count = -1;
            }
            $rows[] = [$table, $count < 0 ? '表不存在' : number_format($count)];
        }

        $this->table(['数据表', '记录数'], $rows);

        $this->newLine();
        $this->table(['GeoLite2', '值'], [
            ['许可证', config('customgeo.maxmind.license_key') ? '已配置' : '未配置（后台开关与自动定位仍需配置）'],
            ['城市库', GeoPaths::relative(GeoPaths::mmdb('city'))],
            ['国家库', GeoPaths::relative(GeoPaths::mmdb('country'))],
            ['可用', $lookup->isAvailable() ? '是' : '否'],
            ['自动定位开关', $detect->enabled() ? '开启' : '关闭'],
            ['缓存', config('customgeo.cache.enabled') ? '开启（TTL '.config('customgeo.cache.ttl').'s）' : '关闭'],
        ]);

        $updater = new MaxMindUpdater;
        foreach (['city', 'country'] as $edition) {
            $info = $updater->info($edition);
            $this->line(sprintf(
                '%s：%s｜%s｜%s',
                $edition,
                $info['exists'] ? '已安装' : '未安装',
                $info['database_type'] ?: '-',
                $info['exists'] ? round($info['size'] / 1048576, 1).' MB' : '-'
            ));
        }

        $this->newLine();
        $lastRun = null;
        try {
            $lastRun = Schema::hasTable('geo_import_runs') ? DB::table('geo_import_runs')->orderByDesc('id')->first() : null;
        } catch (Throwable) {
            $lastRun = null;
        }
        if ($lastRun) {
            $this->line("最近导入：run#{$lastRun->id}｜{$lastRun->mode}｜{$lastRun->status}｜行 {$lastRun->checkpoint_line}｜城市 {$lastRun->city_count}｜错误 {$lastRun->error_count}");
        } else {
            $this->line('最近导入：无记录（可执行 php artisan innoshop:geo-import）');
        }

        if ($ip = (string) $this->option('ip')) {
            $this->newLine();
            $this->line('查询 '.$ip.'：'.(json_encode($lookup->lookup($ip), JSON_UNESCAPED_UNICODE) ?: '无结果'));
        }

        $this->newLine();
        $this->line('许可：'.config('customgeo.geonames.license'));
        $this->line('许可：'.config('customgeo.maxmind.license'));

        return self::SUCCESS;
    }
}
