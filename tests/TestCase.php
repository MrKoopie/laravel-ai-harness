<?php

namespace Yourwebhoster\LaravelAiHarness\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Yourwebhoster\LaravelAiHarness\LaravelAiHarnessServiceProvider;

class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaravelAiHarnessServiceProvider::class,
        ];
    }
}
