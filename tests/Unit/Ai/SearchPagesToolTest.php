<?php
namespace Tests\Unit\Ai;

use App\Services\Ai\Tools\SearchPagesTool;
use PHPUnit\Framework\TestCase;

class SearchPagesToolTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/spt_'.uniqid();
        mkdir($this->root, 0777, true);
        mkdir($this->root.'/assets', 0777, true);

        file_put_contents($this->root.'/bba-fees.php',
            "<?php\n?><title>BBA Fees at VIPS-TC</title>\n<body>BBA fee is 95000 per semester at VIPS-TC.</body>");
        file_put_contents($this->root.'/hostel.php',
            "<?php\n?><title>Hostel</title>\n<body>MAIT hostel has 200 beds for boys.</body>");
        file_put_contents($this->root.'/no-title.php',
            "<?php\n?><body>BBA mention but no title tag at all.</body>");
        file_put_contents($this->root.'/assets/style.php',
            "<?php\n?>BBA fees garbage that should be excluded.");
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = "$dir/$f";
            is_dir($p) ? $this->rrmdir($p) : unlink($p);
        }
        rmdir($dir);
    }

    public function test_finds_matching_files_and_extracts_title(): void
    {
        $tool = new SearchPagesTool($this->root, ['assets']);
        $hits = $tool->execute('BBA');

        $slugs = array_column($hits, 'slug');
        sort($slugs);
        $this->assertSame(['bba-fees.php', 'no-title.php'], $slugs);

        $bba = collect($hits)->firstWhere('slug', 'bba-fees.php');
        $this->assertSame('BBA Fees at VIPS-TC', $bba['title']);
        $this->assertStringContainsString('BBA', $bba['snippet']);
    }

    public function test_falls_back_to_slug_when_no_title(): void
    {
        $tool = new SearchPagesTool($this->root, ['assets']);
        $hit = collect($tool->execute('BBA'))->firstWhere('slug', 'no-title.php');
        $this->assertSame('no-title', $hit['title']);
    }

    public function test_excludes_configured_dirs(): void
    {
        $tool = new SearchPagesTool($this->root, ['assets']);
        $slugs = array_column($tool->execute('BBA'), 'slug');
        $this->assertNotContains('style.php', $slugs);
    }

    public function test_caps_at_10_results(): void
    {
        for ($i = 0; $i < 15; $i++) {
            file_put_contents($this->root."/extra-{$i}.php", "<?php ?><title>x</title>BBA");
        }
        $tool = new SearchPagesTool($this->root, ['assets']);
        $this->assertCount(10, $tool->execute('BBA'));
    }

    public function test_returns_empty_when_docroot_missing(): void
    {
        $tool = new SearchPagesTool('/does/not/exist', []);
        $this->assertSame([], $tool->execute('anything'));
    }

    public function test_definition_shape(): void
    {
        $def = SearchPagesTool::definition();
        $this->assertSame('function', $def['type']);
        $this->assertSame('search_pages', $def['function']['name']);
        $this->assertArrayHasKey('query', $def['function']['parameters']['properties']);
    }
}
