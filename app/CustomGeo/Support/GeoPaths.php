<?php

namespace App\CustomGeo\Support;

use RuntimeException;

/**
 * 统一的地理数据路径解析（GeoNames 原始文件、MMDB、备份、日志）。
 *
 * 原始数据一律落在 storage/app/customgeo 下，已在 .gitignore 中忽略，不会进入 Git。
 */
final class GeoPaths
{
    public const EDITIONS = [
        'city'    => 'GeoLite2-City',
        'country' => 'GeoLite2-Country',
    ];

    /**
     * 确保目录存在并返回绝对路径。
     */
    public static function ensureDir(string $dir): string
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("无法创建目录: {$dir}");
        }

        return $dir;
    }

    public static function dataDir(): string
    {
        return self::ensureDir(self::pick('customgeo.paths.data', storage_path('app/customgeo')));
    }

    public static function geonamesDir(): string
    {
        return self::ensureDir(self::pick('customgeo.paths.geonames', self::dataDir().'/geonames'));
    }

    public static function maxmindDir(): string
    {
        return self::ensureDir(self::pick('customgeo.paths.maxmind', self::dataDir().'/maxmind'));
    }

    public static function logDir(): string
    {
        return self::ensureDir(self::pick('customgeo.paths.logs', self::dataDir().'/logs'));
    }

    /**
     * 配置值为空字符串时回退默认值（.env 留空不会导致路径失效）。
     */
    private static function pick(string $key, string $default): string
    {
        $value = config($key);

        return is_string($value) && trim($value) !== '' ? $value : $default;
    }

    public static function geonamesFile(string $fileName): string
    {
        return self::geonamesDir().'/'.$fileName;
    }

    /**
     * GeoLite2 数据库路径：沿用 InnoShop 原生 GEOLITE2_PATH 作为城市库默认位置，
     * 使既有访客统计、geo_location() 等原生链路自动受益。
     */
    public static function mmdb(string $edition = 'city'): string
    {
        $edition = self::normalizeEdition($edition);

        $configured = (string) config("customgeo.maxmind.{$edition}_path", '');
        if ($configured !== '') {
            return $configured;
        }

        return storage_path('app/geolite2/geolite2-'.$edition.'.mmdb');
    }

    public static function backupDir(): string
    {
        return self::ensureDir(self::maxmindDir().'/backup');
    }

    public static function normalizeEdition(string $edition): string
    {
        $edition = strtolower(trim($edition));

        if (! isset(self::EDITIONS[$edition])) {
            throw new RuntimeException("不支持的 MaxMind 版本: {$edition}（可选 city / country）");
        }

        return $edition;
    }

    public static function editionId(string $edition): string
    {
        return self::EDITIONS[self::normalizeEdition($edition)];
    }

    /**
     * 追加式导入日志文件（按天切分）。
     */
    public static function logFile(string $name): string
    {
        return self::logDir().'/'.$name.'-'.date('Ymd').'.log';
    }

    public static function relative(string $path): string
    {
        $base = base_path();

        return str_starts_with($path, $base) ? ltrim(substr($path, strlen($base)), '/\\') : $path;
    }
}
