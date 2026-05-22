<?php
namespace Tests\Unit\Ai;

use App\Services\Ai\Tools\ReadPageTool;
use PHPUnit\Framework\TestCase;

class ReadPageToolTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/rpt_'.uniqid();
        mkdir($this->root);
        file_put_contents($this->root.'/page.php',
            "<?php include 'x.php'; ?>\n<title>Page</title>\n<h1>Hello</h1>\n<p>Body text here.</p>\n<?php echo 'secret'; ?>");
        file_put_contents($this->root.'/large.php', str_repeat('a', 20000));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/*') as $f) unlink($f);
        rmdir($this->root);
        parent::tearDown();
    }

    public function test_strips_php_and_html_keeps_text(): void
    {
        $tool = new ReadPageTool($this->root, 16384);
        $out = $tool->execute('page.php');

        $this->assertStringContainsString('Hello', $out);
        $this->assertStringContainsString('Body text here.', $out);
        $this->assertStringNotContainsString("echo 'secret'", $out);
        $this->assertStringNotContainsString('include', $out);
        $this->assertStringNotContainsString('<h1>', $out);
    }

    public function test_byte_cap_truncates(): void
    {
        $tool = new ReadPageTool($this->root, 16384);
        $out = $tool->execute('large.php');
        $this->assertLessThanOrEqual(16384, strlen($out));
    }

    public function test_rejects_traversal(): void
    {
        $tool = new ReadPageTool($this->root, 16384);
        $this->assertStringStartsWith('ERROR:', $tool->execute('../etc/passwd'));
        $this->assertStringStartsWith('ERROR:', $tool->execute('/etc/passwd'));
        $this->assertStringStartsWith('ERROR:', $tool->execute('sub/../page.php'));
    }

    public function test_missing_file_returns_error_string(): void
    {
        $tool = new ReadPageTool($this->root, 16384);
        $this->assertStringStartsWith('ERROR:', $tool->execute('nope.php'));
    }

    public function test_definition_shape(): void
    {
        $def = ReadPageTool::definition();
        $this->assertSame('read_page', $def['function']['name']);
        $this->assertArrayHasKey('slug', $def['function']['parameters']['properties']);
    }
}
