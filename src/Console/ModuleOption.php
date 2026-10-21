<?php

declare(strict_types=1);

namespace Distributable\Console;

use Closure;
use Distributable\Context\ModuleContext;
use Distributable\Services\ModuleRegistry;
use Illuminate\Console\Command;
use Illuminate\Console\GeneratorCommand;
use Illuminate\Container\Container;
use Illuminate\Database\Console\Migrations\MigrateMakeCommand;
use Illuminate\Foundation\Console\ProviderMakeCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Every Artisan command takes --module: a generator writes in that module, any other command runs once in each module named. */
final class ModuleOption
{
    public static function addTo(Command $command): void
    {
        // A module has one provider, registered by the package: make:provider would add another to bootstrap/providers.php.
        if ($command instanceof ProviderMakeCommand || $command->getDefinition()->hasOption('module')) {
            return;
        }

        if ($command instanceof GeneratorCommand || $command instanceof MigrateMakeCommand) {
            $command->addOption('module', null, InputOption::VALUE_REQUIRED, 'Generate in this module, under its namespace');

            return;
        }

        $command->addOption('module', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Run in the context of these modules, one after the other, or * for every module this process runs');
        $command->setCode(static fn (InputInterface $input, OutputInterface $output): int => self::runInEach($command, $input, $output));
    }

    private static function runInEach(Command $command, InputInterface $input, OutputInterface $output): int
    {
        $named = array_map(strval(...), (array) $input->getOption('module'));
        $execute = Closure::bind(fn (): int => $this->execute($input, $output), $command, Command::class);

        if ($named === []) {
            return $execute();
        }

        $app = Container::getInstance();
        $registry = $app->make(ModuleRegistry::class);
        $modules = in_array('*', $named, true) ? $registry->local() : array_map($registry->get(...), array_values(array_unique($named)));
        $exitCode = Command::SUCCESS;

        foreach ($modules as $module) {
            if ($named !== [$module->name]) {
                $output->writeln('');
                $output->writeln("  <info>Module [{$module->name}]</info>");
            }

            // The command reads --module as the one module it runs in.
            $input->setOption('module', [$module->name]);
            $exitCode = max($exitCode, $app->make(ModuleContext::class)->within($module, $execute));
        }

        $input->setOption('module', $named);

        return $exitCode;
    }
}
