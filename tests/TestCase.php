<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // PHP tests must not depend on `npm run build`; asset bundling is verified by the frontend build.
        $this->withoutVite();
    }
}
