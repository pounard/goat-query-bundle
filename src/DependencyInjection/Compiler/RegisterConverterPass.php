<?php

declare(strict_types=1);

namespace Goat\Query\Symfony\DependencyInjection\Compiler;

use Goat\Converter\ValueConverterInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

final class RegisterConverterPass implements CompilerPassInterface
{
    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container)
    {
        $converterDefinition = $container->getDefinition('goat.converter.registry');

        foreach (\array_keys($container->findTaggedServiceIds('goat.value_converter', true)) as $id) {
            $definition = $container->getDefinition($id);
            $class = $definition->getClass();

            if (!$reflexion = $container->getReflectionClass($class)) {
                throw new InvalidArgumentException(sprintf('Class "%s" used for service "%s" cannot be found.', $class, $id));
            }
            if (!$reflexion->implementsInterface(ValueConverterInterface::class)) {
                throw new InvalidArgumentException(sprintf('Service "%s" must implement interface "%s".', $id, ValueConverterInterface::class));
            }

            $converterDefinition->addMethodCall('register', [new Reference($id)]);
        }
    }
}
