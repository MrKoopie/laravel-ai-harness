<?php

namespace MrKoopie\LaravelAiHarness\Tests;

use MrKoopie\LaravelAiHarness\LaravelAiHarnessServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

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
