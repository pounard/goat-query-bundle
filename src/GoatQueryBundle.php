<?php

declare(strict_types=1);

namespace Goat\Query\Symfony;

use Goat\Query\Symfony\DependencyInjection\GoatQueryExtension;
use Goat\Query\Symfony\DependencyInjection\Compiler\RegisterConverterPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
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
        $container->addCompilerPass(new RegisterConverterPass());
    }

    /**
     * {@inheritdoc}
     */
    public function getContainerExtension()
    {
        return new GoatQueryExtension();
    }
}
