<?php

namespace App\CustomGeo\Http\Middleware;

use App\CustomGeo\Services\GeoDetectService;
use App\CustomGeo\Services\GeoIpLookupService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Throwable;

/**
 * 前台访客自动定位中间件（只在 front 中间件组末尾执行）。
 *
 * 行为：首次访问 → 查询 MMDB → 写入 session（推荐币种/语言/国家），
 * 每个会话只执行一次，后台开关关闭时不执行，任何异常都不影响页面渲染。
 */
class GeoDetectVisitor
{
    public function __construct(
        private readonly GeoDetectService $detect,
        private readonly GeoIpLookupService $lookup,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        try {
            $this->process($request);
        } catch (Throwable $e) {
            Log::debug('CustomGeo: auto detect skipped', ['error' => $e->getMessage()]);
        }

        return $next($request);
    }

    private function process(Request $request): void
    {
        if (! $request->isMethod('GET')) {
            return;
        }

        if (! $this->detect->enabled()) {
            return;
        }

        if (Session::get('customgeo_detected')) {
            return;
        }

        if (! $this->lookup->isAvailable()) {
            return;
        }

        $result = $this->detect->detect((string) $request->ip());

        // 标记本会话已检测，避免每次请求重复查询
        Session::put('customgeo_detected', true);

        if (empty($result['location'])) {
            return;
        }

        $applied = $this->detect->applySession($result['location'], ! $this->localeFixedByUrl($request));

        // 让首次渲染即使用推荐语言（不改变 URL，仅影响本次响应语言）
        if (! empty($applied['locale'])) {
            app()->setLocale($applied['locale']);
        }
    }

    /**
     * URL 已带语言段时，语言以 URL 为准，不写入推荐语言。
     */
    private function localeFixedByUrl(Request $request): bool
    {
        $segment = strtolower((string) $request->segment(1));
        if ($segment === '') {
            return false;
        }

        foreach ($this->detect->activeLocaleCodes() as $code) {
            if (strtolower(str_replace('_', '-', $code)) === $segment) {
                return true;
            }
        }

        return false;
    }
}
