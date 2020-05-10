<?php

declare(strict_types=1);

namespace Goat\Query\Symfony\DependencyInjection;

use Doctrine\DBAL\Connection;
use GeneratedHydrator\Bridge\Symfony\DeepHydrator;
use GeneratedHydrator\Bridge\Symfony\GeneratedHydratorBundle;
use Goat\Driver\Configuration;
use Goat\Driver\DriverFactory;
use Goat\Driver\ExtPgSQLDriver;
use Goat\Query\QueryBuilder;
use Goat\Runner\Runner;
use Goat\Runner\Hydrator\GeneratedHydratorBundleHydratorRegistry;
use Goat\Runner\Metadata\ApcuResultMetadataCache;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
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

        $loader = new YamlFileLoader($container, new FileLocator(\dirname(__DIR__).'/Resources/config'));
        $loader->load('services.yaml');

        $this->registerHydratorRegistry($container, $config);

        $this->registerRunnerList($container, $config['runner'] ?? []);

        if (\in_array('Symfony\\Bundle\\WebProfilerBundle\\WebProfilerBundle', $container->getParameter('kernel.bundles'))) {
            // @todo This does not work anyway, for many reasons:
            //   - we need to decorate *all* runners,
            //   - code is not stabilized yet.
            // $loader->load('profiler.yaml');
        }
    }

    /**
     * Autoconfigure hydrator registry.
     */
    private function registerHydratorRegistry(ContainerBuilder $container, array $config): void
    {
        if (\in_array(GeneratedHydratorBundle::class, $container->getParameter('kernel.bundles'))) {
            $definition = (new Definition())
                ->setClass(GeneratedHydratorBundleHydratorRegistry::class)
                ->setArguments([new Reference(DeepHydrator::class)])
                ->setPrivate(true)
            ;
            $container->setDefinition('goat.hydrator_registy', $definition);
        }
    }

    /**
     * Create runner list.
     */
    private function registerRunnerList(ContainerBuilder $container, array $config): void
    {
        if (empty($config)) {
            // If configuration is empty, attempt automatic registration
            // of the 'default' connection using the 'doctrine' driver.
            $runnerDefinition = $this->createDoctrineRunner($container, 'default', $config);
            $this->configureRunner($container, 'default', $config, $runnerDefinition);
            return;
        }

        foreach ($config as $name => $runnerConfig) {
            $this->registerRunner($container, $name, $runnerConfig);
        }
    }

    /**
     * Validate and create a single runner.
     */
    private function registerRunner(ContainerBuilder $container, string $name, array $config): void
    {
        $runnerDefinition = null;
        $runnerDriver = $config['driver'] ?? 'doctrine';

        switch ($runnerDriver) {

            case 'doctrine':
                $runnerDefinition = $this->createDoctrineRunner($container, $name, $config);
                break;

            case 'ext-pgsql':
                $runnerDefinition = $this->createExtPgSqlRunner($container, $name, $config);
                break;

            default: // Configuration should have handled invalid values.
                throw new InvalidArgumentException(\sprintf(
                    "Could not create the goat.runner.%s runner service: driver '%s' is unsupported",
                    $name, $runnerDriver
                ));
        }

        $this->configureRunner($container, $name, $config, $runnerDefinition);
    }

    /**
     * Configure single runner.
     */
    private function configureRunner(ContainerBuilder $container, string $name, array $config, Definition $runnerDefinition): void
    {
        // Metadata cache configuration.
        if ($config['metadata_cache']) {
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

        $runnerDefinition->addMethodCall('setHydratorRegistry', [new Reference('goat.hydrator_registy')]);

        // Create the query builder definition.
        $queryBuilderDefinition = (new Definition())
            ->setClass(QueryBuilder::class)
            ->setShared(false)
            ->setPublic(true)
            ->setFactory([new Reference('goat.runner.'.$name), 'getQueryBuilder'])
        ;

        $container->setDefinition('goat.query_builder.'.$name, $queryBuilderDefinition);
        if ('default' === $name) {
            $container->setAlias(Runner::class, 'goat.runner.default')->setPublic(true);
        }
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
            ->setClass(Runner::class)
            ->setPublic(true)
            ->setFactory([DriverFactory::class, 'fromDoctrineConnection'])
            // @todo should the converter be configurable as well?
            ->setArguments([new Reference($doctrineConnectionServiceId), new Reference('goat.converter.default')])
        ;

        $container->setDefinition($runnerId, $runnerDefinition);

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
            ->setClass(Runner::class)
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
