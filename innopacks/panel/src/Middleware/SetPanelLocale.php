<?php
/**
 * Copyright (c) Since 2024 InnoShop - All Rights Reserved
 *
 * @link       https://www.innoshop.com
 * @author     InnoShop <team@innoshop.com>
 * @license    https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

namespace InnoShop\Panel\Middleware;

use Closure;
use Exception;
use Illuminate\Http\Request;

class SetPanelLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @param  Closure  $next
     * @return mixed
     * @throws Exception
     */
    public function handle(Request $request, Closure $next): mixed
    {
        // zwxc-custom: 后台语言与前台语言完全独立，不再读写前台的 session('locale')
        app()->setLocale(panel_locale_code());

        return $next($request);
    }
}
