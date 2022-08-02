<?php

namespace Chamil\FlexLocal;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\Package;
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
    private Configurator $configurator;
    private Lock $lock;
    private Lock $flexLocalLock;

    public static function getSubscribedEvents()
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => ['install', -1],
            PackageEvents::POST_PACKAGE_INSTALL => 'packageInstall',
            ScriptEvents::POST_UPDATE_CMD => ['update', -1],
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
        // dunno why
        $flexLocalLock = str_replace('composer', 'flexlocal', basename($composerLock));

        $this->configurator = new Configurator($composer, $io, $this->options);
        $this->lock = new Lock(getenv('SYMFONY_LOCKFILE') ?: dirname($composerLock) . '/' . (basename($composerLock) !== $symfonyLock ? $symfonyLock : 'symfony.lock'));
        // dunno why
        $this->flexLocalLock = new Lock(dirname($composerLock) . '/' . (basename($composerLock) !== $flexLocalLock ? $flexLocalLock : 'flexlocal.lock'));
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
        $this->flexLocalLock->delete();
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
        $recipes = $this->fetchRecipes();
        // delete temp file

        if (empty($recipes)) {
            $this->flexLocalLock->delete();
            return;
        }

        $this->io->writeError(sprintf('<info>Flex Local operations: %d recipe%s</>', \count($recipes), \count($recipes) > 1 ? 's' : ''));

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

        $this->flexLocalLock->delete();
        // run scripts
        $this->composer->getEventDispatcher()->dispatchScript('auto-scripts');
    }

    /**
     * @return Recipe[]
     */
    private function fetchRecipes(): array
    {
        $recipes = [];
        $packages = $this->flexLocalLock->all();
        $manifests = $this->loadManifests($packages);

        if (empty($manifests)) {
            return $recipes;
        }

        foreach ($packages as $name => $data) {
            if (isset($manifests['manifests'][$name])) {
                $package = new Package($name, $data['version'], $data['pretty_version']);

                // add to recipes
                $recipes[$name] = new Recipe($package, $name, $data['op'], $manifests['manifests'][$name]);
            }
        }

        return $recipes;
    }

    private function loadManifests(array $packages)
    {
        $manifests = [];
        $localRepository = $this->composer->getRepositoryManager()->getLocalRepository();

        foreach ($packages as $name => $data) {
            $package = new Package($name, $data['version'], $data['pretty_version']);
            $installPath = $data['install_path'];

            // make sure package is still installed
            if (!$localRepository->hasPackage($package)) {
                continue;
            }

            // check recipe/manifest.json exists in current package
            if (!file_exists($manifestPath = $installPath . '/recipe/manifest.json')) {
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

            $manifests['locks'][$name]['version'] = $version;
            $manifests['locks'][$name]['recipe']['version'] = 'dev'; // TODO:: need something different here

            $manifests['manifests'][$name] = $manifest + [
                    'package' => $name,
                    'version' => $version,
                    'origin' => sprintf('local recipe for %s:%s', $name, $package->getPrettyVersion()),
                    'package_installation_path' => $installPath,
                ];
        }
        return $manifests;
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

        /** @var InstallOperation $operation */
        $operation = $event->getOperation();
        $package = $operation->getPackage();

        $installationManager = $this->composer->getInstallationManager();

        $extra = $package->getExtra();

        if (isset($extra['flex-local']) && $extra['flex-local'] === TRUE) {
            // copy the package to temp file
            $this->flexLocalLock->set($package->getName(), [
                'op' => $operation->getOperationType(),
                'install_path' => $installationManager->getInstallPath($package),
                'version' => $package->getVersion(),
                'pretty_version' => $package->getPrettyVersion(),
            ]);
        }

        // always write file here, because update event is being stopped from propagating in flex
        $this->flexLocalLock->write();
    }

}