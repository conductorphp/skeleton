<?php

declare(strict_types=1);

namespace MezzioInstaller;

use Composer\Composer;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\BasePackage;
use Composer\Package\Link;
use Composer\Package\RootPackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Script\Event;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Composer installer script
 *
 * Add this script to composer.json:
 *
 *  "scripts": {
 *      "pre-update-cmd": "ConductorInstaller\\OptionalPackages::install",
 *      "pre-install-cmd": "ConductorInstaller\\OptionalPackages::install"
 *  },
 */
class OptionalPackages
{
    public const VCS_GIT          = 'git';

    public const VCS_SVN          = 'svn';

    public const VCS_NONE         = 'none';

    public const PLATFORM_M2      = 'magento_2';

    public const PLATFORM_M1      = 'magento_1';

    public const PLATFORM_DRUPAL  = 'drupal';

    public const PLATFORM_WP      = 'wordpress';

    public const PLATFORM_CUSTOM  = 'custom';

    /**
     * @const string Configuration file lines related to registering the default
     *     App module configuration.
     */
    public const APP_MODULE_CONFIG = '
    // Default App module config
    App\ConfigProvider::class,
';

    /**
     * Assets to remove during cleanup.
     *
     * @var string[]
     */
    private $assetsToRemove = [
        '.coveralls.yml',
        '.travis.yml',
        'CHANGELOG.md',
        'phpcs.xml',
        'phpstan.installer.neon',
        'src/App/templates/.gitkeep',
    ];

    /**
     * @var Composer
     */
    private $composer;

    /**
     * @var array
     */
    private $composerDefinition;

    /**
     * @var JsonFile
     */
    private $composerJson;

    /**
     * @var Link[]
     */
    private $composerRequires;

    /**
     * @var Link[]
     */
    private $composerDevRequires;

    /**
     * @var string[] Dev dependencies to remove after install is complete
     */
    private $devDependencies = [
        'composer/composer',
        'elie29/zend-phpdi-config',
        'filp/whoops',
        'jsoumelidis/zend-sf-di-config',
        'mikey179/vfsstream',
        'northwoods/container',
        "phpstan/phpstan",
        "phpstan/phpstan-strict-rules",
        'laminas/laminas-auradi-config',
        'laminas/laminas-coding-standard',
        'mezzio/mezzio-aurarouter',
        'mezzio/mezzio-fastroute',
        'mezzio/mezzio-platesrenderer',
        'mezzio/mezzio-twigrenderer',
        'mezzio/mezzio-laminasrouter',
        'mezzio/mezzio-laminasviewrenderer',
        'laminas/laminas-pimple-config',
        'laminas/laminas-servicemanager',
    ];

    /**
     * @var string Path to this file.
     */
    private $installerSource;

    /**
     * @var string Platform type selected.
     */
    private $platformType;

    /**
     * @var string VCS type selected.
     */
    private $vcsType;

    /**
     * @var IOInterface
     */
    private $io;

    /**
     * @var string
     */
    private $projectRoot;

    /**
     * @var RootPackageInterface
     */
    private $rootPackage;

    /**
     * @var int[]
     */
    private $stabilityFlags;

    /**
     * Install command: choose packages and provide configuration.
     *
     * Prompts users for package selections, and copies in package-specific
     * configuration when known.
     *
     * Updates the composer.json with the package selections, and removes the
     * install and update commands on completion.
     *
     * @codeCoverageIgnore
     */
    public static function install(Event $event) : void
    {
        $installer = new self($event->getIO(), $event->getComposer());
        $installer->io->write('<info>Setting up optional packages</info>');
        $installer->setupDataAndCacheDir();
        $installer->removeDevDependencies();
        
        $installer->setPlatformType($installer->requestPlatformType());
        $installer->setVCSType($installer->requestVCSType());        
        $installer->setupPlatform();
        $installer->setupVCS();

        // $installer->updateRootPackage();
        // $installer->removeInstallerFromDefinition();
        // $installer->finalizePackage();
    }

    public function __construct(IOInterface $io, Composer $composer, string $projectRoot = null)
    {
        $this->io = $io;
        $this->composer = $composer;
        // Get composer.json location
        $composerFile = Factory::getComposerFile();
        // Calculate project root from composer.json, if necessary
        $this->projectRoot = $projectRoot ?: realpath(dirname($composerFile));
        $this->projectRoot = rtrim($this->projectRoot, '/\\') . '/';
        // Parse the composer.json
        $this->parseComposerDefinition($composer, $composerFile);
        // Source path for this file
        $this->installerSource = realpath(__DIR__) . '/';
    }

    /**
     * Create data and cache directories, if not present.
     *
     * Also sets up appropriate permissions.
     */
    public function setupDataAndCacheDir() : void
    {
        $this->io->write('<info>Setup data and cache dir</info>');
        if (! is_dir($this->projectRoot . '/data/cache')) {
            mkdir($this->projectRoot . '/data/cache', 0775, true);
            chmod($this->projectRoot . '/data', 0775);
        }
    }

