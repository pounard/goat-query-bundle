<?php

declare(strict_types=1);

namespace Goat\Query\Symfony\DependencyInjection;

use Doctrine\DBAL\Connection;
use GeneratedHydrator\Bridge\Symfony\DeepHydrator;
use GeneratedHydrator\Bridge\Symfony\GeneratedHydratorBundle;
use Goat\Converter\ConverterInterface;
use Goat\Converter\ValueConverterRegistry;
use Goat\Driver\Configuration;
use Goat\Driver\Driver;
use Goat\Driver\DriverFactory;
use Goat\Driver\ExtPgSQLDriver;
use Goat\Driver\Runner\AbstractRunner;
use Goat\Query\QueryBuilder;
use Goat\Query\Symfony\Command\GraphvizCommand;
use Goat\Query\Symfony\Command\InspectCommand;
use Goat\Query\Symfony\Command\PgSQLStatCommand;
use Goat\Query\Symfony\DataCollector\RunnerDataCollector;
use Goat\Query\Symfony\Twig\ProfilerExtension;
use Goat\Runner\Runner;
use Goat\Runner\Hydrator\GeneratedHydratorBundleRegistry;
use Goat\Runner\Metadata\ApcuResultMetadataCache;
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
        $runnerServicesList = $this->registerRunnerList($container, $config['runner'] ?? [], $hydratorRegistryEnabled);

        if ($runnerServicesList) {
            if (\in_array(WebProfilerBundle::class, $container->getParameter('kernel.bundles'))) {
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
     * Create runner list.
     *
     * @return string[]
     *   Runner services identifiers found.
     */
    private function registerRunnerList(ContainerBuilder $container, array $config, bool $hydratorRegistryEnabled): array
    {
        $ret = [];
        foreach ($config as $name => $runnerConfig) {
            $ret[] = $this->registerRunner($container, $name, $runnerConfig, $hydratorRegistryEnabled);
        }

        return $ret;
    }

    /**
     * Validate and create a single runner.
     *
     * @return string
     *   The configured runner service identifier.
     */
    private function registerRunner(ContainerBuilder $container, string $name, array $config, bool $hydratorRegistryEnabled): string
    {
        $runnerDefinition = null;
        $runnerDriver = $config['driver'] ?? null;

        // Driver can be null, case in which implementation and options will
        // be determined at runtime using the DriverFactory.
        switch ($runnerDriver) {

            case 'doctrine':
                $runnerDefinition = $this->createDoctrineRunner($container, $name, $config);
                break;

            case Configuration::DRIVER_EXT_PGSQL:
                $runnerDefinition = $this->createExtPgSqlRunner($container, $name, $config);
                break;

            default:
                $runnerDefinition = $this->createDefaultRunnerFromUrl($container, $name, $config);
                break;
        }

        return $this->configureRunner($container, $name, $config, $runnerDefinition, $hydratorRegistryEnabled);
    }

    /**
     * Configure single runner.
     *
     * @return string
     *   The configured runner service identifier.
     */
    private function configureRunner(ContainerBuilder $container, string $name, array $config, Definition $runnerDefinition, bool $hydratorRegistryEnabled): string
    {
        // Metadata cache configuration.
        if (isset($config['metadata_cache'])) {
            switch ($config['metadata_cache']) {

                case 'array': // Do nothing, it's the default.
                    break;

                case 'apcu':
                    if (isset($config['metadata_cache_prefix'])) {
                        $cachePrefix = (string)$config['metadata_cache_prefix'];
                    } else {
                        $cachePrefix = 'goat_metadata_cache.'.$name;
                    }
                    // @todo raise error if APCu is not present or disabled.
                    $metadataCacheDefinition = (new Definition())
                        ->setClass(ApcuResultMetadataCache::class)
                        ->setArguments([$cachePrefix])
                        ->setPublic(false)
                    ;
                    $container->setDefinition('goat.result_metadata_cache', $metadataCacheDefinition);
                    $runnerDefinition->addMethodCall('setResultMetadataCache', [new Reference('goat.result_metadata_cache')]);
                    break;

                default: // Configuration should have handled invalid values.
                    throw new \InvalidArgumentException();
            }
        }

        $runnerDefinition->addMethodCall('setValueConverterRegistry', [new Reference('goat.converter.registry')]);
        if ($hydratorRegistryEnabled) {
            $runnerDefinition->addMethodCall('setHydratorRegistry', [new Reference('goat.hydrator_registy')]);
        }

        // Using this requires that runners not to be identifier by the Runner
        // interface, but with a concrete implementation instead, such as the
        // AbstractRunner, otherwise ProfilerContextAware interface will not
        // be found by makinacorpus/profiling bundle and it will crash during
        // cache clear.
        $runnerDefinition->addTag('profiling.profiler_aware', ['channel' => 'sql']);

        $runnerServiceId = 'goat.runner.'.$name;

        // Create the query builder definition.
        $queryBuilderDefinition = (new Definition())
            ->setClass(QueryBuilder::class)
            ->setShared(false)
            ->setPublic(true)
            ->setFactory([new Reference($runnerServiceId), 'getQueryBuilder'])
        ;

        $container->setDefinition('goat.query_builder.'.$name, $queryBuilderDefinition);
        if ('default' === $name) {
            $container->setAlias(Runner::class, 'goat.runner.default')->setPublic(true);
        }

        return $runnerServiceId;
    }

    /**
     * Create a single doctrine runner.
     */
    private function createDoctrineRunner(ContainerBuilder $container, string $name, array $config): Definition
    {
        /*
         * @todo
         *   Find a smart way to do this, actually we cannot because when
         *   configuring the extension, we are isolated from the rest, and
         *   I'm too lazy to write a compilation pass right now.
         *
        if (!$container->hasDefinition($doctrineConnectionServiceId) && !$container->hasAlias($doctrineConnectionServiceId)) {
            throw new InvalidArgumentException(\sprintf(
                "Could not create the goat.runner.%s runner service: could not find %s doctrine/dbal connection service",
                $name, $doctrineConnectionServiceId
            ));
        }
         */

        $runnerId = "goat.runner.".$name;

        $doctrineConnectionServiceId = Connection::class;
        if (isset($config['doctrine_connection'])) {
            $doctrineConnectionServiceId = 'doctrine.dbal.'.$config['doctrine_connection'].'_connection';
        }

        $runnerDefinition = (new Definition())
            ->setClass(AbstractRunner::class)
            ->setPublic(true)
            ->setFactory([DriverFactory::class, 'doctrineConnectionRunner'])
            // @todo should the converter be configurable as well?
            ->setArguments([new Reference($doctrineConnectionServiceId)])
        ;

        $container->setDefinition($runnerId, $runnerDefinition);

        return $runnerDefinition;
    }

    /**
     * Default runner creation with some bits of magic.
     */
    private function createDefaultRunnerFromUrl(ContainerBuilder $container, string $name, array $config): Definition
    {
        $configurationId = 'goat.runner.'.$name.'.conf';
        $driverId = 'goat.runner.'.$name.'.driver';
        $runnerId = 'goat.runner.'.$name;

        $configurationDefinition = (new Definition())
            ->setClass(Configuration::class)
            ->setPublic(false)
            ->setFactory([Configuration::class, 'fromString'])
            ->setArguments([$config['url']])
        ;

        $driverDefinition = (new Definition())
            ->setClass(Driver::class)
            ->setPublic(false)
            ->setFactory([DriverFactory::class, 'fromConfiguration'])
            ->setArguments([new Reference($configurationId)])
        ;

        $runnerDefinition = (new Definition())
            ->setClass(AbstractRunner::class)
            ->setPublic(true)
            ->setFactory([new Reference($driverId), 'getRunner'])
        ;

        $container->addDefinitions([
            $configurationId => $configurationDefinition,
            $driverId => $driverDefinition,
            $runnerId => $runnerDefinition,
        ]);

        return $runnerDefinition;
    }

    /**
     * Create a single ext-pgsql runner.
     */
    private function createExtPgSqlRunner(ContainerBuilder $container, string $name, array $config): Definition
    {
        $configurationId = 'goat.runner.'.$name.'.conf';
        $driverId = 'goat.runner.'.$name.'.driver';
        $runnerId = 'goat.runner.'.$name;

        $configurationDefinition = (new Definition())
            ->setClass(Configuration::class)
            ->setPublic(false)
            ->setFactory([Configuration::class, 'fromString'])
            ->setArguments([$config['url']])
        ;

        $driverDefinition = (new Definition())
            ->setClass(ExtPgSQLDriver::class)
            ->setPublic(false)
            ->addMethodCall('setConfiguration', [new Reference($configurationId)])
        ;

        $runnerDefinition = (new Definition())
            ->setClass(AbstractRunner::class)
            ->setPublic(true)
            ->setFactory([new Reference($driverId), 'getRunner'])
        ;

        $container->addDefinitions([
            $configurationId => $configurationDefinition,
            $driverId => $driverDefinition,
            $runnerId => $runnerDefinition,
        ]);

        return $runnerDefinition;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfiguration(array $config, ContainerBuilder $container)
    {
        return new GoatQueryConfiguration();
    }
}
