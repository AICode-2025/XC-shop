<?php

namespace App\CustomGeo;

use App\CustomGeo\Console\Commands\GeoImportCommand;
use App\CustomGeo\Console\Commands\GeoStatusCommand;
use App\CustomGeo\Console\Commands\GeoUpdateIpdbCommand;
use App\CustomGeo\Console\Commands\GeoZoneCreateCommand;
use App\CustomGeo\GeoNames\GeoNamesDownloader;
use App\CustomGeo\GeoNames\GeoNamesImporter;
use App\CustomGeo\MaxMind\MaxMindUpdater;
use App\CustomGeo\Services\GeoDetectService;
use App\CustomGeo\Services\GeoIpLookupService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * GeoNames + GeoLite2 集成入口（全部自定义逻辑集中在 app/CustomGeo）。
 *
 * 只做三件事：注册容器单例、注册命令/路由/定时任务、把定位中间件挂到 front 组末尾。
 * 不改动 InnoShop 原生 ServiceProvider，方便与上游比对和合并。
 */
class CustomGeoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GeoNamesDownloader::class);
        $this->app->singleton(GeoNamesImporter::class);
        $this->app->singleton(MaxMindUpdater::class);
        $this->app->singleton(GeoIpLookupService::class);
        $this->app->singleton(GeoDetectService::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                GeoImportCommand::class,
                GeoUpdateIpdbCommand::class,
                GeoZoneCreateCommand::class,
                GeoStatusCommand::class,
            ]);
        }

        $this->registerFrontMiddleware();
        $this->registerSchedules();
    }

    /**
     * 前台定位中间件已在 bootstrap/app.php 的 front 组内注册（位于 SetFrontLocale 之前），
     * 这里只做一次注册校验，避免重复堆叠导致顺序不可控。
     */
    private function registerFrontMiddleware(): void
    {
        // zwxc-custom: 迁移到 bootstrap/app.php 的 front 组定义，保持"先定位、后设语言"的顺序
    }

    /**
     * 定时更新 GeoLite2（需配置 MAXMIND_LICENSE_KEY；未配置时不注册，避免日志噪音）。
     */
    private function registerSchedules(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        if ((string) config('customgeo.maxmind.license_key', '') !== '') {
            $this->app->booted(function (): void {
                $schedule = $this->app->make(Schedule::class);

                $schedule->command('innoshop:geo-update-ipdb --edition=both')
                    ->weeklyOn(3, '04:10')
                    ->withoutOverlapping()
                    ->name('customgeo-ipdb-weekly')
                    ->description('每周三 04:10 更新 GeoLite2 城市/国家库（自动备份，失败回滚）');
            });
        }
    }
}
