<?php
namespace App\Services\Ai\Tools;

final class SearchPagesTool
{
    public function __construct(
        private readonly string $docroot,
        private readonly array $excludedDirs,
    ) {}

    public static function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name'        => 'search_pages',
                'description' => 'Search ipu.co.in pages by free-text query. Returns up to 10 matching pages with slug, title, snippet.',
                'parameters'  => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Free-text search query'],
                    ],
                    'required' => ['query'],
                ],
            ],
        ];
    }

    private const STOPWORDS = [
        'the','a','an','of','in','at','on','for','to','and','or','is','are','was','were',
        'by','with','from','as','this','that','what','which','how','when','where','why',
        'i','you','we','they','it','my','your','our','their','do','does','can','will',
    ];

    /** @return array<int, array{slug:string,title:string,snippet:string}> */
    public function execute(string $query): array
    {
        if (!is_dir($this->docroot)) return [];

        $query = trim($query);
        if ($query === '') return [];

        $tokens = array_values(array_filter(
            array_map('strtolower', preg_split('/[\s,]+/', $query) ?: []),
            fn ($t) => strlen($t) >= 2 && !in_array($t, self::STOPWORDS, true),
        ));
        if ($tokens === []) {
            $tokens = [strtolower($query)];
        }

        $scored = [];
        foreach ($this->iterPhpFiles($this->docroot) as $file) {
            $rel = ltrim(str_replace($this->docroot, '', $file), '/');
            $top = explode('/', $rel)[0] ?? '';
            if (in_array($top, $this->excludedDirs, true) && str_contains($rel, '/')) continue;

            $contents = @file_get_contents($file);
            if ($contents === false) continue;
            $haystack = strtolower($contents);

            $score = 0;
            $firstHit = null;
            foreach ($tokens as $tok) {
                $pos = strpos($haystack, $tok);
                if ($pos !== false) {
                    $score++;
                    if ($firstHit === null) $firstHit = $pos;
                }
            }
            if ($score === 0) continue;

            $scored[] = [
                'slug'    => basename($file),
                'title'   => $this->extractTitle($contents, basename($file)),
                'snippet' => $this->snippet($contents, $firstHit ?? 0),
                'score'   => $score,
            ];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        return array_map(
            fn ($r) => ['slug' => $r['slug'], 'title' => $r['title'], 'snippet' => $r['snippet']],
            array_slice($scored, 0, 10),
        );
    }

    /** @return iterable<string> */
    private function iterPhpFiles(string $dir): iterable
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = "$dir/$entry";
            if (is_dir($path)) {
                if (in_array($entry, $this->excludedDirs, true)) continue;
                yield from $this->iterPhpFiles($path);
            } elseif (str_ends_with($entry, '.php')) {
                yield $path;
            }
        }
    }

    private function extractTitle(string $contents, string $slug): string
    {
        if (preg_match('/<title>(.*?)<\/title>/is', $contents, $m)) {
            $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5));
            if ($title !== '') return $title;
        }
        return preg_replace('/\.php$/', '', $slug);
    }

    private function snippet(string $contents, int $pos): string
    {
        $start  = max(0, $pos - 80);
        $window = substr($contents, $start, 300);
        $clean  = trim(preg_replace('/\s+/', ' ', strip_tags($window)));
        return mb_substr($clean, 0, 200);
    }
}
