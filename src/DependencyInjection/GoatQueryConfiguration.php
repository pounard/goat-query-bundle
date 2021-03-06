<?php

declare(strict_types=1);

namespace Goat\Query\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class GoatQueryConfiguration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder('goat_query');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->arrayNode('runner')
                    ->normalizeKeys(true)
                    ->prototype('array')
                        ->children()
                            ->booleanNode('autocommit')
                                ->info("Set autocommit, for now only ('doctrine' driver only)")
                                ->defaultFalse()
                            ->end()
                            ->scalarNode('doctrine_connection')
                                ->info("Doctrine connection to use ('doctrine' driver only)")
                                ->defaultNull()
                            ->end()
                            ->scalarNode('url')
                                ->info("Database URL")
                                ->defaultNull()
                            ->end()
                            ->scalarNode('driver')
                                ->info('Driver to use')
                                ->defaultNull()
                            ->end()
                            ->enumNode('metadata_cache')
                                ->info("Enable metadata cache, 'doctrine' and 'pdo-*' drivers should use this")
                                ->values([null, 'array', 'apcu', 'php'])
                                ->defaultNull()
                            ->end()
                            ->scalarNode('metadata_cache_prefix')
                                ->defaultNull()
                            ->end()
                            ->scalarNode('metadata_cache_php_filename')
                                ->defaultNull()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('query')
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
