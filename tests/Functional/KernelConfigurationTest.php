<?php

declare(strict_types=1);

namespace Goat\Driver\Symfony\Tests\Functional;

use Goat\Query\Symfony\DependencyInjection\GoatQueryExtension;
use Goat\Runner\Runner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

final class KernelConfigurationTest extends TestCase
{
    private function getContainer()
    {
        // Code inspired by the SncRedisBundle, all credits to its authors.
        return new ContainerBuilder(new ParameterBag([
            'kernel.debug'=> false,
            'kernel.bundles' => [],
            'kernel.cache_dir' => \sys_get_temp_dir(),
            'kernel.environment' => 'test',
            'kernel.root_dir' => \dirname(__DIR__),
        ]));
    }

    private function getMinimalConfig(): array
    {
        return [
            'runner' => [
                'default' => [
                    'driver' => 'ext-pgsql',
                ],
                'logging' => [
                    'driver' => 'doctrine',
                    'doctrine_connection' => 'default',
                ]
            ],
        ];
    }

    /**
     * Test default config for resulting tagged services
     */
    public function testTaggedServicesConfigLoad()
    {
        $extension = new GoatQueryExtension();
        $config = $this->getMinimalConfig();
        $extension->load([$config], $container = $this->getContainer());

        self::assertTrue($container->hasAlias(Runner::class));
        self::assertTrue($container->hasDefinition('goat.runner.default'));
        self::assertTrue($container->hasDefinition('goat.runner.logging'));
    }
}
