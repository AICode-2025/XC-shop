<?php

/**
 * zwxc-custom: 地理数据相关路由（调试 + 城市候选）。
 * 独立于 InnoShop 原生路由文件，便于上游合并；路径固定不带语言前缀，仅输出 JSON。
 */

use App\CustomGeo\Http\Controllers\GeoCityController;
use App\CustomGeo\Http\Controllers\GeoDebugController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')
    ->prefix('customgeo')
    ->name('customgeo.')
    ->group(function (): void {
        Route::get('debug', GeoDebugController::class)->name('debug');
        Route::get('cities', GeoCityController::class)->name('cities');
    });
