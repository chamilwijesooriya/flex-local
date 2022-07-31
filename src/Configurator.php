<?php

namespace Chamil\FlexLocal;

use Chamil\FlexLocal\Configurator\YamlConfigurator;
use Composer\Composer;
use Composer\IO\IOInterface;
use Symfony\Flex\Options;

class Configurator extends \Symfony\Flex\Configurator
{
    public function __construct(Composer $composer, IOInterface $io, Options $options)
    {
        parent::__construct($composer, $io, $options);

        $reflection = new \ReflectionClass($this);

        $parent = $reflection->getParentClass();

        $configuratorsProp = $parent->getProperty('configurators');
        $configuratorsProp->setAccessible(TRUE);

        $configurators = $configuratorsProp->getValue($this);
        // add yaml configurator
        $configurators['yaml'] = YamlConfigurator::class;

        $configuratorsProp->setValue($this, $configurators);
    }
}