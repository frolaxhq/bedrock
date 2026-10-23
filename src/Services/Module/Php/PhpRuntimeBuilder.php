<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Module\Php;

use Frolax\Bedrock\DataObjects\Module\Manifest;
use Frolax\Bedrock\Exceptions\Module\InvalidModuleSourceException;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class PhpRuntimeBuilder
{
    public function __construct(protected DependencyManifest $dependencies, protected Filesystem $files) {}

    /** @param array{composer: array<string, mixed>, lock: array<string, mixed>, packages: list<array<string, mixed>>, fingerprint: string} $data */
    public function build(Manifest $manifest, string $path, array $data, bool $offline): string
    {
        $source = $this->inventory($path, true);
        $dist = $path.'/dist';

        if (is_link($dist) || (file_exists($dist) && ! is_dir($dist))) {
            throw new InvalidModuleSourceException('The module PHP runtime destination is unsafe.');
        }

        $this->files->ensureDirectoryExists($dist);
        $stage = $dist.'/.php-stage-'.bin2hex(random_bytes(12));
        $this->files->ensureDirectoryExists($stage.'/module');

        try {
            $bytes = array_sum(array_map(static fn (string $file): int => (int) filesize($path.'/'.$file), array_keys($source)));

            if (($free = disk_free_space($dist)) !== false && $free < $bytes * 4 + 16777216) {
                throw new InvalidModuleSourceException('Insufficient disk space to prepare the module PHP runtime.');
            }

            foreach (array_keys($source) as $file) {
                $this->files->ensureDirectoryExists(dirname($stage.'/module/'.$file));

                if (! copy($path.'/'.$file, $stage.'/module/'.$file)) {
                    throw new InvalidModuleSourceException('Could not stage the module PHP source.');
                }
            }

            // Keep empty PSR-4 roots valid, too.
            $autoload = $this->autoload($manifest, $data['composer']);

            foreach (($autoload['psr-4'] ?? []) as $paths) {
                foreach ((array) $paths as $relative) {
                    $this->safeRelative($relative);
                    $this->files->ensureDirectoryExists($stage.'/module/'.$relative);
                }
            }

            if ($data['packages'] === []) {
                $maps = $this->legacyMaps(new Manifest(autoload: $autoload), $stage.'/module');
                $maps = $this->relativeMaps($maps, $stage);
            } else {
                $maps = $this->scope($manifest, $path, $stage, $data, $offline);
            }

            $this->writeJson($stage.'/autoload.json', $maps);
            $this->writeJson($stage.'/runtime.json', [
                'schema' => 1,
                'module' => $manifest->key,
                'fingerprint' => $data['fingerprint'],
                'dependencies' => $this->packageIdentity($data),
                'toolchain' => $this->toolchainIdentity($data),
                'source' => $source,
                'files' => $this->inventory($stage),
            ]);

            return $stage;
        } catch (Throwable $exception) {
            $this->discard($stage);

            throw $exception;
        }
    }

    public function publish(string $path, string $stage): void
    {
        if (file_exists($path.'/dist/php') || is_link($path.'/dist/php') || ! rename($stage, $path.'/dist/php')) {
            throw new InvalidModuleSourceException('Could not atomically publish the module PHP runtime.');
        }
    }

    public function discard(string $stage): void
    {
        if (preg_match('~/dist/\.php-stage-[a-f0-9]{24}$~D', str_replace('\\', '/', $stage)) && is_dir($stage) && ! is_link($stage)) {
            $this->files->deleteDirectory($stage);
        }
    }

    /** @return array<string, string> Relative paths and SHA256 content hashes. */
    public function inventory(string $root, bool $source = false): array
    {
        $root = realpath($root) ?: throw new InvalidModuleSourceException('The module PHP source directory is unavailable.');
        $hashes = [];
        $bytes = 0;
        $entries = 0;
        $patterns = [];

        if ($source && is_file($root.'/.bedrockignore')) {
            if (is_link($root.'/.bedrockignore') || filesize($root.'/.bedrockignore') > 1048576) {
                throw new InvalidModuleSourceException('The module PHP ignore file is unsafe or oversized.');
            }

            foreach (preg_split('/\R/', $this->files->get($root.'/.bedrockignore')) ?: [] as $pattern) {
                $pattern = trim($pattern);

                if ($pattern !== '' && ! str_starts_with($pattern, '#')) {
                    $pattern = preg_quote(ltrim(str_replace('\\', '/', $pattern), '/'), '#');
                    $patterns[] = '#^'.str_replace(['\*\*', '\*'], ['.*', '[^/]*'], $pattern).'(?:$|/)#';
                }
            }
        }

        $walk = function (string $relative, int $depth) use (&$walk, &$hashes, &$bytes, &$entries, $root, $source, $patterns): void {
            if ($depth > 64) {
                throw new InvalidModuleSourceException('The module PHP tree exceeds the depth limit.');
            }

            foreach (scandir($root.($relative === '' ? '' : '/'.$relative)) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                if ($source && (in_array($name, ['.git', '.hg', '.svn', 'node_modules', '.idea', '.github'], true)
                    || $name === '.DS_Store' || str_ends_with($name, '.log')
                    || ($relative === '' && (in_array($name, ['vendor', 'dist', 'tests', 'Tests', 'auth.json'], true) || str_starts_with($name, '.env'))))) {
                    continue;
                }

                $file = ltrim($relative.'/'.$name, '/');
                $absolute = $root.'/'.$file;

                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $file) === 1) {
                        continue 2;
                    }
                }

                if (++$entries > min((int) config('bedrock.modules.php.max_files', 10000), (int) config('bedrock.modules.install.max_entries', 10000))
                    || strlen($file) > (int) config('bedrock.modules.install.max_path_length', 240) || is_link($absolute)) {
                    throw new InvalidModuleSourceException('The module PHP inventory contains a link or exceeds its limits.');
                }

                if (is_dir($absolute)) {
                    $walk($file, $depth + 1);
                } elseif (is_file($absolute)) {
                    $size = filesize($absolute);
                    $bytes += $size;

                    if ($size > (int) config('bedrock.modules.php.max_file_bytes', 67108864) || $bytes > min((int) config('bedrock.modules.php.max_bytes', 268435456), (int) config('bedrock.modules.install.max_archive_bytes', 268435456))) {
                        throw new InvalidModuleSourceException('The module PHP inventory exceeds its byte limits.');
                    }

                    $hashes[$file] = hash_file('sha256', $absolute) ?: throw new InvalidModuleSourceException('Could not hash the module PHP inventory.');
                } else {
                    throw new InvalidModuleSourceException('The module PHP inventory contains a nonregular file.');
                }
            }
        };
        $walk('', 0);
        ksort($hashes);

        return $hashes;
    }

    /** @return array{psr4: array<string, list<string>>, classmap: array<string, string>, files: list<string>} */
    public function legacyMaps(Manifest $manifest, string $path): array
    {
        $maps = ['psr4' => [], 'classmap' => [], 'files' => []];

        foreach (($manifest->autoload['psr-4'] ?? []) as $prefix => $paths) {
            foreach ((array) $paths as $relative) {
                $maps['psr4'][$prefix][] = $this->autoloadPath($path, $relative, true);
            }
        }

        foreach (($manifest->autoload['files'] ?? []) as $relative) {
            $maps['files'][] = $this->autoloadPath($path, $relative, false);
        }

        foreach (($manifest->autoload['classmap'] ?? []) as $relative) {
            $absolute = $this->autoloadPath($path, $relative, is_dir($path.'/'.$relative));
            $files = is_dir($absolute) ? array_keys($this->inventory($absolute)) : [basename($absolute)];
            $base = is_dir($absolute) ? $absolute : dirname($absolute);

            foreach ($files as $file) {
                if (str_ends_with($file, '.php')) {
                    foreach ($this->classes((string) file_get_contents($base.'/'.$file)) as $class) {
                        $maps['classmap'][$class] = $base.'/'.$file;
                    }
                }
            }
        }

        return $maps;
    }

    /** @return list<string> */
    protected function classes(string $php): array
    {
        $namespace = '';
        $classes = [];
        $tokens = token_get_all($php);
        $previous = null;

        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];

            if (! is_array($token) || in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = '';

                for ($next = $index + 1; $next < $count; $next++) {
                    if (is_array($tokens[$next]) && in_array($tokens[$next][0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                        $namespace .= $tokens[$next][1];
                    } elseif ($tokens[$next] === ';' || $tokens[$next] === '{') {
                        break;
                    }
                }
            } elseif (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && ! in_array($previous, [T_NEW, T_DOUBLE_COLON], true)) {
                for ($next = $index + 1; $next < $count; $next++) {
                    if (is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                        continue;
                    }

                    if (is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) {
                        $classes[] = ltrim($namespace.'\\'.$tokens[$next][1], '\\');
                    }

                    break;
                }
            }

            $previous = $token[0];
        }

        return $classes;
    }

    protected function safeRelative(mixed $relative): void
    {
        if (! is_string($relative) || $relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\')
            || str_contains($relative, "\0") || preg_match('~(?:^|/)\.\.(?:/|$)|^[a-zA-Z]:~', $relative)) {
            throw new InvalidModuleSourceException('The module PHP autoload path is unsafe.');
        }
    }

    protected function autoloadPath(string $path, mixed $relative, bool $directory): string
    {
        $this->safeRelative($relative);
        $root = realpath($path);
        $absolute = realpath($path.'/'.$relative);

        if ($root === false || $absolute === false || ($absolute !== $root && ! str_starts_with($absolute, $root.DIRECTORY_SEPARATOR))
            || ($directory ? ! is_dir($absolute) : ! is_file($absolute))) {
            throw new InvalidModuleSourceException('The module PHP autoload path is unavailable.');
        }

        return $absolute;
    }

    /** @param array<string, mixed> $data */
    protected function writeJson(string $file, array $data): void
    {
        if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n") === false) {
            throw new InvalidModuleSourceException('Could not write the module PHP runtime metadata.');
        }
    }

    /** @param array{psr4: array<string, list<string>>, classmap: array<string, string>, files: list<string>} $maps
     * @return array{psr4: array<string, list<string>>, classmap: array<string, string>, files: list<string>}
     */
    protected function relativeMaps(array $maps, string $root): array
    {
        $root = realpath($root) ?: $root;
        $relative = static fn (string $path): string => str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));

        return [
            'psr4' => array_map(static fn (array $paths): array => array_map($relative, $paths), $maps['psr4']),
            'classmap' => array_map($relative, $maps['classmap']),
            'files' => array_map($relative, $maps['files']),
        ];
    }

    /** @param array{composer: array<string, mixed>, lock: array<string, mixed>, packages: list<array<string, mixed>>, fingerprint: string} $data
     * @return array{psr4: array<string, list<string>>, classmap: array<string, string>, files: list<string>}
     */
    protected function scope(Manifest $manifest, string $path, string $stage, array $data, bool $offline): array
    {
        $tools = $this->tools($offline);
        $runner = dirname(__DIR__, 4).'/resources/module-tools/scope.php';

        if (is_dir($path.'/vendor')) {
            $this->dependencies->validateVendor($path, $data);
            $this->inventory($path.'/vendor');
            $this->files->copyDirectory($path.'/vendor', $stage.'/module/vendor');
        } elseif ($offline) {
            throw new InvalidModuleSourceException('Offline PHP preparation requires a complete raw vendor directory or an already prepared runtime.');
        } else {
            $this->composer(['install', '--no-autoloader'], $stage.'/module');
        }

        $this->dependencies->validateVendor($stage.'/module', $data);
        $this->inventory($stage.'/module/vendor');
        rename($stage.'/module/vendor', $stage.'/vendor');
        // Archive-supplied generated PHP is never interpreted, even in the builder.
        $this->files->deleteDirectory($stage.'/vendor/composer');
        $this->files->delete($stage.'/vendor/autoload.php');
        $this->files->ensureDirectoryExists($stage.'/vendor/composer');
        $packages = array_map(static function (array $package): array {
            $package['install-path'] = '../'.$package['name'];

            return $package;
        }, $data['packages']);
        $this->writeJson($stage.'/vendor/composer/installed.json', ['packages' => $packages, 'dev' => false, 'dev-package-names' => []]);
        $composer = $data['composer'];
        $composer['autoload'] = $this->autoload($manifest, $composer);
        unset($composer['autoload-dev'], $composer['scripts'], $composer['repositories'], $composer['config'], $composer['bin']);
        $composer['config'] = ['allow-plugins' => false];

        foreach (['psr-4', 'psr-0'] as $kind) {
            foreach (($composer['autoload'][$kind] ?? []) as $prefix => $paths) {
                $composer['autoload'][$kind][$prefix] = array_map(static fn (string $file): string => 'module/'.$file, (array) $paths);
            }
        }

        foreach (['files', 'classmap', 'exclude-from-classmap'] as $kind) {
            if (isset($composer['autoload'][$kind])) {
                $composer['autoload'][$kind] = array_map(static fn (string $file): string => 'module/'.$file, $composer['autoload'][$kind]);
            }
        }

        $this->writeJson($stage.'/composer.json', $composer);
        $prefix = 'BedrockPrivate\\M'.substr(hash('sha256', $manifest->key.'|'.$data['fingerprint']), 0, 24);
        $this->process([PHP_BINARY, $runner, 'scope', $tools, $stage, $prefix], $stage);
        $this->composer(['dump-autoload', '--optimize'], $stage);
        $this->process([PHP_BINARY, $runner, 'export', $tools, $stage], $stage);
        $maps = json_decode($this->files->get($stage.'/autoload.json'), true, 64, JSON_THROW_ON_ERROR);
        // Only JSON maps and scoped code ship; Composer's generated loader never enters the host.
        $this->files->deleteDirectory($stage.'/vendor/composer');
        $this->files->delete([$stage.'/vendor/autoload.php', $stage.'/composer.json']);

        return $maps;
    }

    /** @param array<string, mixed> $composer
     * @return array<string, mixed>
     */
    protected function autoload(Manifest $manifest, array $composer): array
    {
        $autoload = $composer['autoload'] ?? [];

        foreach (['psr-4', 'psr-0'] as $kind) {
            foreach (($manifest->autoload[$kind] ?? []) as $prefix => $paths) {
                $autoload[$kind][$prefix] = array_values(array_unique(array_merge((array) ($autoload[$kind][$prefix] ?? []), (array) $paths)));
            }
        }

        foreach (['files', 'classmap', 'exclude-from-classmap'] as $kind) {
            if (isset($autoload[$kind]) || isset($manifest->autoload[$kind])) {
                $autoload[$kind] = array_values(array_unique(array_merge($autoload[$kind] ?? [], $manifest->autoload[$kind] ?? [])));
            }
        }

        return $autoload;
    }

    /** @param array<string, mixed> $data
     * @return array<string, string>
     */
    public function packageIdentity(array $data): array
    {
        $packages = array_column($data['packages'], 'version', 'name');
        ksort($packages);

        return $packages;
    }

    /** @param array<string, mixed> $data */
    public function toolchainIdentity(array $data): ?string
    {
        return $data['packages'] === [] ? null : (hash_file('sha256', dirname(__DIR__, 4).'/resources/module-tools/composer.lock')
            ?: throw new InvalidModuleSourceException('The pinned PHP toolchain lockfile is unavailable.'));
    }

    protected function tools(bool $offline): string
    {
        $source = dirname(__DIR__, 4).'/resources/module-tools';

        $configured = config('bedrock.modules.php.toolchain_path', storage_path('framework/bedrock/module-tools'));

        if (! is_string($configured) || $configured === '') {
            throw new InvalidModuleSourceException('The configured PHP toolchain directory must be a nonempty host path.');
        }

        $base = str_starts_with($configured, '/') || preg_match('~^[a-zA-Z]:[/\\\\]~', $configured) ? $configured : base_path($configured);
        $target = $base.'/'.hash_file('sha256', $source.'/composer.lock');

        if (is_file($source.'/vendor/autoload.php')) {
            $this->verifyTools($source, $source);

            return $source;
        }

        if (! is_file($target.'/vendor/autoload.php')) {
            if ($offline) {
                throw new InvalidModuleSourceException('Offline PHP preparation requires the pinned PHP-Scoper toolchain to be installed.');
            }

            $this->files->ensureDirectoryExists($target);
            $this->files->copy($source.'/composer.json', $target.'/composer.json');
            $this->files->copy($source.'/composer.lock', $target.'/composer.lock');
            $this->composer(['install'], $target);
        }

        $this->verifyTools($target, $source);

        return $target;
    }

    protected function verifyTools(string $target, string $source): void
    {
        if (! hash_equals((string) hash_file('sha256', $source.'/composer.lock'), (string) hash_file('sha256', $target.'/composer.lock'))) {
            throw new InvalidModuleSourceException('The PHP toolchain does not match the package pinned lockfile.');
        }

        $lock = json_decode($this->files->get($source.'/composer.lock'), true, 64, JSON_THROW_ON_ERROR);
        $this->dependencies->validateVendor($target, ['packages' => $lock['packages']]);
    }

    /** @param list<string> $arguments */
    protected function composer(array $arguments, string $directory): void
    {
        $command = config('bedrock.modules.php.composer', ['composer']);

        if (! is_array($command) || $command === [] || ! array_is_list($command) || count(array_filter($command, is_string(...))) !== count($command)) {
            throw new InvalidModuleSourceException('The configured module Composer executable must be an argument list.');
        }

        $executable = $command[0];

        if ((str_contains($executable, '/') || str_contains($executable, '\\')) ? ! is_executable($executable) : (new ExecutableFinder)->find($executable) === null) {
            throw new InvalidModuleSourceException('The configured module Composer executable is unavailable. Install Composer or set bedrock.modules.php.composer.');
        }

        $flags = ['--no-dev', '--no-scripts', '--no-plugins', '--no-interaction'];

        if ($arguments[0] === 'install') {
            $flags = [...$flags, '--no-progress', '--prefer-dist'];
        }

        $this->process([...$command, ...$arguments, ...$flags], $directory);
    }

    /** @param list<string> $command */
    protected function process(array $command, string $directory): void
    {
        $environment = array_fill_keys(array_keys(getenv()), false);

        foreach (['PATH', 'HOME', 'USERPROFILE', 'SystemRoot', 'TEMP', 'TMP', 'TMPDIR', 'HTTPS_PROXY', 'HTTP_PROXY', 'NO_PROXY', 'COMPOSER_AUTH'] as $name) {
            if (($value = getenv($name)) !== false) {
                $environment[$name] = $value;
            }
        }

        $home = storage_path('framework/bedrock/module-composer');
        $this->files->ensureDirectoryExists($home);
        $repositories = config('bedrock.modules.php.repositories', []);

        if (! is_array($repositories)) {
            throw new InvalidModuleSourceException('The module PHP repositories setting must contain host-controlled Composer repositories.');
        }

        $this->writeJson($home.'/config.json', ['repositories' => $repositories, 'config' => ['allow-plugins' => false]]);
        $environment['COMPOSER_HOME'] = $home;
        $environment['COMPOSER_CACHE_DIR'] = $home.'/cache';
        $environment['COMPOSER_ALLOW_SUPERUSER'] = '1';
        $environment['COMPOSER_DISABLE_NETWORK'] = '1';

        // Only install operations may access the network. dump-autoload and scoping are local.
        if (in_array('install', $command, true)) {
            $environment['COMPOSER_DISABLE_NETWORK'] = false;
        }

        $process = new Process($command, $directory, $environment, timeout: (float) config('bedrock.modules.php.timeout', 300));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new InvalidModuleSourceException('Module PHP build failed in the isolated subprocess (exit '.($process->getExitCode() ?? 'unknown').').');
        }
    }
}
