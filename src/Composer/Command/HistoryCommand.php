<?php
/**
 * Created by PhpStorm.
 * User: shaduli
 * Date: 02/06/15
 * Time: 8:49 AM
 */

namespace Composer\Command;


use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\DefaultPolicy;
use Composer\Factory;
use Composer\Package\CompletePackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Guzzle\Common\Exception\ExceptionCollection;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Repository\ArrayRepository;
use Composer\Repository\CompositeRepository;
use Composer\Repository\ComposerRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Util\SpdxLicense;


class HistoryCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('history')
            ->setDescription('Show history about package/s')
            ->setDefinition(array(
                new InputArgument('package', InputArgument::OPTIONAL, 'Package to inspect'),
                new InputOption('installed', 'i', InputOption::VALUE_NONE, 'List installed packages only'),
                new InputOption('platform', 'p', InputOption::VALUE_NONE, 'List platform packages only'),
                new InputOption('available', 'a', InputOption::VALUE_NONE, 'List available packages only'),
                new InputOption('json', 'j', InputOption::VALUE_NONE, 'Output as json'),
            ))
            ->setHelp(<<<EOT
The history command displays detailed information about a package, or
lists all packages available.

EOT
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->versionParser = new VersionParser;

        // init repos
        $platformRepo = new PlatformRepository;

        $composer = $this->getComposer(false);
        if ($input->getOption('platform')) {
            $repos = $installedRepo = $platformRepo;
        } elseif ($input->getOption('installed')) {
            $repos = $installedRepo = $this->getComposer()->getRepositoryManager()->getLocalRepository();
        } elseif ($input->getOption('available')) {
            $installedRepo = $platformRepo;
            if ($composer) {
                $repos = new CompositeRepository($composer->getRepositoryManager()->getRepositories());
            } else {
                $defaultRepos = Factory::createDefaultRepositories($this->getIO());
                $repos = new CompositeRepository($defaultRepos);
                $this->getIO()->writeError('No composer.json found in the current directory, showing available packages from ' . implode(', ', array_keys($defaultRepos)));
            }
        } elseif ($composer) {
            $localRepo = $composer->getRepositoryManager()->getLocalRepository();
            $installedRepo = new CompositeRepository(array($localRepo, $platformRepo));
            $repos = new CompositeRepository(array_merge(array($installedRepo), $composer->getRepositoryManager()->getRepositories()));
        } else {
            $defaultRepos = Factory::createDefaultRepositories($this->getIO());
            $this->getIO()->writeError('No composer.json found in the current directory, showing available packages from ' . implode(', ', array_keys($defaultRepos)));
            $installedRepo = $platformRepo;
            $repos = new CompositeRepository(array_merge(array($installedRepo), $defaultRepos));
        }

        if ($composer) {
            $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'show', $input, $output);
            $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);
        }

        // show single package or single version
        if ($input->getArgument('package') || !empty($package)) {
            $versions = array();
            if (empty($package)) {
                list($package, $versions) = $this->getPackage($installedRepo, $repos, $input->getArgument('package'));

                if (!$package) {
                    throw new \InvalidArgumentException('Package '.$input->getArgument('package').' not found');
                }
            } else {
                $versions = array($package->getPrettyVersion() => $package->getVersion());
            }

            $this->printMeta($package, $versions, $installedRepo);
            $this->printLinks($package, 'requires');
            $this->printLinks($package, 'devRequires', 'requires (dev)');
            if ($package->getSuggests()) {
                $this->getIO()->write("\n<info>suggests</info>");
                foreach ($package->getSuggests() as $suggested => $reason) {
                    $this->getIO()->write($suggested . ' <comment>' . $reason . '</comment>');
                }
            }
            $this->printLinks($package, 'provides');
            $this->printLinks($package, 'conflicts');
            $this->printLinks($package, 'replaces');

            return;
        }

        // list packages
        $packages = array();

        if ($repos instanceof CompositeRepository) {
            $repos = $repos->getRepositories();
        } elseif (!is_array($repos)) {
            $repos = array($repos);
        }

        foreach ($repos as $repo) {
            if ($repo === $platformRepo) {
                $type = '<info>platform</info>:';
            } elseif (
                $repo === $installedRepo
                || ($installedRepo instanceof CompositeRepository && in_array($repo, $installedRepo->getRepositories(), true))
            ) {
                $type = '<info>installed</info>:';
            } else {
                $type = '<comment>available</comment>:';
            }
            if ($repo instanceof ComposerRepository && $repo->hasProviders()) {
                foreach ($repo->getProviderNames() as $name) {
                    $packages[$type][$name] = $name;
                }
            } else {
                foreach ($repo->getPackages() as $package) {
                    if (!isset($packages[$type][$package->getName()])
                        || !is_object($packages[$type][$package->getName()])
                        || version_compare($packages[$type][$package->getName()]->getVersion(), $package->getVersion(), '<')
                    ) {
                        $packages[$type][$package->getName()] = $package;
                    }
                }
            }
        }

        $tree = !$input->getOption('platform') && !$input->getOption('installed') && !$input->getOption('available');
        $indent = $tree ? '  ' : '';
        foreach (array('<info>platform</info>:' => true, '<comment>available</comment>:' => false, '<info>installed</info>:' => true) as $type => $showVersion) {
            if (isset($packages[$type])) {
                if ($tree) {
                    $this->getIO()->write($type);
                }
                ksort($packages[$type]);

                $nameLength = $versionLength = 0;
                foreach ($packages[$type] as $package) {
                    if (is_object($package)) {
                        $nameLength = max($nameLength, strlen($package->getPrettyName()));
                        $versionLength = max($versionLength, strlen($this->versionParser->formatVersion($package)));
                    } else {
                        $nameLength = max($nameLength, $package);
                    }
                }
                list($width) = $this->getApplication()->getTerminalDimensions();
                if (null === $width) {
                    // In case the width is not detected, we're probably running the command
                    // outside of a real terminal, use space without a limit
                    $width = PHP_INT_MAX;
                }
                if (defined('PHP_WINDOWS_VERSION_BUILD')) {
                    $width--;
                }

                $writeVersion = $showVersion && ($nameLength + $versionLength + 3 <= $width);
                $writeDescription = ($nameLength + ($showVersion ? $versionLength : 0) + 24 <= $width);
                if($input->getOption('json')) {
                    $packageVersions = array();

                    foreach ($packages[$type] as $package) {
                        if (is_object($package)) {


                            $packageVersion = array();
                            $packageVersion['bundle'] = $package->getPrettyName();
                            $releaseDate = !is_null($package->getReleaseDate()) ? $package->getReleaseDate()->format('d/m/y') : ' ';
                            $packageVersion['installed']['name'] = $this->versionParser->formatVersion($package);
                            $packageVersion['installed']['description'] = $this->getReleaseDescription($package);
                            $packageVersion['installed']['releaseDate'] = $releaseDate;

                            $latestRelease = $this->getLatestRelease($package);


                            if (!is_null($latestRelease)) {
                                $releaseDate = new \DateTime($latestRelease['published_at']);
                                $packageVersion['latest']['name'] = $latestRelease['tag_name'];
                                $packageVersion['latest']['description'] = $latestRelease['body'];
                                $packageVersion['latest']['releaseDate'] = $releaseDate->format('d/m/y');
                            } else {
                                $packageVersion['latest'] = array();
                            }

                            $packageVersion['included'] = $this->getIncluded($package, $this->versionParser->formatVersion($package), $latestRelease['tag_name']);

                            array_push($packageVersions, $packageVersion);



                        }
                    }

                    $packageVersions = json_encode($packageVersions);
                    $output->write($packageVersions);
                } else {

                    foreach ($packages[$type] as $package) {
                        if (is_object($package)) {
                            $output->write("Bundle: " . $package->getPrettyName() . "\n");
                            $releaseDate = !is_null($package->getReleaseDate()) ? $package->getReleaseDate()->format('d/m/y') : ' ';
                            $output->write(
                                "Installed version: " . $this->versionParser->formatVersion($package) .
                                " - " . $this->getReleaseDescription($package) .
                                " (" . $releaseDate . ")\n"
                            );

                            $latestRelease = $this->getLatestRelease($package);
                            if (!is_null($latestRelease)) {
                                $releaseDate = new \DateTime($latestRelease['published_at']);
                                $output->write("Latest version: " . $latestRelease['tag_name'] .
                                    " - " . $latestRelease['body'] .
                                    " (" . $releaseDate->format('d/m/y') . ")\n"
                                );
                            } else {
                                $output->write("Latest version: No releases available\n"
                                );
                            }


                            $included = $this->getIncluded($package, $this->versionParser->formatVersion($package), $latestRelease['tag_name']);

                            $output->write("Included:)\n");

                            foreach ($included as $release) {
                                $output->write($release['name'] .
                                    " - " . $release['description'] .
                                    " (" . $release['releaseDate'] . ")\n"
                                );
                            }

                        }

                    }
                }
            }
        }
    }

    /**
     * finds a package by name and version if provided
     *
     * @param  RepositoryInterface       $installedRepo
     * @param  RepositoryInterface       $repos
     * @param  string                    $name
     * @param  string                    $version
     * @return array                     array(CompletePackageInterface, array of versions)
     * @throws \InvalidArgumentException
     */
    protected function getPackage(RepositoryInterface $installedRepo, RepositoryInterface $repos, $name, $version = null)
    {
        $name = strtolower($name);
        $constraint = null;
        if ($version) {
            $constraint = $this->versionParser->parseConstraints($version);
        }

        $policy = new DefaultPolicy();
        $pool = new Pool('dev');
        $pool->addRepository($repos);

        $matchedPackage = null;
        $versions = array();
        $matches = $pool->whatProvides($name, $constraint);
        foreach ($matches as $index => $package) {
            // skip providers/replacers
            if ($package->getName() !== $name) {
                unset($matches[$index]);
                continue;
            }

            // select an exact match if it is in the installed repo and no specific version was required
            if (null === $version && $installedRepo->hasPackage($package)) {
                $matchedPackage = $package;
            }

            $versions[$package->getPrettyVersion()] = $package->getVersion();
            $matches[$index] = $package->getId();
        }

        // select preferred package according to policy rules
        if (!$matchedPackage && $matches && $preferred = $policy->selectPreferredPackages($pool, array(), $matches)) {
            $matchedPackage = $pool->literalToPackage($preferred[0]);
        }

        return array($matchedPackage, $versions);
    }

    /**
     * prints package meta data
     */
    protected function printMeta(CompletePackageInterface $package, array $versions, RepositoryInterface $installedRepo)
    {
        $this->getIO()->write('<info>name</info>     : ' . $package->getPrettyName());
        $this->getIO()->write('<info>descrip.</info> : ' . $package->getDescription());
        $this->getIO()->write('<info>keywords</info> : ' . join(', ', $package->getKeywords() ?: array()));
        $this->printVersions($package, $versions, $installedRepo);
        $this->getIO()->write('<info>type</info>     : ' . $package->getType());
        $this->printLicenses($package);
        $this->getIO()->write('<info>source</info>   : ' . sprintf('[%s] <comment>%s</comment> %s', $package->getSourceType(), $package->getSourceUrl(), $package->getSourceReference()));
        $this->getIO()->write('<info>dist</info>     : ' . sprintf('[%s] <comment>%s</comment> %s', $package->getDistType(), $package->getDistUrl(), $package->getDistReference()));
        $this->getIO()->write('<info>names</info>    : ' . implode(', ', $package->getNames()));

        if ($package->isAbandoned()) {
            $replacement = ($package->getReplacementPackage() !== null)
                ? ' The author suggests using the ' . $package->getReplacementPackage(). ' package instead.'
                : null;

            $this->getIO()->writeError(
                sprintf('<warning>Attention: This package is abandoned and no longer maintained.%s</warning>', $replacement)
            );
        }

        if ($package->getSupport()) {
            $this->getIO()->write("\n<info>support</info>");
            foreach ($package->getSupport() as $type => $value) {
                $this->getIO()->write('<comment>' . $type . '</comment> : '.$value);
            }
        }

        if ($package->getAutoload()) {
            $this->getIO()->write("\n<info>autoload</info>");
            foreach ($package->getAutoload() as $type => $autoloads) {
                $this->getIO()->write('<comment>' . $type . '</comment>');

                if ($type === 'psr-0') {
                    foreach ($autoloads as $name => $path) {
                        $this->getIO()->write(($name ?: '*') . ' => ' . (is_array($path) ? implode(', ', $path) : ($path ?: '.')));
                    }
                } elseif ($type === 'psr-4') {
                    foreach ($autoloads as $name => $path) {
                        $this->getIO()->write(($name ?: '*') . ' => ' . (is_array($path) ? implode(', ', $path) : ($path ?: '.')));
                    }
                } elseif ($type === 'classmap') {
                    $this->getIO()->write(implode(', ', $autoloads));
                }
            }
            if ($package->getIncludePaths()) {
                $this->getIO()->write('<comment>include-path</comment>');
                $this->getIO()->write(implode(', ', $package->getIncludePaths()));
            }
        }
    }

    /**
     * prints all available versions of this package and highlights the installed one if any
     */
    protected function printVersions(CompletePackageInterface $package, array $versions, RepositoryInterface $installedRepo)
    {
        uasort($versions, 'version_compare');
        $versions = array_keys(array_reverse($versions));

        // highlight installed version
        if ($installedRepo->hasPackage($package)) {
            $installedVersion = $package->getPrettyVersion();
            $key = array_search($installedVersion, $versions);
            if (false !== $key) {
                $versions[$key] = '<info>* ' . $installedVersion . '</info>';
            }
        }

        $versions = implode(', ', $versions);

        $this->getIO()->write('<info>versions</info> : ' . $versions);
    }

    /**
     * print link objects
     *
     * @param CompletePackageInterface $package
     * @param string                   $linkType
     * @param string                   $title
     */
    protected function printLinks(CompletePackageInterface $package, $linkType, $title = null)
    {
        $title = $title ?: $linkType;
        if ($links = $package->{'get'.ucfirst($linkType)}()) {
            $this->getIO()->write("\n<info>" . $title . "</info>");

            foreach ($links as $link) {
                $this->getIO()->write($link->getTarget() . ' <comment>' . $link->getPrettyConstraint() . '</comment>');
            }
        }
    }

    /**
     * Prints the licenses of a package with metadata
     *
     * @param CompletePackageInterface $package
     */
    protected function printLicenses(CompletePackageInterface $package)
    {
        $spdxLicense = new SpdxLicense;

        $licenses = $package->getLicense();

        foreach ($licenses as $licenseId) {
            $license = $spdxLicense->getLicenseByIdentifier($licenseId); // keys: 0 fullname, 1 osi, 2 url

            if (!$license) {
                $out = $licenseId;
            } else {
                // is license OSI approved?
                if ($license[1] === true) {
                    $out = sprintf('%s (%s) (OSI approved) %s', $license[0], $licenseId, $license[2]);
                } else {
                    $out = sprintf('%s (%s) %s', $license[0], $licenseId, $license[2]);
                }
            }

            $this->getIO()->write('<info>license</info>  : ' . $out);
        }
    }


    public function getReleaseDescription($package){
        $version = $this->versionParser->formatVersion($package);

        list($author, $repo) = $this->getRepoMeta($package);

        if(strpos($version,'dev-master') !== false){
            $commitId = str_replace('dev-master ', '', $version);
            $commit = $this->getCommitInfo($author, $repo, $commitId);
            if($commit != null){
                $description = $commit['commit']['message'];
            }
            else {
                $description = 'Description not accessible';
            }

        }
        else{

            $release = $this->getCurrentRelease($author, $repo, $version);
            $description = $release['body'];
        }

        return $description;
    }

    private function getRepoMeta($package)
    {
        $sourceUrl = $package->getSourceUrl();

        if (strpos($sourceUrl,'https://github.com/') !== false){
            $sourceUrl = str_replace('https://github.com/', '', $sourceUrl);
        }
        else {
            $sourceUrl = str_replace('git@github.com:', '', $sourceUrl);
        }
        $repoMeta = explode('/', $sourceUrl);
        $author = $repoMeta[0];
        $repo = str_replace('.git', '', $repoMeta[1]);

        return array($author, $repo);

    }


    public function getRelease($author, $repo, $version)
    {
        $client = new \Github\Client();
        $release = $client->api('repo')->releases()->all($author, $repo, $version);

    }


    public function getCurrentRelease($author, $repo, $version)
    {
        $client = new \Github\Client();
        $auth = $client->authenticate('alicomo', 'illuali365', \Github\Client::AUTH_HTTP_PASSWORD);
        $releases = $client->api('repo')->releases()->all($author, $repo);
        $currentRelease = null;

        foreach($releases as $release)
        {
            if($release['name'] == $version)
            {
                $currentRelease = $release;
            }
        }

        return $currentRelease;
    }

    public function getCommitInfo($author, $repo, $commitId)
    {
        $client = new \Github\Client();
        $auth = $client->authenticate('alicomo', 'illuali365', \Github\Client::AUTH_HTTP_PASSWORD);

        try{
            $commit = $client->api('repo')->commits()->show($author,  $repo, $commitId);
        }
        catch(\Github\Exception\RuntimeException $e) {
            return null;
        }

        return $commit;

    }


    public function getLatestRelease($package)
    {
        list($author, $repo) = $this->getRepoMeta($package);
        $client = new \Github\Client();
        $auth = $client->authenticate('alicomo', 'illuali365', \Github\Client::AUTH_HTTP_PASSWORD);
        try{
            $release = $client->api('repo')->releases()->latest($author, $repo);
        }
        catch(\Github\Exception\RuntimeException $e) {
            return null;
        }

        return $release;
    }

    public function getIncluded($package, $current, $latest)
    {
        $included = array();
        list($author, $repo) = $this->getRepoMeta($package);
        $client = new \Github\Client();
        $auth = $client->authenticate('alicomo', 'illuali365', \Github\Client::AUTH_HTTP_PASSWORD);
        try{
            $releases = $client->api('repo')->releases()->all($author, $repo);
        }
        catch(\Github\Exception\RuntimeException $e) {
            return $included;
        }
        foreach($releases as $release)
        {

            if($release['name'] == $latest) {
                continue;
            }
            if($release['name'] == $current)
            {
                break;
            }
            $formattedMeta = array();
            $formattedMeta['name'] = $release['tag_name'];
            $releaseDate = new \DateTime($release['published_at']);
            $formattedMeta['description'] = $release['body'];
            $formattedMeta['releaseDate'] = $releaseDate->format('d/m/y');

            array_push($included, $formattedMeta);

        }

        return $included;
    }


}