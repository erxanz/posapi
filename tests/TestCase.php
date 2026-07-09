<?php

namespace Tests;

use Illuminate\Support\Facades\Config;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Pastikan driver broadcast dan session di-set untuk testing
        // karena phpunit.xml env vars tidak selalu terbaca di environment ini.
        Config::set('broadcasting.default', 'null');
        Config::set('session.driver', 'array');
    }
}
