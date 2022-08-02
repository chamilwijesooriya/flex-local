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
    private array $packages = []; // packages that have flex local
    private Configurator $configurator;
    private Lock $lock;
    private Lock $flexLocalLock;
    private bool $flexInstall = FALSE;

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
        // dunno why
        $flexLocalLock = str_replace('composer', 'flexlocal', basename($composerLock));

        $this->configurator = new Configurator($composer, $io, $this->options);
        $this->lock = new Lock(getenv('SYMFONY_LOCKFILE') ?: dirname($composerLock) . '/' . (basename($composerLock) !== $symfonyLock ? $symfonyLock : 'symfony.lock'));
        // dunno why
        $this->flexLocalLock = new Lock(dirname($composerLock) . '/' . (basename($composerLock) !== $flexLocalLock ? $flexLocalLock : 'flexlocal.lock'));

        // check if temp file has any data
        if (!empty($this->flexLocalLock->all())) {
            $localRepository = $this->composer->getRepositoryManager()->getLocalRepository();
            $packages = $this->flexLocalLock->all();
            foreach ($packages as $name => $data) {
                // need package to pass with Recipe
                $package = $localRepository->findPackage($name, '');
                if (isset($package)) {
                    $this->packages[$name] = $data + ['package' => $package];
                }
            }

            // delete lock file
            // $this->flexLocalLock->delete();
        }
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
        $recipes = $this->fetchRecipes($this->packages);
        $this->packages = [];     // Reset the operations after getting recipes

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


    public function packageInstall(PackageEvent $event)
    {
        // each individual package install comes here

        /** @var InstallOperation $operation */
        $operation = $event->getOperation();
        $package = $operation->getPackage();

        // evaluate the package and add to the list
        $this->evaluatePackage($package, $operation->getOperationType());

        // if flex is being installed, write file
        // always do this here, because update event is being stopped from propagating in flex
        if ($this->flexInstall) {
            $this->flexLocalLock->write();
        }
    }

    /**
     * @return Recipe[]
     */
    private function fetchRecipes(array $packages): array
    {
        $recipes = [];
        $manifests = $this->loadManifests($packages);

        if (empty($manifests)) {
            return $recipes;
        }

        foreach ($packages as $name => $data) {
            if (isset($manifests['manifests'][$name])) {
                // add to recipes
                $recipes[$name] = new Recipe($data['package'], $name, $data['op'], $manifests['manifests'][$name]);
            }
        }

        return $recipes;
    }

    private function loadManifests(array $packages)
    {
        $manifests = [];
        $localRepository = $this->composer->getRepositoryManager()->getLocalRepository();

        foreach ($packages as $name => $data) {
            /** @var PackageInterface $package */
            $package = $data['package'];
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


    private function evaluatePackage(PackageInterface $package, string $op)
    {
        $installationManager = $this->composer->getInstallationManager();

        $extra = $package->getExtra();
        // check if flex is also being installed
        // if it is, it will start a separate installation process, so we need to save flex-local packages to a temp file
        // and use that later is it's there
        if ('symfony/flex' === $package->getName()) {
            $this->flexInstall = TRUE;
            // save any operations in list and clear it
            if (!empty($this->packages)) {
                // write packages to file
                foreach ($this->packages as $packageName => $data) {
                    $this->flexLocalLock->set($packageName, $data);
                }

                // clear package list
                $this->packages = [];
            }
        }

        if (isset($extra['flex-local']) && $extra['flex-local'] === TRUE) {
            if ($this->flexInstall) {
                // flex flag is true, so copy the package name to temp file
                $this->flexLocalLock->set($package->getName(), [
                    'op' => $op,
                    'install_path' => $installationManager->getInstallPath($package),
                    'package' => $package,
                ]);
            } else {
                // set to current list of packages
                $this->packages[$package->getName()] = [
                    'op' => $op,
                    'install_path' => $installationManager->getInstallPath($package),
                    'package' => $package,
                ];
            }
        }
    }

}