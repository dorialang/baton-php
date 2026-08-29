<?php

declare(strict_types=1);

namespace Doria\Baton\Manifest;

enum DependencyKind: string
{
    case Normal = 'normal';
    case Development = 'development';
    case Processor = 'processor';
}
