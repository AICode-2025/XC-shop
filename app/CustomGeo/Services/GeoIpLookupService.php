<?php

namespace App\CustomGeo\Services;

use App\CustomGeo\Support\GeoPaths;
use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 离线 IP 定位服务（GeoLite2-City 优先，GeoLite2-Country 兜底）。
 *
 * - 内网/保留地址、非法 IP、库中无记录：静默返回空数组，绝不抛异常；
 * - 高频 IP 结果做缓存，缓存 key 带数据库版本，换成新库后自动失效（无需手动清缓存）。
 *
 * 许可：GeoLite2 data created by MaxMind, available from https://www.maxmind.com
 */
class GeoIpLookupService
{
    /** @var array<string, Reader> path@mtime => Reader */
    private array $readers = [];

    /**
     * 查询 IP 归属地。
     *
     * @return array{country_iso?: string, country_name?: string, region_code?: string, region_name?: string, city?: string, timezone?: string, latitude?: float|null, longitude?: float|null, source?: string, cached?: bool}
     */
    public function lookup(?string $ip): array
    {
        $ip = trim((string) $ip);

        if (! $this->isPublicIp($ip)) {
            return [];
        }

        $key = $this->cacheKey($ip);

        if ($this->cacheEnabled()) {
            try {
                $cached = Cache::get($key);
                if (is_array($cached) && $cached !== []) {
                    $cached['cached'] = true;

                    return $cached;
                }
            } catch (Throwable) {
                // 缓存不可用时静默降级
            }
        }

        $result = $this->read($ip);

        if ($result !== [] && $this->cacheEnabled()) {
            try {
                Cache::put($key, $result, (int) config('customgeo.cache.ttl', 86400));
            } catch (Throwable) {
                // 忽略缓存写入失败
            }
        }

        return $result;
    }

    /**
     * 是否可公网路由的合法 IP（排除 10/8、172.16/12、192.168/16、127/8、169.254/16、::1 等）。
     */
    public function isPublicIp(string $ip): bool
    {
        if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    public function isAvailable(): bool
    {
        return is_file(GeoPaths::mmdb('city')) || is_file(GeoPaths::mmdb('country'));
    }

    /**
     * 已启用的数据库信息（后台/调试用）。
     *
     * @return array<string, mixed>
     */
    public function info(): array
    {
        $info = [];
        foreach (array_keys(GeoPaths::EDITIONS) as $edition) {
            $path  = GeoPaths::mmdb($edition);
            $entry = [
                'path'   => GeoPaths::relative($path),
                'exists' => is_file($path),
                'size'   => is_file($path) ? (int) filesize($path) : 0,
            ];

            if ($entry['exists']) {
                try {
                    $meta = (new Reader($path))->metadata();
                    $entry['database_type'] = (string) $meta->databaseType;
                    $entry['build_date']    = $meta->buildEpoch ? date('Y-m-d', (int) $meta->buildEpoch) : '';
                } catch (Throwable $e) {
                    $entry['error'] = $e->getMessage();
                }
            }

            $info[$edition] = $entry;
        }

        return $info;
    }

    /**
     * 清除单个 IP 的缓存（调试用）。
     */
    public function forget(string $ip): void
    {
        try {
            Cache::forget($this->cacheKey($ip));
        } catch (Throwable) {
            // ignore
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $ip): array
    {
        $cityPath = GeoPaths::mmdb('city');

        if (is_file($cityPath)) {
            try {
                return $this->fromCity($this->reader('city')->city($ip));
            } catch (AddressNotFoundException) {
                // 城市库无记录，继续尝试国家库
            } catch (Throwable $e) {
                Log::debug('CustomGeo: city lookup failed', ['ip' => $ip, 'error' => $e->getMessage()]);
            }
        }

        $countryPath = GeoPaths::mmdb('country');

        if (is_file($countryPath)) {
            try {
                return $this->fromCountry($this->reader('country')->country($ip));
            } catch (AddressNotFoundException) {
                return [];
            } catch (Throwable $e) {
                Log::debug('CustomGeo: country lookup failed', ['ip' => $ip, 'error' => $e->getMessage()]);

                return [];
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function fromCity(object $record): array
    {
        $subdivision = $record->mostSpecificSubdivision ?? ($record->subdivisions[0] ?? null);

        $result = [
            'country_iso'  => (string) ($record->country->isoCode ?? ''),
            'country_name' => (string) ($record->country->name ?? ''),
            'region_code'  => (string) ($subdivision->isoCode ?? ''),
            'region_name'  => (string) ($subdivision->name ?? ''),
            'city'         => (string) ($record->city->name ?? ''),
            'postal_code'  => (string) ($record->postal->code ?? ''),
            'timezone'     => (string) ($record->location->timeZone ?? ''),
            'latitude'     => $record->location->latitude ?? null,
            'longitude'    => $record->location->longitude ?? null,
            'source'       => 'city',
        ];

        return $result['country_iso'] === '' && $result['city'] === '' ? [] : $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function fromCountry(object $record): array
    {
        $result = [
            'country_iso'  => (string) ($record->country->isoCode ?? ''),
            'country_name' => (string) ($record->country->name ?? ''),
            'region_code'  => '',
            'region_name'  => '',
            'city'         => '',
            'postal_code'  => '',
            'timezone'     => '',
            'latitude'     => null,
            'longitude'    => null,
            'source'       => 'country',
        ];

        return $result['country_iso'] === '' ? [] : $result;
    }

    private function reader(string $edition): Reader
    {
        $path  = GeoPaths::mmdb($edition);
        $stamp = $path.'@'.(@filemtime($path) ?: 0);

        if (! isset($this->readers[$stamp])) {
            $this->readers = [$stamp => new Reader($path)];
        }

        return $this->readers[$stamp];
    }

    private function cacheEnabled(): bool
    {
        return (bool) config('customgeo.cache.enabled', true);
    }

    /**
     * 缓存 key 带数据库版本，MMDB 更新后旧缓存自然失效。
     */
    private function cacheKey(string $ip): string
    {
        return config('customgeo.cache.prefix', 'customgeo').':'.$this->version().':ip:'.$ip;
    }

    private function version(): string
    {
        $parts = [];
        foreach (['city', 'country'] as $edition) {
            $path    = GeoPaths::mmdb($edition);
            $parts[] = is_file($path) ? (@filemtime($path) ?: 0).':'.(int) filesize($path) : '0';
        }

        return substr(md5(implode('|', $parts)), 0, 10);
    }
}
