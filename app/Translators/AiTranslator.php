<?php

namespace App\Translators;

use Exception;
use InnoShop\Aicore\Services\AIServiceManager;
use InnoShop\Panel\Interfaces\Translator;

/**
 * AI 翻译器：复用 Aicore 的文本生成能力，把源语言文案翻译为目标语言。
 *
 * 仅在后台已配置 AI 文本供应商（settings.ai_text_provider）时启用，
 * 由 AppServiceProvider 注册到 panel.service.translator 过滤器。
 */
class AiTranslator implements Translator
{
    private const LANGUAGE_NAMES = [
        'en'    => 'English',
        'zh-cn' => 'Simplified Chinese',
        'zh-hk' => 'Traditional Chinese (Hong Kong)',
        'ja'    => 'Japanese',
        'ko'    => 'Korean',
        'de'    => 'German',
        'fr'    => 'French',
        'es'    => 'Spanish',
        'it'    => 'Italian',
        'pt-pt' => 'Portuguese (Portugal)',
        'pt-br' => 'Portuguese (Brazil)',
        'ru'    => 'Russian',
        'uk'    => 'Ukrainian',
        'tr'    => 'Turkish',
        'th'    => 'Thai',
        'vi'    => 'Vietnamese',
        'id'    => 'Indonesian',
        'ms'    => 'Malay',
        'ar'    => 'Arabic',
        'nl'    => 'Dutch',
        'pl'    => 'Polish',
        'cs'    => 'Czech',
        'el'    => 'Greek',
        'hu'    => 'Hungarian',
        'ro'    => 'Romanian',
        'sv'    => 'Swedish',
        'da'    => 'Danish',
        'nb'    => 'Norwegian',
        'fi'    => 'Finnish',
        'he'    => 'Hebrew',
        'km'    => 'Khmer',
    ];

    /**
     * @throws Exception
     */
    public function translate($from, $to, $text): string
    {
        $text = trim((string) $text);
        if ($text === '' || $from === $to) {
            return $text;
        }

        $prompt = sprintf(
            "You are a professional e-commerce translator. Translate the text below from %s to %s.\n"
            ."Rules: keep HTML tags and line breaks unchanged; keep brand names, product codes, URLs, emails, numbers and units unchanged; "
            ."use natural, marketing-friendly wording; do not add explanations; output only the translated text.\n\n%s",
            $this->languageName((string) $from),
            $this->languageName((string) $to),
            $text
        );

        $result = AIServiceManager::getInstance()->generate($prompt, 'translate');

        return $this->clean((string) $result);
    }

    /**
     * @throws Exception
     */
    public function batchTranslate($from, $to, $texts): array
    {
        $results = [];
        foreach ((array) $texts as $key => $text) {
            $results[$key] = $this->translate($from, $to, $text);
        }

        return $results;
    }

    public function mapCode($code): string
    {
        return (string) $code;
    }

    private function languageName(string $code): string
    {
        $code = strtolower($code);

        return self::LANGUAGE_NAMES[$code] ?? $code;
    }

    private function clean(string $text): string
    {
        $text = trim($text);

        // 去掉模型偶尔包裹的引号或代码块围栏
        $text = preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $text) ?? $text;
        $text = trim($text, " \t\n\r\0\x0B\"'");

        return trim($text);
    }
}
