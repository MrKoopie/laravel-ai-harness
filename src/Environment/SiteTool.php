<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Environment;

enum SiteTool: string
{
    case Herd = 'herd';
    case Valet = 'valet';
    case Yerd = 'yerd';

    /** Return the site tool that serves the given runtime, if any. */
    public static function forRuntime(Runtime $runtime): ?self
    {
        return match ($runtime) {
            Runtime::Herd => self::Herd,
            Runtime::Valet => self::Valet,
            Runtime::Yerd => self::Yerd,
            default => null,
        };
    }

    /** Return the product name used in console output. */
    public function label(): string
    {
        return match ($this) {
            self::Herd => 'Herd',
            self::Valet => 'Valet',
            self::Yerd => 'Yerd',
        };
    }
}
