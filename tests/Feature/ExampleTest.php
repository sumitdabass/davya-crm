<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_root_redirects_to_admin(): void
    {
        $this->get('/')->assertRedirect('/admin');
    }

    public function test_all_responses_carry_noindex_header(): void
    {
        $this->get('/')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
    }
}
