<?php

namespace Chamil\FlexLocal;

class Recipe extends \Symfony\Flex\Recipe
{
    public function getPackageInstallationPath()
    {
        $reflection = new \ReflectionClass($this);
        $parent = $reflection->getParentClass();

        $dataProp = $parent->getProperty('data');
        $dataProp->setAccessible(TRUE);

        $data = $dataProp->getValue($this);

        return $data['package_installation_path'];
    }
}