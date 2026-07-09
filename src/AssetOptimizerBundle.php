<?php

declare(strict_types=1);

namespace AssetOptimizer;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use function dirname;

/**
 * Config root key: `asset_optimizer`.
 */
final class AssetOptimizerBundle extends AbstractBundle
{
    // Bundle class lives in src/; the bundle root (holding tools/ and config/) is its parent.
    public function getPath(): string
    {
        return dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->arrayNode('js')->addDefaultsIfNotSet()
            ->children()->booleanNode('enabled')->defaultTrue()->end()->end()
            ->end()
            ->arrayNode('css')->addDefaultsIfNotSet()
            ->children()->booleanNode('enabled')->defaultTrue()->end()->end()
            ->end()
            ->arrayNode('svg')->addDefaultsIfNotSet()
            ->children()->booleanNode('enabled')->defaultTrue()->end()->end()
            ->end()
            ->arrayNode('jpg_png')->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')->defaultTrue()->end()
            ->integerNode('quality')->defaultValue(80)->end()
            ->end()
            ->end()
            ->arrayNode('webp')->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')->defaultTrue()->end()
            ->integerNode('quality')->defaultValue(80)->end()
            ->end()
            ->end()
            ->arrayNode('ignore_paths')
            ->scalarPrototype()->end()
            ->defaultValue(['*.min.js', '*.min.css'])
            ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->setParameter('asset_optimizer.js_enabled', $config['js']['enabled']);
        $builder->setParameter('asset_optimizer.css_enabled', $config['css']['enabled']);
        $builder->setParameter('asset_optimizer.svg_enabled', $config['svg']['enabled']);
        $builder->setParameter('asset_optimizer.jpg_png_enabled', $config['jpg_png']['enabled']);
        $builder->setParameter('asset_optimizer.jpg_png_quality', $config['jpg_png']['quality']);
        $builder->setParameter('asset_optimizer.webp_enabled', $config['webp']['enabled']);
        $builder->setParameter('asset_optimizer.webp_quality', $config['webp']['quality']);
        $builder->setParameter('asset_optimizer.ignore_paths', $config['ignore_paths']);

        $container->import($this->getPath() . '/config/services.php');
    }
}
