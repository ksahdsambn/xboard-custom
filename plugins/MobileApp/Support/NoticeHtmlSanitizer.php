<?php

namespace Plugin\MobileApp\Support;

final class NoticeHtmlSanitizer
{
    public static function sanitize(?string $html): string
    {
        $html = (string) $html;
        $html = preg_replace('#<(script|iframe|object|embed|style|link|meta)[^>]*>.*?</\1>#is', '', $html) ?? '';
        $html = preg_replace('#<(script|iframe|object|embed|style|link|meta)[^>]*/?>#is', '', $html) ?? '';
        $html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/javascript\s*:/i', '', $html) ?? '';
        $clean = strip_tags($html, '<p><br><strong><em><b><i><ul><ol><li><a>');
        $clean = preg_replace_callback(
            '/<a\s+([^>]*)>/i',
            static function (array $match): string {
                $attrs = $match[1];
                if (!preg_match('/href\s*=\s*(["\'])(.*?)\1/i', $attrs, $hrefMatch)) {
                    return '<a>';
                }
                $href = html_entity_decode((string) $hrefMatch[2], ENT_QUOTES);
                if (!preg_match('#^https?://#i', $href)) {
                    return '<a>';
                }
                return '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
            },
            $clean
        ) ?? $clean;
        return $clean;
    }
}
