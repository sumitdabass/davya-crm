<?php

namespace Tests\Feature\LeadImport;

use App\Services\LeadImport\Mappers\CanonicalMapper;
use App\Services\LeadImport\Mappers\NikhilMapper;
use App\Services\LeadImport\Mappers\SonamMapper;
use App\Services\LeadImport\Mappers\SumitWebsiteMapper;
use Tests\TestCase;

class TemplatesExistTest extends TestCase
{
    /** @dataProvider provideSources */
    public function test_template_exists_and_header_matches_mapper(string $slug, string $mapperClass): void
    {
        $path = public_path("templates/lead-import-{$slug}.csv");
        $this->assertFileExists($path);

        $headerLine = rtrim((string) file($path)[0], "\r\n");
        $fields = str_getcsv($headerLine);
        $expected = (new $mapperClass())->expectedHeaders();

        $this->assertSame($expected, $fields, "template header for {$slug} must match mapper::expectedHeaders()");
    }

    public static function provideSources(): array
    {
        return [
            'sonam'         => ['sonam',         SonamMapper::class],
            'nikhil'        => ['nikhil',        NikhilMapper::class],
            'sumit-website' => ['sumit-website', SumitWebsiteMapper::class],
            'canonical'     => ['canonical',     CanonicalMapper::class],
        ];
    }
}