    /**
     * Cleanup development dependencies.
     *
     * The dev dependencies should be removed from the stability flags,
     * require-dev and the composer file.
     */
    public function removeDevDependencies() : void
    {
        $this->io->write('<info>Removing installer development dependencies</info>');
        foreach ($this->devDependencies as $devDependency) {
            unset($this->stabilityFlags[$devDependency]);
            unset($this->composerDevRequires[$devDependency]);
            unset($this->composerDefinition['require-dev'][$devDependency]);
        }
    }

    /**
     * Prompt for the platform type.
     *
     * @return string One of the PLATFORM_ constants.
     */
    public function requestPlatformType() : string
    {
        $query = [
            sprintf(
                "\n  <question>%s</question>\n",
                'Which platform are you using?'
            ),
            "  [<comment>1</comment>] Magento 2\n",
            "  [<comment>2</comment>] Magento 1\n",
            "  [<comment>3</comment>] Drupal 8\n",
            "  [<comment>4</comment>] Wordpress\n",
            "  [<comment>5</comment>] Custom\n",
            '  Make your selection: ',
        ];
        while (true) {
            $answer = $this->io->ask(implode($query), '2');
            switch (true) {
                case ($answer === '1'):
                    return self::PLATFORM_M2;
                case ($answer === '2'):
                    return self::PLATFORM_M1;
                case ($answer === '3'):
                    return self::PLATFORM_DRUPAL;
                case ($answer === '4'):
                    return self::PLATFORM_WP;
                case ($answer === '5'):
                    return self::PLATFORM_CUSTOM;
                default:
                    // @codeCoverageIgnoreStart
                    $this->io->write('<error>Invalid answer</error>');
                    // @codeCoverageIgnoreEnd
            }
        }
    }

    /**
     * Set the platform type.
     */
    public function setPlatformType(string $platformType) : void
    {
        $this->platformType =
            in_array($platformType, [
                self::PLATFORM_M2,
                self::PLATFORM_M1,
                self::PLATFORM_DRUPAL,
                self::PLATFORM_WP,
                self::PLATFORM_CUSTOM,
            ], true)
            ? $platformType
            : self::PLATFORM_CUSTOM;
    }

    /**
     * Prompt for the VCS type.
     *
     * @return string One of the VCS_ constants.
     */
    public function requestVCSType() : string
    {
        $query = [
            sprintf(
                "\n  <question>%s</question>\n",
                'Which version control system are you using?'
            ),
            "  [<comment>1</comment>] Git\n",
            "  [<comment>2</comment>] SVN\n",
            "  [<comment>3</comment>] None\n",
            '  Make your selection: ',
        ];
        while (true) {
            $answer = $this->io->ask(implode($query), '2');
            switch (true) {
                case ($answer === '1'):
                    return self::VCS_GIT;
                case ($answer === '2'):
                    return self::VCS_SVN;
                case ($answer === '3'):
                    return self::VCS_NONE;
                default:
                    // @codeCoverageIgnoreStart
                    $this->io->write('<error>Invalid answer</error>');
                    // @codeCoverageIgnoreEnd
            }
        }
    }

    /**
     * Set the VCS type.
     */
    public function setVCSType(string $vcsType) : void
    {
        $this->vcsType =
            in_array($vcsType, [
                self::VCS_GIT,
                self::VCS_SVN,
                self::VCS_NONE,
            ], true)
            ? $vcsType
            : self::VCS_NONE;
    }

    /**
     * Setup the application platform.
     *
     * @throws RuntimeException if $platformType is unknown
     */
    public function setupPlatform() : void
    {
        switch ($this->platformType) {
            case self::PLATFORM_M2:
                // install M2
                return;
            case self::PLATFORM_M1:
                // install M1
                return;
            case self::PLATFORM_DRUPAL:
                // install drupal
                return;
            case self::PLATFORM_WP:
                // install wordpress
                return;
            case self::PLATFORM_CUSTOM:
                // install Custom
                return;
            default:
                throw new RuntimeException(sprintf(
                    'Invalid platform type "%s"; this indicates a bug in the installer',
                    $this->platformType
                ));
        }
    }

    /**
     * Setup the application VCS.
     *
     * @throws RuntimeException if $vcsType is unknown
     */
    public function setupVCS() : void
    {
        switch ($this->vcsType) {
            case self::VCS_GIT:
                // install Git
                return;
            case self::VCS_SVN:
                // install SVN
                return;
            case self::VCS_NONE;
                // none
                return;
            default:
                throw new RuntimeException(sprintf(
                    'Invalid VCS type "%s"; this indicates a bug in the installer',
                    $this->vcsType
                ));
        }
    }

    /**
     * Update the root package based on current state.
     */
    public function updateRootPackage() : void
    {
        $this->rootPackage->setRequires($this->composerRequires);
        $this->rootPackage->setDevRequires($this->composerDevRequires);
        $this->rootPackage->setStabilityFlags($this->stabilityFlags);
        $this->rootPackage->setAutoload($this->composerDefinition['autoload']);
        $this->rootPackage->setDevAutoload($this->composerDefinition['autoload-dev']);
        $this->rootPackage->setExtra($this->composerDefinition['extra'] ?? []);
    }

