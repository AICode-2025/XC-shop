<?php

/**
 * zwxc-custom: GeoNames 地区数据 Seeder。
 *
 * 用法：
 *   php artisan db:seed --class=GeoNamesSeeder                       # 精简模式（config 白名单国家）
 *   GEONAMES_MODE=full php artisan db:seed --class=GeoNamesSeeder    # 全量导入
 *   GEONAMES_RESUME=true php artisan db:seed --class=GeoNamesSeeder   # 断点续导
 *   GEONAMES_TRUNCATE=true php artisan db:seed --class=GeoNamesSeeder # 清空后重建（保留被引用数据）
 *
 * 数据许可：GeoNames CC BY 4.0，须署名 https://www.geonames.org/
 */

namespace Database\Seeders;

use App\CustomGeo\GeoNames\GeoNamesImporter;
use Illuminate\Database\Seeder;
use Throwable;

class GeoNamesSeeder extends Seeder
{
    /**
     * @throws Throwable
     */
    public function run(): void
    {
        $options = [
            'mode'      => env('GEONAMES_MODE', config('customgeo.import.mode', 'slim')),
            'countries' => env('GEONAMES_COUNTRIES'),
            'truncate'  => filter_var(env('GEONAMES_TRUNCATE', false), FILTER_VALIDATE_BOOLEAN),
            'resume'    => filter_var(env('GEONAMES_RESUME', false), FILTER_VALIDATE_BOOLEAN),
            'download'  => filter_var(env('GEONAMES_DOWNLOAD', true), FILTER_VALIDATE_BOOLEAN),
        ];

        $this->command?->info("GeoNames Seeder 启动｜模式：{$options['mode']}");

        try {
            $summary = app(GeoNamesImporter::class)->run($options, function (string $message): void {
                $this->command?->line($message);
            });
        } catch (Throwable $e) {
            $this->command?->error('GeoNames Seeder 失败：'.$e->getMessage());

            throw $e;
        }

        $this->command?->info(sprintf(
            '导入完成：国家 %d｜行政1 %d｜行政2 %d｜城市 %d｜错误 %d｜耗时 %ss',
            $summary['countries'],
            $summary['admin1'],
            $summary['admin2'],
            $summary['cities'],
            $summary['errors'],
            $summary['duration_sec']
        ));
    }
}
