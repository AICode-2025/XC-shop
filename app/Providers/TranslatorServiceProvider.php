<?php

namespace App\Providers;

use App\Translators\AiTranslator;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * 注册后台翻译器：仅当已配置 AI 文本供应商时启用，供
 * "复制到所有语言"逐语言自动翻译使用。
 *
 * 需排在 PluginServiceProvider 之后（eventy 在此之后才可用）。
 */
class TranslatorServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! interface_exists(\InnoShop\Panel\Interfaces\Translator::class)) {
            return;
        }

        try {
            app('eventy')->addFilter('panel.service.translator', function ($translator) {
                if (! empty($translator)) {
                    return $translator;
                }
                if (empty(system_setting('ai_text_provider'))) {
                    return $translator;
                }
                if (! class_exists(\InnoShop\Aicore\Services\AIServiceManager::class)) {
                    return $translator;
                }

                return AiTranslator::class;
            });
        } catch (Throwable) {
            // eventy 不可用时忽略，保持原生行为（无翻译）
        }
    }
}
