<?php

namespace App\CustomGeo\Services;

use App\CustomGeo\Support\GeoPaths;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Throwable;

/**
 * 访客自动定位与应用（推荐币种 / 语言）。
 *
 * 设计原则：
 * - 只“推荐”，不强制跳转、不改 URL，避免破坏 InnoShop 原有的多语言/多币种路由；
 * - 用户已手动切换过（session 已有 currency / locale）则不覆盖；
 * - 币种来源优先 GeoNames 写入的 countries.currency_code，其次 config 兜底映射；
 * - 任何异常都静默跳过，保证前台可用性。
 */
class GeoDetectService
{
    public function __construct(private readonly GeoIpLookupService $lookup) {}

    /**
     * 后台开关 + 全局开关。
     */
    public function enabled(): bool
    {
        if (! (bool) config('customgeo.enabled', true)) {
            return false;
        }

        try {
            if (! installed()) {
                return false;
            }

            return filter_var(system_setting('geo_auto_detect', config('customgeo.detect.enabled_by_default', true)), FILTER_VALIDATE_BOOLEAN);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 解析 IP 并给出推荐（不写入 session）。
     *
     * @return array{ip: string, location: array<string, mixed>, currency: ?string, locale: ?string}
     */
    public function detect(string $ip): array
    {
        $location = $this->lookup->lookup($ip);
        $country  = (string) ($location['country_iso'] ?? '');

        return [
            'ip'       => $ip,
            'location' => $location,
            'currency' => $country !== '' ? $this->currencyForCountry($country) : null,
            'locale'   => $country !== '' ? $this->localeForCountry($country) : null,
        ];
    }

    /**
     * 把推荐写入 session（不覆盖用户已有选择）。
     *
     * @return array{currency: ?string, locale: ?string}
     */
    public function applySession(array $location, bool $allowLocale = true): array
    {
        $country = strtoupper((string) ($location['country_iso'] ?? ''));
        $applied = ['currency' => null, 'locale' => null];

        if ($country === '') {
            return $applied;
        }

        Session::put('geo_country_code', $country);
        if (! empty($location['city'])) {
            Session::put('geo_city', $location['city']);
        }

        $currency = $this->currencyForCountry($country);
        if ($currency && ! Session::has('currency')) {
            Session::put('currency', $currency);
            $applied['currency'] = $currency;
        }

        if ($allowLocale && ! Session::has('customgeo_locale_applied')) {
            $locale = $this->localeForCountry($country);
            if ($locale) {
                Session::put('locale', $locale);
                Session::put('customgeo_locale_applied', true);
                $applied['locale'] = $locale;
            }
        }

        return $applied;
    }

    /**
     * 国家 → 币种（小写 code），仅返回后台已启用的币种。
     */
    public function currencyForCountry(string $countryIso): ?string
    {
        $countryIso = strtoupper(trim($countryIso));
        if ($countryIso === '') {
            return null;
        }

        $candidate = '';

        try {
            $row = DB::table('countries')->where('code', $countryIso)->first(['currency_code']);
            $candidate = strtolower(trim((string) ($row->currency_code ?? '')));
        } catch (Throwable) {
            // 表未就绪时走配置兜底
        }

        if ($candidate === '') {
            $candidate = strtolower((string) (config('customgeo.detect.currency_by_country')[$countryIso] ?? ''));
        }

        if ($candidate === '') {
            return null;
        }

        return in_array($candidate, $this->activeCurrencyCodes(), true) ? $candidate : null;
    }

    /**
     * 国家 → 语言（仅返回后台已启用的 locale）。
     */
    public function localeForCountry(string $countryIso): ?string
    {
        $countryIso = strtoupper(trim($countryIso));
        if ($countryIso === '') {
            return null;
        }

        $candidate = strtolower((string) (config('customgeo.detect.locale_by_country')[$countryIso] ?? ''));
        if ($candidate === '') {
            return null;
        }

        foreach ($this->activeLocaleCodes() as $code) {
            $normalized = strtolower(str_replace('_', '-', $code));
            if ($normalized === $candidate || str_starts_with($normalized, $candidate.'-')) {
                return $code;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    public function activeCurrencyCodes(): array
    {
        try {
            return DB::table('currencies')->where('active', true)->pluck('code')
                ->map(static fn ($code): string => strtolower((string) $code))
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return string[]
     */
    public function activeLocaleCodes(): array
    {
        try {
            return locales()->pluck('code')->map(static fn ($code): string => (string) $code)->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * 调试信息汇总。
     *
     * @return array<string, mixed>
     */
    public function debugInfo(): array
    {
        return [
            'enabled'       => $this->enabled(),
            'session'       => [
                'currency' => Session::get('currency'),
                'locale'    => Session::get('locale'),
                'geo_country_code' => Session::get('geo_country_code'),
                'detected'  => (bool) Session::get('customgeo_detected'),
            ],
            'currencies'    => $this->activeCurrencyCodes(),
            'locales'       => $this->activeLocaleCodes(),
            'paths'         => [
                'geonames' => GeoPaths::relative(GeoPaths::geonamesDir()),
                'maxmind'  => GeoPaths::relative(GeoPaths::maxmindDir()),
            ],
        ];
    }
}
