<?php

declare(strict_types=1);

namespace MrKoopie\LaravelAiHarness\Environment;

enum Runtime: string
{
    case Native = 'native';
    case Herd = 'herd';
    case Sail = 'sail';
}
