<?php

declare(strict_types=1);

namespace Goat\Query\Symfony\DependencyInjection\Compiler;

use Goat\Query\Symfony\DependencyInjection\ServiceDefinitionHelper;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;

final class RegisterDoctrineRunnersPass implements CompilerPassInterface
{
    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container)
    {
        if (!$container->hasParameter('doctrine.connections')) {
            return;
        }

        $hydratorRegistryEnabled = $container->hasDefinition('goat.hydrator_registy');

        // Prevent existing runner names from conflicting with Doctrine DBAL
        // registered runner names.
        $existingRunners = $container->getParameter('goat.existing_runners');
        $doctrineConnections = $container->getParameter('doctrine.connections');

        foreach ($doctrineConnections as $name => $doctrineServiceId) {
            if (isset($existingRunners[$name])) {
                // Ignore name conflicts.
                continue;
            }

            $existingRunners[$name] = ServiceDefinitionHelper::registerRunner(
                $container,
                $name,
                [
                    'driver' => 'doctrine',
                    'doctrine_connection' => $name,
                ],
                $hydratorRegistryEnabled
            );
        }

        $container->setParameter('goat.existing_runners', $existingRunners);
    }
}
