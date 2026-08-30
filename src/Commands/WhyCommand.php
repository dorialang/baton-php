<?php

declare(strict_types=1);

namespace Doria\Baton\Commands;

use Doria\Baton\Dependency\LockedDependency;
use Doria\Baton\Dependency\LockedGraphLoader;
use Doria\Baton\Dependency\LockedGraphView;
use Doria\Baton\Diagnostics\BatonError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'why', description: 'Explain every locked path to a dependency')]
final class WhyCommand extends BatonCommand
{
    protected function configure(): void
    {
        $this->addArgument('dependency', InputArgument::REQUIRED, 'Locked package identity');
        WorkspaceOptions::configure($this, true);
        $this->addOption('development', null, InputOption::VALUE_NONE, 'Include development and processor edges');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $target */
        $target = $input->getArgument('dependency');
        $graph = (new LockedGraphLoader())->load(WorkspaceOptions::select($input, true, 'why'));
        if (!isset($graph->packages[$target]) && !in_array($target, $graph->roots, true)) {
            $known = array_unique([...$graph->roots, ...array_keys($graph->packages)]);
            sort($known, SORT_STRING);
            throw new BatonError(
                'B0383',
                'Dependency Is Unknown',
                "Package `{$target}` is not in the locked graph.\nKnown packages: " . implode(', ', $known),
            );
        }
        $paths = [];
        foreach ($graph->roots as $root) {
            if ($root === $target) {
                $paths[] = [$root];
            }
            $this->find($graph, $graph->rootEdges[$root], $target, [$root], (bool) $input->getOption('development'), $paths);
        }
        usort($paths, static fn (array $left, array $right): int => strcmp(implode("\0", $left), implode("\0", $right)));
        if ($paths === []) {
            throw new BatonError(
                'B0383',
                'Dependency Is Not Active',
                "Package `{$target}` is locked but not reachable with the selected dependency categories.",
            );
        }
        foreach ($paths as $path) {
            $output->writeln(implode(' -> ', $path));
        }

        return self::SUCCESS;
    }

    /**
     * @param list<LockedDependency> $edges
     * @param list<string> $chain
     * @param list<list<string>> $paths
     */
    private function find(
        LockedGraphView $graph,
        array $edges,
        string $target,
        array $chain,
        bool $development,
        array &$paths,
    ): void {
        foreach ($edges as $edge) {
            if (!$development && $edge->kind->value !== 'normal') {
                continue;
            }
            if (in_array($edge->package, $chain, true)) {
                continue;
            }
            $next = [...$chain, $edge->package];
            if ($edge->package === $target) {
                $paths[] = $next;
            }
            $this->find($graph, $graph->packages[$edge->package]->dependencies, $target, $next, $development, $paths);
        }
    }
}
