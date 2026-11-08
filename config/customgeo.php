<?php

/**
 * zwxc-custom: GeoNames + MaxMind GeoLite2 集成配置（跨境独立站）。
 *
 * 数据许可：
 * - GeoNames 数据采用 CC BY 4.0，使用需署名 "GeoNames (https://www.geonames.org/)"。
 * - GeoLite2 数据采用 CC BY-SA 4.0，需注册 MaxMind 免费账号获取 license key，
 *   并在站点保留 "GeoLite2 data created by MaxMind, available from https://www.maxmind.com" 署名。
 */

$customGeoRoot = storage_path('app/customgeo');
$geoLite2City  = env('GEOLITE2_PATH') ?: storage_path('app/geolite2/geolite2-city.mmdb');

// .env 中留空时回退默认值，避免空字符串把路径/白名单清空
$slimCountries = env('GEONAMES_SLIM_COUNTRIES')
    ?: 'AE,AR,AT,AU,BE,BG,BR,CA,CH,CL,CN,CZ,DE,DK,EE,EG,ES,FI,FR,GB,GR,HK,HU,ID,IE,IL,IN,IT,JP,KR,KW,MX,MY,NL,NO,NZ,PH,PL,PT,QA,RO,SA,SE,SG,SK,TH,TR,TW,UA,US,VN,ZA';

