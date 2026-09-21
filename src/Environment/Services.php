<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Environment;

enum Services: string
{
    case None = 'none';
    case Sail = 'sail';
}
