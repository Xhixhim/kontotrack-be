<?php

namespace App\Support;

/**
 * Puts a message written in English into the language of the current request.
 *
 * Messages are looked up three ways, most exact first: the whole sentence (lang/{locale}.json),
 * the sentence with its numbers taken out (lang/{locale}/templates.php), and patterns for sentences
 * that contain names (lang/{locale}/patterns.php). A message nobody translated stays in English.
 */
class Localizer
{
    public static function translate(string $text): string
    {
        if ($text === '' || app()->getLocale() === 'en') {
            return $text;
        }

        $exact = __($text);

        if (is_string($exact) && $exact !== $text) {
            return $exact;
        }

        $numbers = [];
        $template = (string) preg_replace_callback('/\d[\d.,]*\d|\d/', function (array $match) use (&$numbers): string {
            $numbers[] = $match[0];

            return '#';
        }, $text);
        $templates = trans('templates');

        if ($numbers !== [] && is_array($templates) && isset($templates[$template])) {
            $next = 0;

            return (string) preg_replace_callback('/#(\d+)?/', function (array $match) use ($numbers, &$next): string {
                return $numbers[isset($match[1]) ? (int) $match[1] - 1 : $next++] ?? '';
            }, $templates[$template]);
        }

        $patterns = trans('patterns');

        foreach (is_array($patterns) ? $patterns : [] as $pattern => $translation) {
            if (preg_match($pattern, $text, $parts) === 1) {
                return (string) preg_replace_callback('/\$(\d)/', fn (array $match): string => $parts[(int) $match[1]] ?? '', $translation);
            }
        }

        return $text;
    }
}
