<?php
namespace App\Services\Ai\Tools;

final class ReadPageTool
{
    public function __construct(
        private readonly string $docroot,
        private readonly int $byteCap,
    ) {}

    public static function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name'        => 'read_page',
                'description' => 'Read the text content of an ipu.co.in page by slug. Returns up to 16 KB of HTML- and PHP-stripped text.',
                'parameters'  => [
                    'type' => 'object',
                    'properties' => [
                        'slug' => ['type' => 'string', 'description' => 'Page slug like "IPU-B-Tech-admission-2026.php"'],
                    ],
                    'required' => ['slug'],
                ],
            ],
        ];
    }

    public function execute(string $slug): string
    {
        if ($slug === '' || str_contains($slug, '..') || str_starts_with($slug, '/')) {
            return 'ERROR: invalid slug';
        }

        $path = $this->docroot.'/'.$slug;
        $real = realpath($path);
        $rootReal = realpath($this->docroot);
        if ($real === false || $rootReal === false || !str_starts_with($real, $rootReal.DIRECTORY_SEPARATOR) && $real !== $rootReal) {
            return 'ERROR: file not found';
        }
        if (!is_file($real)) return 'ERROR: not a file';

        $raw = file_get_contents($real);
        if ($raw === false) return 'ERROR: read failed';

        // Strip PHP blocks
        $stripped = preg_replace('/<\?php.*?\?>/is', ' ', $raw) ?? $raw;
        $stripped = preg_replace('/<\?=.*?\?>/is', ' ', $stripped) ?? $stripped;

        // Strip remaining tags but preserve whitespace + newlines around headings
        $text = preg_replace('/<\s*\/?\s*(h[1-6]|br|p)\s*[^>]*>/i', "\n", $stripped) ?? $stripped;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $text = trim($text);

        return mb_strcut($text, 0, $this->byteCap);
    }
}
