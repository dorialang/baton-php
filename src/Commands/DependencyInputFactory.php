<?php

declare(strict_types=1);

namespace Doria\Baton\Commands;

use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\DependencyDeclaration;
use Doria\Baton\Manifest\GitDependencySource;
use Doria\Baton\Manifest\GitSelector;
use Doria\Baton\Manifest\GitUrl;
use Doria\Baton\Manifest\PackageVersionConstraint;
use Doria\Baton\Manifest\PackageIdentity;
use Doria\Baton\Manifest\PathDependencySource;
use Symfony\Component\Console\Input\InputInterface;
use UnexpectedValueException;

final class DependencyInputFactory
{
    public function create(InputInterface $input): DependencyDeclaration
    {
        /** @var string $package */
        $package = $input->getArgument('package');
        try {
            PackageIdentity::compilerIdentity($package);
        } catch (UnexpectedValueException) {
            throw $this->error('Dependency Declaration Is Invalid', "Package `{$package}` is not a valid authored dependency identity.");
        }
        $segments = explode('/', $package);
        /** @var string|null $path */
        $path = $input->getOption('path');
        /** @var string|null $git */
        $git = $input->getOption('git');
        if (($path === null || $path === '') === ($git === null || $git === '')) {
            throw $this->error(
                $path !== null && $git !== null ? 'Dependency Source Modes Conflict' : 'Dependency Source Is Missing',
                'Declare exactly one of `--path` or `--git`.',
            );
        }
        /** @var string|null $versionExpression */
        $versionExpression = $input->getOption('version');
        $version = null;
        if ($versionExpression !== null && $versionExpression !== '') {
            try {
                $version = PackageVersionConstraint::parse($versionExpression);
            } catch (UnexpectedValueException) {
                throw $this->error('Dependency Version Constraint Is Invalid', "Constraint `{$versionExpression}` is invalid.");
            }
        }

        if ($path !== null && $path !== '') {
            foreach (['rev', 'tag', 'branch'] as $selector) {
                if ($input->getOption($selector) !== null) {
                    throw $this->error('Dependency Source Modes Conflict', 'Git selectors cannot be used with `--path`.');
                }
            }
            $normalized = str_replace('\\', '/', $path);
            if (str_contains($normalized, "\0")
                || str_starts_with($normalized, '/')
                || preg_match('/^[A-Za-z]:\//', $normalized) === 1
                || preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://#', $normalized) === 1
            ) {
                throw $this->error('Dependency Declaration Is Invalid', '`--path` must be relative to Baton.toml.');
            }

            return new DependencyDeclaration($package, new PathDependencySource($normalized), $version);
        }
        if (count($segments) !== 2) {
            throw $this->error('Dependency Declaration Is Invalid', 'Git dependencies require a scoped `vendor/package` identity.');
        }
        try {
            $url = GitUrl::canonicalize((string) $git);
        } catch (UnexpectedValueException $error) {
            throw $this->error(
                str_contains($error->getMessage(), 'credentials') ? 'Git Source Contains Credentials' : 'Git Source URL Is Invalid',
                'The supplied Git URL is not permitted.',
            );
        }
        $selectors = [];
        foreach (['rev', 'tag', 'branch'] as $kind) {
            /** @var string|null $value */
            $value = $input->getOption($kind);
            if ($value !== null) {
                $selectors[$kind] = $value;
            }
        }
        if (count($selectors) !== 1) {
            throw $this->error(
                $selectors === [] ? 'Git Selector Is Missing' : 'Git Selectors Conflict',
                'Declare exactly one of `--rev`, `--tag`, or `--branch`.',
            );
        }
        $kind = array_key_first($selectors);
        $value = $selectors[$kind];
        try {
            $selector = GitSelector::parse($kind, $value);
        } catch (UnexpectedValueException) {
            throw $this->error('Git ' . ucfirst($kind) . ' Is Invalid', "Git {$kind} `{$value}` is invalid.");
        }

        return new DependencyDeclaration(
            $package,
            new GitDependencySource($url, $selector),
            $version,
        );
    }

    private function error(string $heading, string $body): BatonError
    {
        return new BatonError('B0330', $heading, $body);
    }
}
