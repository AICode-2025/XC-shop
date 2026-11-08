<?php

namespace App\CustomGeo\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * 基于 GeoNames 国家/一级行政区编码创建区域（复用 InnoShop 原生 regions + region_states）。
 *
 * 用途：税率（tax_rates.region_id）与运费插件的区域判断都复用同一套 zone 数据；
 * 国家整国 = state_id 0；指定州省 = 对应 state_id。
 *
 * 示例：
 *   php artisan innoshop:geo-zone-create "EU-Core" --countries=DE,FR,IT
 *   php artisan innoshop:geo-zone-create "US-West" --states=US:CA,US:WA,US:OR
 */
class GeoZoneCreateCommand extends Command
{
    protected $signature = 'innoshop:geo-zone-create
        {name : 区域名称（同名则更新）}
        {--countries= : 整国范围，ISO2 逗号分隔，如 DE,FR}
        {--states= : 州省范围，国家:州代码，逗号分隔，如 US:CA,US:NY}
        {--description= : 区域描述}';

    protected $description = '基于国家/州省编码创建或更新区域（复用原生 regions / region_states）';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));

        if ($name === '') {
            $this->error('区域名称不能为空');

            return self::FAILURE;
        }

        if (! Schema::hasTable('regions') || ! Schema::hasTable('region_states')) {
            $this->error('缺少 regions / region_states 表，请先执行 php artisan migrate');

            return self::FAILURE;
        }

        try {
            $rows = array_merge(
                $this->countryRows((string) $this->option('countries')),
                $this->stateRows((string) $this->option('states'))
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $rows) {
            $this->error('没有可用的国家/州省编码，请检查 --countries 或 --states');

            return self::FAILURE;
        }

        $rows = array_values(array_unique($rows, SORT_REGULAR));
        $now  = now();

        $regionId = DB::table('regions')->where('name', $name)->value('id');

        if ($regionId) {
            DB::table('regions')->where('id', $regionId)->update([
                'description' => (string) $this->option('description'),
                'active'      => true,
                'updated_at'  => $now,
            ]);
            $this->line("更新已有区域：{$name}（#{$regionId}）");
        } else {
            $regionId = DB::table('regions')->insertGetId([
                'name'        => $name,
                'description' => (string) $this->option('description'),
                'position'    => 0,
                'active'      => true,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
            $this->line("创建区域：{$name}（#{$regionId}）");
        }

        DB::table('region_states')->where('region_id', $regionId)->delete();

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('region_states')->insert(array_map(static fn (array $row): array => [
                'region_id'  => $regionId,
                'country_id' => $row['country_id'],
                'state_id'   => $row['state_id'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }

        $countryCount = count(array_filter($rows, static fn (array $row): bool => $row['state_id'] === 0));
        $stateCount   = count($rows) - $countryCount;

        $this->info("区域已就绪：整国 {$countryCount} 个，州省 {$stateCount} 个。");
        $this->line('下一步：后台「税率」中选择该区域，或在运费插件中按 region 判断；见文档区域规则章节。');

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{country_id: int, state_id: int}>
     */
    private function countryRows(string $input): array
    {
        $codes = array_values(array_filter(array_map(
            static fn (string $code): string => strtoupper(trim($code)),
            explode(',', $input)
        )));

        if (! $codes) {
            return [];
        }

        $countries = DB::table('countries')->whereIn('code', $codes)->pluck('id', 'code');
        $missing   = array_diff($codes, $countries->keys()->all());

        if ($missing) {
            throw new \RuntimeException('未找到国家编码：'.implode(',', $missing));
        }

        return $countries->map(static fn ($id): array => ['country_id' => (int) $id, 'state_id' => 0])->values()->all();
    }

    /**
     * @return array<int, array{country_id: int, state_id: int}>
     */
    private function stateRows(string $input): array
    {
        $pairs = array_values(array_filter(array_map('trim', explode(',', $input))));
        if (! $pairs) {
            return [];
        }

        $rows = [];

        foreach ($pairs as $pair) {
            $parts = preg_split('/[:|\\/]/', $pair) ?: [];
            if (count($parts) !== 2) {
                throw new \RuntimeException("州省格式应为 国家:州代码，例如 US:CA，收到：{$pair}");
            }

            [$countryCode, $stateCode] = [strtoupper(trim($parts[0])), trim($parts[1])];

            $countryId = DB::table('countries')->where('code', $countryCode)->value('id');
            if (! $countryId) {
                throw new \RuntimeException("未找到国家编码：{$countryCode}");
            }

            $stateId = DB::table('states')
                ->where('country_code', $countryCode)
                ->where('code', $stateCode)
                ->value('id');

            if (! $stateId) {
                throw new \RuntimeException("未找到州省编码：{$countryCode}:{$stateCode}（请先执行 innoshop:geo-import）");
            }

            $rows[] = ['country_id' => (int) $countryId, 'state_id' => (int) $stateId];
        }

        return $rows;
    }
}
