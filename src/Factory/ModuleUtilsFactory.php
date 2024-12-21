<?php

namespace Laminas\ApiTools\Configuration\Factory;

use Laminas\ApiTools\Configuration\ModuleUtils;
use Psr\Container\ContainerInterface;

class ModuleUtilsFactory
{
    /**
     * @return ModuleUtils
     */
    public function __invoke(ContainerInterface $container)
    {
        return new ModuleUtils($container->get('ModuleManager'));
    }
}
