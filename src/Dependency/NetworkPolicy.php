<?php

declare(strict_types=1);

namespace Doria\Baton\Dependency;

enum NetworkPolicy: string
{
    case Online = 'online';
    case Offline = 'offline';

    public function permitsNetwork(): bool
    {
        return $this === self::Online;
    }
}
