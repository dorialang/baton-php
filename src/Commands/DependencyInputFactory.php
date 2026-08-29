<?php

declare(strict_types=1);

namespace Doria\Baton\Commands;

use Doria\Baton\Diagnostics\BatonError;
use Doria\Baton\Manifest\DependencyDeclaration;
use Doria\Baton\Manifest\DependencyKind;
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
        /** @var string|null $legacyGit */
        $legacyGit = $input->getOption('git');
        if ($legacyGit !== null) {
            throw $this->error(
                'Git Source Locator Spelling Has Changed',
                'Git remains supported. Replace `--git <url>` with `--source git --url <url>`.',
            );
        }
        /** @var string|null $sourceKind */
        $sourceKind = $input->getOption('source');
        if ($sourceKind === null || $sourceKind === '') {
            throw $this->error(
                'Dependency Source Must Be Declared',
                'Declare `--source path` or `--source git` explicitly.',
            );
        }
        if (!in_array($sourceKind, ['path', 'git'], true)) {
            throw $this->error(
                'Dependency Source Is Unsupported',
                "Source transport `{$sourceKind}` is not implemented; use `path` or `git`.",
            );
        }
        /** @var string|null $path */
        $path = $input->getOption('path');
        /** @var string|null $urlOption */
        $urlOption = $input->getOption('url');
        $dependencyKind = (bool) $input->getOption('dev')
            ? DependencyKind::Development
            : DependencyKind::Normal;
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

        if ($sourceKind === 'path') {
            if ($path === null || $path === '') {
                throw $this->error('Dependency Declaration Is Invalid', '`--source path` requires `--path`.');
            }
            if ($urlOption !== null) {
                throw $this->error('Dependency Source Modes Conflict', '`--source path` cannot be combined with `--url`.');
            }
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

            return new DependencyDeclaration($package, new PathDependencySource($normalized), $version, $dependencyKind);
        }
        if ($path !== null) {
            throw $this->error('Dependency Source Modes Conflict', '`--source git` cannot be combined with `--path`.');
        }
        if ($urlOption === null || $urlOption === '') {
            throw $this->error('Dependency Declaration Is Invalid', '`--source git` requires `--url`.');
        }
        if (count($segments) !== 2) {
            throw $this->error('Dependency Declaration Is Invalid', 'Git dependencies require a scoped `vendor/package` identity.');
        }
        try {
            $url = GitUrl::canonicalize($urlOption);
        } catch (UnexpectedValueException $error) {
            throw $this->error(
                str_contains($error->getMessage(), 'credentials') ? 'Git Source Contains Credentials' : 'Git Source URL Is Invalid',
                'The supplied Git URL is not permitted.',
            );
        }
        $selectors = [];
        foreach (['rev', 'tag', 'branch'] as $selectorKind) {
            /** @var string|null $value */
            $value = $input->getOption($selectorKind);
            if ($value !== null) {
                $selectors[$selectorKind] = $value;
            }
        }
        if (count($selectors) !== 1) {
            throw $this->error(
                $selectors === [] ? 'Git Selector Is Missing' : 'Git Selectors Conflict',
                'Declare exactly one of `--rev`, `--tag`, or `--branch`.',
            );
        }
        $selectorKind = array_key_first($selectors);
        $value = $selectors[$selectorKind];
        try {
            $selector = GitSelector::parse($selectorKind, $value);
        } catch (UnexpectedValueException) {
            throw $this->error(
                'Git ' . ucfirst($selectorKind) . ' Is Invalid',
                "Git {$selectorKind} `{$value}` is invalid.",
            );
        }

        return new DependencyDeclaration(
            $package,
            new GitDependencySource($url, $selector),
            $version,
            $dependencyKind,
        );
    }

    private function error(string $heading, string $body): BatonError
    {
        return new BatonError('B0330', $heading, $body);
    }
}
