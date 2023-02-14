<?php

declare(strict_types=1);

namespace Goat\Query\Symfony;

use Goat\Query\Symfony\DependencyInjection\GoatQueryExtension;
use Goat\Query\Symfony\DependencyInjection\Compiler\RegisterConverterPass;
use Goat\Query\Symfony\DependencyInjection\Compiler\RegisterDoctrineRunnersPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * @codeCoverageIgnore
 */
final class GoatQueryBundle extends Bundle
{
    /**
     * {@inheritdoc}
     */
    public function build(ContainerBuilder $container)
    {
        $container->addCompilerPass(new RegisterDoctrineRunnersPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION);
        $container->addCompilerPass(new RegisterConverterPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION);
    }

    /**
     * {@inheritdoc}
     */
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new GoatQueryExtension();
    }
}
