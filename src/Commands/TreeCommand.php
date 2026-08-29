<?php

declare(strict_types=1);

namespace Doria\Baton\Commands;

use Doria\Baton\Dependency\LockedDependency;
use Doria\Baton\Dependency\LockedGraphLoader;
use Doria\Baton\Dependency\LockedGraphView;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'tree', description: 'Show the exact locked dependency tree')]
final class TreeCommand extends BatonCommand
{
    protected function configure(): void
    {
        WorkspaceOptions::configure($this, true);
        $this->addOption('development', null, InputOption::VALUE_NONE, 'Include development and processor edges');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $selection = WorkspaceOptions::select($input, true, 'tree');
        $graph = (new LockedGraphLoader())->load($selection);
        $development = (bool) $input->getOption('development');
        $expanded = [];
        foreach ($graph->roots as $root) {
            $output->writeln($root);
            $this->renderEdges($output, $graph, $graph->rootEdges[$root], '', $development, $expanded);
        }

        return self::SUCCESS;
    }

    /**
     * @param list<LockedDependency> $edges
     * @param array<string, true> $expanded
     */
    private function renderEdges(
        OutputInterface $output,
        LockedGraphView $graph,
        array $edges,
        string $indent,
        bool $development,
        array &$expanded,
    ): void {
        $visible = array_values(array_filter(
            $edges,
            static fn (LockedDependency $edge): bool => $development || $edge->kind->value === 'normal',
        ));
        foreach ($visible as $index => $edge) {
            $last = $index === array_key_last($visible);
            $package = $graph->packages[$edge->package];
            $repeated = isset($expanded[$edge->package]);
            $output->writeln(
                $indent . ($last ? '└── ' : '├── ')
                . "{$edge->package} {$package->version} [{$edge->kind->value}]"
                . ($repeated ? ' (repeated)' : ''),
            );
            if ($repeated) {
                continue;
            }
            $expanded[$edge->package] = true;
            $this->renderEdges(
                $output,
                $graph,
                $package->dependencies,
                $indent . ($last ? '    ' : '│   '),
                $development,
                $expanded,
            );
        }
    }
}
