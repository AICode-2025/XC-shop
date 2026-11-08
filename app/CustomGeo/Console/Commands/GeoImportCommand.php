<?php

namespace App\CustomGeo\Console\Commands;

use App\CustomGeo\GeoNames\GeoNamesImporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * 一键导入 GeoNames 地区数据。
 *
 * GeoNames 数据许可：CC BY 4.0（署名 "GeoNames (https://www.geonames.org/)"）。
 */
class GeoImportCommand extends Command
{
    protected $signature = 'innoshop:geo-import
        {--mode= : full 全量 / slim 精简（默认取 config/customgeo.php）}
        {--countries= : 指定 ISO2 国家（逗号分隔，覆盖精简白名单）}
        {--min-population= : 城市人口下限，0 表示不过滤}
        {--chunk= : 批量写入条数，默认 500}
        {--truncate : 先清空已导入城市/行政2（保留被地址或区域引用的行政1）}
        {--resume : 断点续导（从上一次中断的行继续）}
        {--no-download : 只使用本地已下载文件}
        {--force-download : 强制重新下载官方数据包}
        {--from-file= : 直接指定本地 allCountries.txt 路径}
        {--skip-cities : 不导入城市}
        {--skip-admin2 : 不导入二级行政区}
        {--dry-run : 只统计不写库}
        {--force : 跳过危险操作确认}';

    protected $description = '导入 GeoNames 官方 allCountries / countryInfo 数据（支持全量与精简、断点续导）';

    public function handle(GeoNamesImporter $importer): int
    {
        @set_time_limit(0);
        if ((int) ini_get('memory_limit') !== -1 && $this->toBytes((string) ini_get('memory_limit')) < 1024 ** 3) {
            @ini_set('memory_limit', '1G');
        }

        $options = array_filter([
            'mode'           => $this->option('mode'),
            'countries'      => $this->option('countries'),
            'min_population' => $this->option('min-population'),
            'chunk'          => $this->option('chunk'),
            'truncate'       => (bool) $this->option('truncate'),
            'resume'         => (bool) $this->option('resume'),
            'download'       => ! $this->option('no-download'),
            'force_download' => (bool) $this->option('force-download'),
            'from_file'      => $this->option('from-file'),
            'skip_cities'    => (bool) $this->option('skip-cities'),
            'skip_admin2'    => (bool) $this->option('skip-admin2'),
            'dry_run'        => (bool) $this->option('dry-run'),
        ], static fn ($value) => $value !== null && $value !== false && $value !== '');

        if ($options['truncate'] ?? false) {
            $this->warn('TRUNCATE 模式会删除已导入的城市与未被引用的行政1数据。');
            if (! $this->option('force') && ! $this->confirm('确认继续？', false)) {
                $this->line('已取消。');

                return self::SUCCESS;
            }
        }

        $this->info('GeoNames 数据来源：'.config('customgeo.geonames.base_url'));
        $this->line('许可：'.config('customgeo.geonames.license'));

        try {
            $summary = $importer->run($options, function (string $message): void {
                $this->line($message);
            });
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['项目', '结果'], [
            ['模式', $summary['mode']],
            ['国家', $summary['countries']],
            ['行政1（states）', $summary['admin1']],
            ['行政2（geo_admin2）', $summary['admin2']],
            ['城市（geo_cities）', $summary['cities']],
            ['扫描行数', $summary['lines']],
            ['错误数', $summary['errors']],
            ['耗时', $summary['duration_sec'].' 秒'],
            ['日志', $summary['log']],
        ]);
        $this->info('导入完成。');

        return self::SUCCESS;
    }

    private function toBytes(string $value): int
    {
        $value = trim($value);
        $unit  = strtolower(substr($value, -1));
        $num   = (int) $value;

        return match ($unit) {
            'g' => $num * 1024 ** 3,
            'm' => $num * 1024 ** 2,
            'k' => $num * 1024,
            default => $num,
        };
    }
}
