<?php
/**
 * Copyright (c) Since 2024 InnoShop - All Rights Reserved
 *
 * @link       https://www.innoshop.com
 * @author     InnoShop <team@innoshop.com>
 * @license    https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use InnoShop\Common\Models\Setting;
use InnoShop\Plugin\Repositories\PluginRepo;
use InnoShop\Plugin\Services\PluginService;
use Throwable;

class PluginSeeder extends Seeder
{
    /**
     * 随系统发布的内置插件（code 必须与 plugins/<Code>/config.json 一致）。
     *
     * @var string[]
     */
    private const BUILTIN_PLUGINS = [
        'FixedShipping',
        'BankTransfer',
        'PageBuilder',
        'PayPal',
        'Stripe',
    ];

    /**
     * 注册并启用全部内置插件。
     *
     * 幂等：plugins 行已存在时不重复写入，已有设置不覆盖。
     * 启用状态落在 settings(space=<Code>, name=active)，重启后由 PluginManager 读取。
     *
     * @return void
     * @throws Throwable
     */
    public function run(): void
    {
        foreach (self::BUILTIN_PLUGINS as $code) {
            $plugin = app('plugin')->getPlugin($code);
            if (! $plugin) {
                $this->command?->warn("内置插件目录缺失，已跳过：{$code}");

                continue;
            }

            // 1) 注册：写 plugins 表（含插件自身迁移）
            PluginService::getInstance()->installPlugin($plugin);

            // 2) 启用：settings space=<Code>, name=active
            $this->saveSetting($code, 'active', '1');

            // 3) 首次安装写入最小可运行默认值，已有配置不覆盖
            foreach ($this->defaults($code) as $name => $value) {
                if (! $this->hasSetting($code, $name)) {
                    $this->saveSetting($code, $name, $value);
                }
            }
        }

        PluginRepo::resetCache();
        config(['inno' => null]);
    }

    /**
     * 各插件开箱即用所需的默认设置。
     *
     * PayPal / Stripe 不写假密钥；两者 active=1 但 available 为空，
     * 未配置密钥前不会出现在前台支付方式中。
     *
     * @param  string  $code
     * @return array
     */
    private function defaults(string $code): array
    {
        return match ($code) {
            'FixedShipping' => [
                'type'  => 'fixed',
                'value' => '2',
            ],
            'BankTransfer' => [
                'bank_name'     => 'Bank of America',
                'bank_account'  => '12345678910',
                'bank_username' => 'Sam',
                'bank_comment'  => '',
                'available'     => ['pc_web', 'mobile_web'],
            ],
            default => [],
        };
    }

    /**
     * @param  string  $space
     * @param  string  $name
     * @return bool
     */
    private function hasSetting(string $space, string $name): bool
    {
        return Setting::query()
            ->where('space', $space)
            ->where('name', $name)
            ->exists();
    }

    /**
     * @param  string  $space
     * @param  string  $name
     * @param  mixed  $value
     * @return void
     */
    private function saveSetting(string $space, string $name, mixed $value): void
    {
        Setting::query()->updateOrCreate(
            ['space' => $space, 'name' => $name],
            [
                'value' => is_array($value) ? json_encode($value) : (string) $value,
                'json'  => is_array($value),
            ]
        );
    }
}
