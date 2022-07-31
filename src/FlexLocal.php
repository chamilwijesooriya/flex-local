<?php

namespace Chamil\FlexLocal;

use Composer\Composer;
use Composer\DependencyResolver\Operation\OperationInterface;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Symfony\Flex\Lock;
use Symfony\Flex\Options;
use function dirname;
use function is_array;
use const PATHINFO_EXTENSION;

class FlexLocal implements PluginInterface, EventSubscriberInterface
{
    private Composer $composer;
    private IOInterface $io;
    private Options $options;
    private array $operations = [];
    private Configurator $configurator;
    private Lock $lock;

    public static function getSubscribedEvents()
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'install',
            PackageEvents::POST_PACKAGE_INSTALL => 'packageInstall',
            ScriptEvents::POST_UPDATE_CMD => 'update',
        ];
    }

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->options = $this->initOptions();

        $composerFile = Factory::getComposerFile();
        $composerLock = 'json' === pathinfo($composerFile, PATHINFO_EXTENSION) ? substr($composerFile, 0, -4) . 'lock' : $composerFile . '.lock';
        $symfonyLock = str_replace('composer', 'symfony', basename($composerLock));

        $this->configurator = new Configurator($composer, $io, $this->options);
        $this->lock = new Lock(getenv('SYMFONY_LOCKFILE') ?: dirname($composerLock) . '/' . (basename($composerLock) !== $symfonyLock ? $symfonyLock : 'symfony.lock'));
    }

    /**
     * Copied from Flex
     */
    private function initOptions(): Options
    {
        $extra = $this->composer->getPackage()->getExtra();

        $options = array_merge([
            'bin-dir' => 'bin',
            'conf-dir' => 'conf',
            'config-dir' => 'config',
            'src-dir' => 'src',
            'var-dir' => 'var',
            'public-dir' => 'public',
            'root-dir' => $extra['symfony']['root-dir'] ?? '.',
            'runtime' => $extra['runtime'] ?? [],
        ], $extra);

        return new Options($options, $this->io);
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
        // TODO: Implement deactivate() method.
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
        // TODO: Implement uninstall() method.
    }

    public function update(Event $event, $operations = [])
    {
        // call install directly
        // composer require comes here
        // TODO:: may be need other checks ?
        $this->install($event);
    }

    public function install(Event $event)
    {
        // get recipes
        $recipes = $this->fetchRecipes($this->operations);
        $this->operations = [];     // Reset the operations after getting recipes

        if (empty($recipes)) {
            return;
        }

        foreach ($recipes as $recipe) {
            if ($recipe->getJob() === 'install') {
                $this->io->writeError(sprintf('  - Configuring %s', $this->formatOrigin($recipe)));
                $this->configurator->install($recipe, $this->lock);
                $manifest = $recipe->getManifest();
                if (isset($manifest['post-install-output'])) {
                    $this->postInstallOutput[] = sprintf('<bg=yellow;fg=white> %s </> instructions:', $recipe->getName());
                    $this->postInstallOutput[] = '';
                    foreach ($manifest['post-install-output'] as $line) {
                        $this->postInstallOutput[] = $this->options->expandTargetDir($line);
                    }
                    $this->postInstallOutput[] = '';
                }
            }
        }
    }

    /**
     * @return Recipe[]
     */
    public function fetchRecipes(array $operations): array
    {
        $recipes = [];
        $manifests = $this->loadManifests($operations);

        if (empty($manifests)) {
            return $recipes;
        }

        foreach ($operations as $operation) {

            /** @var PackageInterface $package */
            $package = $operation->getPackage();
            $name = $package->getName();

            if (isset($manifests['manifests'][$name])) {
                $job = method_exists($operation, 'getOperationType') ? $operation->getOperationType() : $operation->getJobType();
                $recipes[$name] = new Recipe($package, $name, $job, $manifests['manifests'][$name]);
            }
        }

        return $recipes;
    }

    /**
     * @param OperationInterface[] $operations
     */
    private function loadManifests(array $operations)
    {
        $data = [];
        $repositoryManager = $this->composer->getRepositoryManager();
        $installationManager = $this->composer->getInstallationManager();
        $localRepository = $repositoryManager->getLocalRepository();

        $installedPackages = $localRepository->getPackages();

        foreach ($operations as $operation) {
            /** @var PackageInterface $package */
            $package = $operation->getPackage();
            $name = $package->getName();

            foreach ($installedPackages as $installedPackage) {
                if ($name === $installedPackage->getName()) {
                    $installPath = $installationManager->getInstallPath($package);
                    break;
                }
            }
            // check recipe/manifest.json exists in current package
            if (!isset($installPath) || !file_exists($manifestPath = $installPath . '/recipe/manifest.json')) {
                continue;
            }

            // load manifest file
            $contents = file_get_contents($manifestPath);
            $manifest = JsonFile::parseJson($contents);

            // this copied from Flex
            foreach ($manifest['files'] ?? [] as $i => $file) {
                $manifest['files'][$i]['contents'] = is_array($file['contents']) ? implode("\n", $file['contents']) : base64_decode($file['contents']);
            }

            $version = $package->getPrettyVersion();

            $data['locks'][$name]['version'] = $version;
            $data['locks'][$name]['recipe']['version'] = 'dev'; // TODO:: need something different here

            $data['manifests'][$name] = $manifest + [
                    'package' => $name,
                    'version' => $version,
                    'origin' => sprintf('local recipe for %s:%s', $name, $package->getPrettyVersion()),
                    'package_installation_path' => $installPath,
                ];
        }
        return $data;
    }

    /**
     * Copied from Flex
     */
    private function formatOrigin(Recipe $recipe): string
    {
        if (method_exists($recipe, 'getFormattedOrigin')) {
            return $recipe->getFormattedOrigin();
        }

        // BC with upgrading from flex < 1.18
        $origin = $recipe->getOrigin();

        // symfony/translation:3.3@github.com/symfony/recipes:branch
        if (!preg_match('/^([^:]++):([^@]++)@(.+)$/', $origin, $matches)) {
            return $origin;
        }

        return sprintf('<info>%s</> (<comment>>=%s</>): From %s', $matches[1], $matches[2], 'auto-generated recipe' === $matches[3] ? '<comment>' . $matches[3] . '</>' : $matches[3]);
    }

    public function packageInstall(PackageEvent $event)
    {
        // each individual package install comes here
        // add them to operations list, which will be used by the install script event listener
        $this->operations[] = $event->getOperation();
    }
}