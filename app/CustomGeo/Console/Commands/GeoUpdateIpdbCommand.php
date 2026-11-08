<?php

namespace App\CustomGeo\Console\Commands;

use App\CustomGeo\MaxMind\MaxMindUpdater;
use Illuminate\Console\Command;
use Throwable;

/**
 * 下载并替换 GeoLite2 MMDB（官方源，需要 MaxMind license key）。
 *
 * 流程：下载 → 解压 → 校验（类型/构建时间/体积）→ 备份旧库 → 原子替换；任一步失败自动回滚。
 * 许可：GeoLite2 data created by MaxMind, available from https://www.maxmind.com（CC BY-SA 4.0）。
 */
class GeoUpdateIpdbCommand extends Command
{
    protected $signature = 'innoshop:geo-update-ipdb
        {--edition=city : city、country 或 both}
        {--license-key= : 覆盖 .env 中的 MAXMIND_LICENSE_KEY}
        {--format=tar.gz : 下载格式 tar.gz 或 zip}
        {--dry-run : 只下载校验，不替换现有数据库}
        {--force : 忽略“已是最新”的提示，强制更新}';

    protected $description = '下载并安全替换 MaxMind GeoLite2 离线 IP 库';

    public function handle(MaxMindUpdater $updater): int
    {
        @set_time_limit(0);

        $updater = new MaxMindUpdater($this->option('license-key') ?: null);

        if ($updater->licenseKey() === '') {
            $this->error('缺少 MaxMind license key。');
            $this->line('1) 免费注册：https://www.maxmind.com/en/geolite2/signup');
            $this->line('2) 在 .env 增加：MAXMIND_LICENSE_KEY=你的key');
            $this->line('3) 重新执行：php artisan innoshop:geo-update-ipdb --edition=both');

            return self::FAILURE;
        }

        $edition = strtolower((string) $this->option('edition'));
        $editions = match ($edition) {
            'both'    => ['city', 'country'],
            'city'    => ['city'],
            'country' => ['country'],
            default   => null,
        };

        if ($editions === null) {
            $this->error('--edition 只能是 city / country / both');

            return self::FAILURE;
        }

        $failed = false;
        $rows   = [];

        foreach ($editions as $item) {
            $this->info("处理 {$item} ...");

            try {
                $result = $updater->update($item, [
                    'dry_run' => (bool) $this->option('dry-run'),
                    'force'   => (bool) $this->option('force'),
                    'format'  => (string) $this->option('format'),
                ], function (string $message): void {
                    $this->line($message);
                });

                $rows[] = [
                    $item,
                    $result['database_type'],
                    $result['build_epoch'] ? date('Y-m-d', (int) $result['build_epoch']) : '-',
                    number_format($result['size'] / 1048576, 1).' MB',
                    $result['backup'] ? basename((string) $result['backup']) : '-',
                    $result['dry_run'] ? '仅校验' : (($result['skipped'] ?? false) ? '已是最新' : '已替换'),
                ];
            } catch (Throwable $e) {
                $failed = true;
                $this->error("{$item} 更新失败：".$e->getMessage());
            }
        }

        if ($rows) {
            $this->newLine();
            $this->table(['版本', '数据库类型', '构建日期', '体积', '备份', '状态'], $rows);
        }

        $this->line('许可：'.config('customgeo.maxmind.license'));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