return [
    'enabled' => (bool) env('CUSTOMGEO_ENABLED', true),

    'paths' => [
        'data'     => env('CUSTOMGEO_DATA_PATH') ?: $customGeoRoot,
        'geonames' => env('GEONAMES_DATA_PATH') ?: $customGeoRoot.'/geonames',
        'maxmind'  => env('CUSTOMGEO_MAXMIND_PATH') ?: $customGeoRoot.'/maxmind',
        'logs'     => env('CUSTOMGEO_LOG_PATH') ?: $customGeoRoot.'/logs',
    ],

    'geonames' => [
        'base_url' => env('GEONAMES_BASE_URL', 'https://download.geonames.org/export/dump'),
        'timeout'  => (int) env('GEONAMES_TIMEOUT', 1800),
        'files'    => ['allCountries.zip', 'countryInfo.txt'],
        'license'  => 'GeoNames data is licensed under CC BY 4.0 (https://creativecommons.org/licenses/by/4.0/), source: https://www.geonames.org/',
    ],

    'maxmind' => [
        'license_key'  => env('MAXMIND_LICENSE_KEY', ''),
        'download_url' => env('MAXMIND_DOWNLOAD_URL', 'https://download.maxmind.com/app/geoip_download'),
        'city_path'    => env('GEOLITE2_CITY_PATH') ?: $geoLite2City,
        'country_path' => env('GEOLITE2_COUNTRY_PATH') ?: storage_path('app/geolite2/geolite2-country.mmdb'),
        'backup_keep'  => (int) env('MAXMIND_BACKUP_KEEP', 3),
        'timeout'      => (int) env('MAXMIND_TIMEOUT', 900),
        'min_bytes'    => 1024 * 1024,
        'license'      => 'GeoLite2 data created by MaxMind, available from https://www.maxmind.com (CC BY-SA 4.0).',
    ],

    'import' => [
        // full = 全量；slim = 仅电商常用国家（白名单来自下方配置，非代码硬编码）
        'mode'                   => env('GEONAMES_IMPORT_MODE', 'slim'),
        'chunk'                  => (int) env('GEONAMES_IMPORT_CHUNK', 500),
        'min_population_full'    => 0,
        'min_population_slim'    => (int) env('GEONAMES_SLIM_MIN_POPULATION', 500),
        'max_errors'             => (int) env('GEONAMES_MAX_ERRORS', 1000),
        'progress_every'         => (int) env('GEONAMES_PROGRESS_EVERY', 200000),
        'slim_countries'         => array_values(array_filter(array_map(
            static fn (string $code): string => strtoupper(trim($code)),
            explode(',', (string) $slimCountries)
        ))),
    ],

    'cache' => [
        'enabled' => (bool) env('CUSTOMGEO_CACHE', true),
        'ttl'     => (int) env('CUSTOMGEO_CACHE_TTL', 86400),
        'prefix'  => env('CUSTOMGEO_CACHE_PREFIX', 'customgeo'),
    ],

    'detect' => [
        'enabled_by_default' => (bool) env('CUSTOMGEO_AUTODETECT_DEFAULT', true),
        'session_key'        => 'customgeo_detected',
        // 兜底映射：优先使用 countries.currency_code（GeoNames countryInfo 提供），此处仅作补充
        'currency_by_country' => [
            'US' => 'usd', 'CA' => 'cad', 'MX' => 'mxn', 'BR' => 'brl',
            'GB' => 'gbp', 'DE' => 'eur', 'FR' => 'eur', 'IT' => 'eur', 'ES' => 'eur',
            'NL' => 'eur', 'BE' => 'eur', 'AT' => 'eur', 'IE' => 'eur', 'PT' => 'eur',
            'FI' => 'eur', 'GR' => 'eur', 'SK' => 'eur', 'SI' => 'eur', 'EE' => 'eur',
            'JP' => 'jpy', 'KR' => 'krw', 'CN' => 'cny', 'HK' => 'hkd', 'TW' => 'twd',
            'SG' => 'sgd', 'AU' => 'aud', 'NZ' => 'nzd', 'IN' => 'inr', 'ID' => 'idr',
            'MY' => 'myr', 'TH' => 'thb', 'VN' => 'vnd', 'PH' => 'php', 'AE' => 'aed',
            'SA' => 'sar', 'TR' => 'try', 'RU' => 'rub', 'ZA' => 'zar', 'SE' => 'sek',
            'NO' => 'nok', 'DK' => 'dkk', 'PL' => 'pln', 'CZ' => 'czk', 'CH' => 'chf',
        ],
        // 语言推荐：命中且该语言在站点已启用（locales 表 active=1）才会生效，
        // 未命中或对应语言未启用时统一回退前台默认语言（当前为英文）。
        'locale_by_country' => [
            'US' => 'en', 'GB' => 'en', 'AU' => 'en', 'NZ' => 'en', 'CA' => 'en', 'SG' => 'en',
            'DE' => 'de', 'AT' => 'de', 'CH' => 'de', 'FR' => 'fr', 'BE' => 'fr',
            'ES' => 'es', 'MX' => 'es', 'AR' => 'es', 'CL' => 'es', 'CO' => 'es',
            'IT' => 'it', 'NL' => 'nl', 'PT' => 'pt', 'BR' => 'pt', 'PL' => 'pl',
            'RU' => 'ru', 'UA' => 'uk', 'TR' => 'tr', 'JP' => 'ja', 'KR' => 'ko',
            // 中文地区统一用简体中文（zh-tw/zh-hk 语言包当前未启用，避免回落英文）
            'CN' => 'zh-cn', 'TW' => 'zh-cn', 'HK' => 'zh-cn', 'MO' => 'zh-cn',
            'TH' => 'th', 'VN' => 'vi', 'ID' => 'id', 'MY' => 'ms', 'SA' => 'ar',
            'AE' => 'ar', 'IL' => 'he', 'SE' => 'sv', 'DK' => 'da', 'NO' => 'nb',
            'FI' => 'fi', 'CZ' => 'cs', 'GR' => 'el', 'HU' => 'hu', 'RO' => 'ro',
        ],
    ],

    'debug' => [
        'enabled' => (bool) env('CUSTOMGEO_DEBUG_ROUTE', true),
        // 生产环境建议设置随机 token，访问时追加 ?token=xxx
        'token' => env('CUSTOMGEO_DEBUG_TOKEN', ''),
    ],
];
