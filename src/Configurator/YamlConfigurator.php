<?php

namespace Chamil\FlexLocal\Configurator;

use Symfony\Component\Yaml\Yaml;
use Symfony\Flex\Configurator\AbstractConfigurator;
use Symfony\Flex\Lock;
use Symfony\Flex\Recipe;
use Symfony\Flex\Update\RecipeUpdate;

class YamlConfigurator extends AbstractConfigurator
{
    public function configure(Recipe $recipe, $config, Lock $lock, array $options = [])
    {
        if (!$recipe instanceof \Chamil\FlexLocal\Recipe) {
            return;
        }
        $this->write('Copying yaml configs from recipe');

        $options['package_installation_path'] = $recipe->getPackageInstallationPath();
        $options = array_merge($this->options->toArray(), $options);

        $this->copyFiles($config, $options);
    }

    private function copyFiles(array $manifest, array $options)
    {
        $to = $options['root-dir'] ?? '.';
        $from = $options['package_installation_path'];

        foreach ($manifest as $source => $target) {
            $target = $this->options->expandTargetDir($target);
            if ('yaml' === substr($source, -4) || 'yml' === substr($source, -3)) {
                $this->copyFile($this->path->concatenate([$from, 'recipe/' . $source]), $this->path->concatenate([$to, $target]), $options);
            }
        }
    }

    private function copyFile(string $source, string $target, array $options)
    {
        if (!file_exists($source)) {
            return;
        }
        $sourceContent = Yaml::parseFile($source);

        $toContent = [];
        if (file_exists($target)) {
            $toContent = Yaml::parseFile($target);
        }
        // merge arrays
        $content = array_replace_recursive($toContent, $sourceContent);


        if (!is_dir(\dirname($target))) {
            mkdir(\dirname($target), 0777, TRUE);
        }

        $parsedContent = Yaml::dump($content, 6);

        file_put_contents($target, $parsedContent);

        $this->write(sprintf('  Created <fg=green>"%s"</>', $this->path->relativize($target)));
    }

    public function unconfigure(Recipe $recipe, $config, Lock $lock)
    {
        // TODO: Implement unconfigure() method.
    }

    public function update(RecipeUpdate $recipeUpdate, array $originalConfig, array $newConfig): void
    {
        // TODO: Implement update() method.
    }
}