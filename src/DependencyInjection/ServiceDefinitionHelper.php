<?php

declare(strict_types=1);

namespace Goat\Query\Symfony\DependencyInjection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Goat\Driver\Configuration;
use Goat\Driver\DriverFactory;
use Goat\Driver\ExtPgSQLDriver;
use Goat\Driver\Runner\AbstractRunner;
use Goat\Query\QueryBuilder;
use Goat\Runner\Runner;
use Goat\Runner\Metadata\ApcuResultMetadataCache;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

class ServiceDefinitionHelper
{
    /**
     * Validate and create a single runner.
     *
     * @return string
     *   The configured runner service identifier.
     */
    public static function registerRunner(ContainerBuilder $container, string $name, array $config = [], bool $hydratorRegistryEnabled = false): string
    {
        $runnerDefinition = null;
        $runnerDriver = $config['driver'] ?? null;

        // Driver can be null, case in which implementation and options will
        // be determined at runtime using the DriverFactory.
        switch ($runnerDriver) {

            case 'doctrine':
                $runnerDefinition = self::createDoctrineRunner($container, $name, $config);
                break;

            case Configuration::DRIVER_EXT_PGSQL:
                $runnerDefinition = self::createExtPgSqlRunner($container, $name, $config);
                break;

            default:
                $runnerDefinition = self::createDefaultRunnerFromUrl($container, $name, $config);
                break;
        }

        return self::configureRunner($container, $name, $config, $runnerDefinition, $hydratorRegistryEnabled);
    }

    /**
     * Configure single runner.
     *
     * @return string
     *   The configured runner service identifier.
     */
    public static function configureRunner(ContainerBuilder $container, string $name, array $config, Definition $runnerDefinition, bool $hydratorRegistryEnabled = false): string
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
    public static function createDoctrineRunner(ContainerBuilder $container, string $name, array $config): Definition
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

        if (!isset($config['doctrine_connection'])) {
            throw new InvalidArgumentException(\sprintf("Goat runner '%s' using 'doctrine' driver is missing the 'doctrine_connection' option.", $name));
        }

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
    public static function createDefaultRunnerFromUrl(ContainerBuilder $container, string $name, array $config): Definition
    {
        if (!isset($config['url'])) {
            throw new InvalidArgumentException(\sprintf("Goat runner '%s' using database URL is missing the 'url' option.", $name));
        }

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
    public static function createExtPgSqlRunner(ContainerBuilder $container, string $name, array $config): Definition
    {
        if (!isset($config['url'])) {
            throw new InvalidArgumentException(\sprintf("Goat runner '%s' using 'pgsql' driver is missing the 'url' option.", $name));
        }

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
}
