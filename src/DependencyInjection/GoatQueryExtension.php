<?php

declare(strict_types=1);

namespace Goat\Query\Symfony\DependencyInjection;

use GeneratedHydrator\Bridge\Symfony\DeepHydrator;
use GeneratedHydrator\Bridge\Symfony\GeneratedHydratorBundle;
use Goat\Converter\ConverterInterface;
use Goat\Converter\ValueConverterRegistry;
use Goat\Query\Symfony\Command\GraphvizCommand;
use Goat\Query\Symfony\Command\InspectCommand;
use Goat\Query\Symfony\Command\PgSQLStatCommand;
use Goat\Query\Symfony\DataCollector\RunnerDataCollector;
use Goat\Query\Symfony\Twig\ProfilerExtension;
use Goat\Runner\Hydrator\GeneratedHydratorBundleRegistry;
use MakinaCorpus\Profiling\Bridge\Symfony5\ProfilingBundle;
use MakinaCorpus\Profiling\ProfilerContext;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

final class GoatQueryExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = $this->getConfiguration($configs, $container);
        $config = $this->processConfiguration($configuration, $configs);

        $hydratorRegistryEnabled = $this->registerHydratorRegistry($container, $config);
        $this->registerDefaultConverter($container, $config);

        $runnerServicesList = [];
        foreach (($config['runner'] ?? []) as $name => $runnerConfig) {
            $runnerServicesList[$name] = ServiceDefinitionHelper::registerRunner($container, $name, $runnerConfig, $hydratorRegistryEnabled);
        }
        $container->setParameter('goat.existing_runners', $runnerServicesList);

        if ($runnerServicesList) {
            if (\in_array(WebProfilerBundle::class, $container->getParameter('kernel.bundles')) &&
                \in_array(ProfilingBundle::class, $container->getParameter('kernel.bundles'))
            ) {
                $this->registerWebProfiler($container, $config, $runnerServicesList);
            }
            if (\class_exists(Command::class)) {
                $this->registerConsoleCommands($container, $config, $runnerServicesList);
            }
        }
    }

    /**
     * Register console commands.
     */
    private function registerConsoleCommands(ContainerBuilder $container, array $config, array $runnerServicesList): void
    {
        foreach ($runnerServicesList as $serviceId) {
            $definition = new Definition();
            $definition->setClass(GraphvizCommand::class);
            $definition->setArguments([new Reference($serviceId)]);
            $definition->addTag('console.command');
            $container->setDefinition(GraphvizCommand::class, $definition);

            $definition = new Definition();
            $definition->setClass(InspectCommand::class);
            $definition->setArguments([new Reference($serviceId)]);
            $definition->addTag('console.command');
            $container->setDefinition(InspectCommand::class, $definition);

            $definition = new Definition();
            $definition->setClass(PgSQLStatCommand::class);
            $definition->setArguments([new Reference($serviceId)]);
            $definition->addTag('console.command');
            $container->setDefinition(PgSQLStatCommand::class, $definition);

            // @todo We register only one for the first runner in order,
            //    but it might be nice to have more than one actually.
            //    A place to start with would be to have a runner registry
            //    which allows listing and fetching them.
            break;
        }
    }

    /**
     * Register default converter.
     */
    private function registerDefaultConverter(ContainerBuilder $container, array $config): void
    {
        $defaultConverter = new Definition(ValueConverterRegistry::class);
        $defaultConverter->setPublic(false);
        $container->setDefinition('goat.converter.registry', $defaultConverter);
        $container->setAlias(ConverterInterface::class, 'goat.converter.registry');
    }

    /**
     * Register web profiler services.
     */
    private function registerWebProfiler(ContainerBuilder $container, array $config, array $runnerServicesList): void
    {
        $profilerTwigExtension = new Definition(ProfilerExtension::class);
        $profilerTwigExtension->setPublic(false);
        $profilerTwigExtension->addTag('twig.extension');

        $runnerDataCollector = new Definition(RunnerDataCollector::class);
        $runnerDataCollector->addTag(
            'data_collector',
            [
                'template' => '@GoatQuery/profiler/goat.html.twig',
                'id' => 'goat_runner'
            ]
        );
        $runnerDataCollector->setArguments([
            \array_map(
                fn (string $id) => new Reference($id),
                $runnerServicesList
            ),
            new Reference(ProfilerContext::class)
        ]);

        // Enable debug mode on each runner, in order to collect executed SQL
        // in query profiler results.
        foreach ($runnerServicesList as $serviceId) {
            // Service could be an alias.
            if ($container->hasAlias($serviceId)) {
                $serviceId = (string)$container->getAlias($serviceId);
            }
            $definition = $container->getDefinition($serviceId);
            $definition->addMethodCall('setDebug', [true]);
        }

        $container->addDefinitions([
            ProfilerExtension::class => $profilerTwigExtension,
            RunnerDataCollector::class => $runnerDataCollector,
        ]);
    }

    /**
     * Autoconfigure hydrator registry.
     */
    private function registerHydratorRegistry(ContainerBuilder $container, array $config): bool
    {
        if (\in_array(GeneratedHydratorBundle::class, $container->getParameter('kernel.bundles'))) {
            $definition = (new Definition())
                ->setClass(GeneratedHydratorBundleRegistry::class)
                ->setArguments([new Reference(DeepHydrator::class)])
                ->setPublic(false)
            ;
            $container->setDefinition('goat.hydrator_registy', $definition);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfiguration(array $config, ContainerBuilder $container)
    {
        return new GoatQueryConfiguration();
    }
}