    /**
     * Remove the installer from the composer definition
     */
    public function removeInstallerFromDefinition() : void
    {
        $this->io->write('<info>Remove installer</info>');
        // Remove installer script autoloading rules
        unset($this->composerDefinition['autoload']['psr-4']['MezzioInstaller\\']);
        unset($this->composerDefinition['autoload-dev']['psr-4']['MezzioInstallerTest\\']);
        // Remove branch-alias
        unset($this->composerDefinition['extra']['branch-alias']);
        // Remove installer data
        unset($this->composerDefinition['extra']['optional-packages']);
        // Remove installer scripts
        unset($this->composerDefinition['scripts']['pre-update-cmd']);
        unset($this->composerDefinition['scripts']['pre-install-cmd']);
        // Remove phpstan completely
        $this->composerDefinition['scripts']['check'] = array_diff(
            $this->composerDefinition['scripts']['check'],
            ['@analyze']
        );
        unset($this->composerDefinition['scripts']['analyze']);
    }

    /**
     * Finalize the package.
     *
     * Writes the current JSON state to composer.json, clears the
     * composer.lock file, and cleans up all files specific to the
     * installer.
     *
     * @codeCoverageIgnore
     */

    public function finalizePackage() : void
    {
        // Update composer definition
        $this->composerJson->write($this->composerDefinition);
        $this->clearComposerLockFile();
        $this->cleanUp();
    }

    /**
     * Remove lines from string content containing words in array.
     */
    public function removeLinesContainingStrings(array $entries, string $content) : string
    {
        $entries = implode('|', array_map(function ($word) {
            return preg_quote($word, '/');
        }, $entries));
        return preg_replace('/^.*(?:' . $entries . ").*$(?:\r?\n)?/m", '', $content);
    }

    /**
     * Clean up/remove installer classes and assets.
     *
     * On completion of install/update, removes the installer classes (including
     * this one) and assets (including configuration and templates).
     *
     * @codeCoverageIgnore
     */
    private function cleanUp() : void
    {
        $this->io->write('<info>Removing Expressive installer classes, configuration, tests and docs</info>');
        foreach ($this->assetsToRemove as $target) {
            $target = $this->projectRoot . $target;
            if (file_exists($target)) {
                unlink($target);
            }
        }
        $this->recursiveRmdir($this->installerSource);
        $this->recursiveRmdir($this->projectRoot . 'test/MezzioInstallerTest');
        $this->recursiveRmdir($this->projectRoot . 'docs');
        $this->preparePhpunitConfig();
    }

    /**
     * Remove the MezzioInstaller exclusion from the phpunit configuration
     *
     * @codeCoverageIgnore
     */
    private function preparePhpunitConfig() : void
    {
        $phpunitConfigFile = $this->projectRoot . 'phpunit.xml.dist';
        $phpunitConfig     = file_get_contents($phpunitConfigFile);
        $phpunitConfig     = $this->removeLinesContainingStrings(['exclude', 'MezzioInstaller'], $phpunitConfig);
        file_put_contents($phpunitConfigFile, $phpunitConfig);
    }

    /**
     * Recursively remove a directory.
     *
     * @codeCoverageIgnore
     */
    private function recursiveRmdir(string $directory) : void
    {
        if (! is_dir($directory)) {
            return;
        }
        $rdi = new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS);
        $rii = new RecursiveIteratorIterator($rdi, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($rii as $filename => $fileInfo) {
            if ($fileInfo->isDir()) {
                rmdir($filename);
                continue;
            }
            unlink($filename);
        }
        rmdir($directory);
    }

    /**
     * Removes composer.lock file from gitignore.
     *
     * @codeCoverageIgnore
     */
    private function clearComposerLockFile() : void
    {
        $this->io->write('<info>Removing composer.lock from .gitignore</info>');
        $ignoreFile = sprintf('%s/.gitignore', $this->projectRoot);
        $content = $this->removeLinesContainingStrings(['composer.lock'], file_get_contents($ignoreFile));
        file_put_contents($ignoreFile, $content);
    }

    /**
     * Removes the App\ConfigProvider entry from the application config file.
     */
    private function removeAppModuleConfig() : void
    {
        $configFile = $this->projectRoot . '/config/config.php';
        $contents = file_get_contents($configFile);
        $contents = str_replace(self::APP_MODULE_CONFIG, '', $contents);
        file_put_contents($configFile, $contents);
    }

    /**
     * Parses the composer file and populates internal data
     */
    private function parseComposerDefinition(Composer $composer, string $composerFile) : void
    {
        $this->composerJson = new JsonFile($composerFile);
        $this->composerDefinition = $this->composerJson->read();
        // Get root package or root alias package
        $this->rootPackage = $composer->getPackage();
        // Get required packages
        $this->composerRequires    = $this->rootPackage->getRequires();
        $this->composerDevRequires = $this->rootPackage->getDevRequires();
        // Get stability flags
        $this->stabilityFlags = $this->rootPackage->getStabilityFlags();
    }
}
