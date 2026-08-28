<?php

declare(strict_types=1);

namespace Doria\Baton\Console;

use Symfony\Component\Console\Input\ArgvInput;

/** Keeps `add --version` command-local while retaining top-level `baton --version`. */
final class BatonArgvInput extends ArgvInput
{
    /** @param string|list<string> $values */
    public function hasParameterOption(string|array $values, bool $onlyParams = false): bool
    {
        $versionProbe = is_array($values)
            && in_array('--version', $values, true);
        if ($versionProbe && $this->getFirstArgument() === 'add') {
            return false;
        }

        return parent::hasParameterOption($values, $onlyParams);
    }
}
