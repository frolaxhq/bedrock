<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Services\Installation;

use Illuminate\Filesystem\Filesystem;
use JsonException;
use ReflectionClass;
use RuntimeException;
use Spatie\Permission\PermissionServiceProvider;

class FoundationInstaller
{
    public function __construct(protected Filesystem $files, protected string $basePath) {}

    /** @return array{changes: list<string>, problems: list<string>} */
    public function inspect(): array
    {
        $changes = [];
        $problems = [];

        try {
            foreach ($this->plannedFiles() as $relative => $contents) {
                $path = $this->basePath.'/'.$relative;

                if (! $this->files->exists($path)) {
                    $changes[] = $relative;
                } elseif ($this->files->get($path) !== $contents) {
                    if (in_array($relative, ['package.json', 'app/Models/User.php'], true)) {
                        $changes[] = $relative;
                    } else {
                        $problems[] = "Conflict: {$relative}. Keep your customizations and integrate the Bedrock template manually.";
                    }
                }
            }
        } catch (RuntimeException $exception) {
            $problems[] = $exception->getMessage();
        }

        return compact('changes', 'problems');
    }

    public function install(): void
    {
        $inspection = $this->inspect();

        if ($inspection['problems'] !== []) {
            throw new RuntimeException(implode("\n", $inspection['problems']));
        }
        $planned = $this->plannedFiles();
        foreach ($inspection['changes'] as $relative) {
            $path = $this->basePath.'/'.$relative;
            $this->files->ensureDirectoryExists(dirname($path));
            $this->files->replace($path, $planned[$relative]);
        }
    }

    /** @return array<string, string> */
    protected function plannedFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $planned = [
            'config/bedrock.php' => "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'auth' => ['enabled' => true, 'registration' => false],\n    'admin' => ['enabled' => true, 'path' => 'admin', 'navigation' => []],\n    'administration' => ['enabled' => true],\n    'brand' => ['name' => null, 'logo' => null],\n];\n",
            'resources/js/bedrock.tsx' => $this->files->get($root.'/resources/stubs/foundation/bedrock.tsx'),
            'resources/css/bedrock.css' => $this->files->get($root.'/resources/stubs/foundation/bedrock.css'),
            'vite.bedrock.config.ts' => $this->files->get($root.'/resources/stubs/foundation/vite.bedrock.config.ts'),
            'tsconfig.json' => $this->files->get($root.'/resources/stubs/foundation/tsconfig.json'),
        ];
        $userPath = $this->basePath.'/app/Models/User.php';

        if (! $this->files->isFile($userPath)) {
            throw new RuntimeException('Expected app/Models/User.php. Configure your custom authenticatable model manually before installation.');
        }
        $user = $this->files->get($userPath);

        $planned['app/Models/User.php'] = $this->prepareUser($user);
        $packagePath = $this->basePath.'/package.json';

        try {
            $json = $this->files->isFile($packagePath) ? $this->files->get($packagePath) : '{"private":true,"type":"module"}';

            if (! is_object(json_decode($json, flags: JSON_THROW_ON_ERROR))) {
                throw new RuntimeException('package.json must contain a JSON object.');
            }
            $package = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid package.json: '.$exception->getMessage(), previous: $exception);
        }
        foreach (['dependencies', 'devDependencies', 'scripts', 'overrides'] as $section) {
            if (isset($package[$section]) && ! is_array($package[$section])) {
                throw new RuntimeException("package.json {$section} must be an object.");
            }
        }
        foreach (['dependencies', 'devDependencies'] as $section) {
            if (isset($package[$section]['vite-plus']) && $package[$section]['vite-plus'] !== '1.0.0') {
                throw new RuntimeException('Conflict: existing vite-plus version. Align it with the supported 1.0.0 toolchain manually.');
            }
        }
        $required = [
            '@frolax/bedrock' => '^1.0', '@inertiajs/react' => '^3.0', '@inertiajs/core' => '^3.0',
            'react' => '^19.0', 'react-dom' => '^19.0',
        ];
        foreach ($required as $name => $version) {
            if (! isset($package['dependencies'][$name]) && ! isset($package['devDependencies'][$name])) {
                $package['dependencies'][$name] = $version;
            }
        }
        foreach (['vite-plus' => '1.0.0', 'laravel-vite-plugin' => '^3.0', 'tailwindcss' => '^4.0', 'typescript' => '^5.9', '@types/react' => '^19.0', '@types/react-dom' => '^19.0'] as $name => $version) {
            if (! isset($package['dependencies'][$name]) && ! isset($package['devDependencies'][$name])) {
                $package['devDependencies'][$name] = $version;
            }
        }

        $vite = 'npm:@voidzero-dev/vite-plus-core@1.0.0';

        if (isset($package['overrides']['vite']) && $package['overrides']['vite'] !== $vite) {
            throw new RuntimeException('Conflict: package.json overrides.vite. Align the Vite+ toolchain manually.');
        }

        if (isset($package['dependencies']['vite'], $package['devDependencies']['vite'])) {
            throw new RuntimeException('Conflict: vite is declared in both dependencies and devDependencies.');
        }
        $section = isset($package['dependencies']['vite']) ? 'dependencies' : 'devDependencies';
        $package[$section]['vite'] = $vite;
        $package['overrides']['vite'] = $vite;
        foreach (['bedrock:dev' => 'vp dev --config vite.bedrock.config.ts', 'bedrock:build' => 'vp build --config vite.bedrock.config.ts'] as $name => $command) {
            if (isset($package['scripts'][$name]) && $package['scripts'][$name] !== $command) {
                throw new RuntimeException("Conflict: package.json scripts.{$name} already exists.");
            }
            $package['scripts'][$name] = $command;
        }
        $planned['package.json'] = json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $migrations = glob($this->basePath.'/database/migrations/*create_permission_tables.php') ?: [];

        if ($migrations === []) {
            $providerFile = (new ReflectionClass(PermissionServiceProvider::class))->getFileName();

            if ($providerFile === false) {
                throw new RuntimeException('Permission provider resources are unavailable.');
            }
            $planned['database/migrations/2026_01_01_000001_create_permission_tables.php'] = $this->files->get(dirname($providerFile, 2).'/database/migrations/create_permission_tables.php.stub');
        }

        return $planned;
    }

    protected function prepareUser(string $source): string
    {
        // Mask comments and strings without changing offsets; imports are not trait usage.
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $code .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING], true)
                    ? str_repeat(' ', strlen($token[1])) : $token[1];
            } else {
                $code .= $token;
            }
        }

        if (! preg_match('/\bclass\s+User\s+extends\s+[^\{]+\{/', $code, $matches, PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException('Unable to safely integrate User. Add HasRoles and MustVerifyEmail manually.');
        }
        [$declaration, $offset] = $matches[0];
        $bodyOffset = $offset + strlen($declaration);
        $header = substr($source, $offset, strlen($declaration) - 1);

        if (! preg_match('/\bimplements\s+[^\{]*\bMustVerifyEmail\b/', $declaration)) {
            $header = rtrim($header).(str_contains($declaration, 'implements') ? ', ' : ' implements ').'\\Illuminate\\Contracts\\Auth\\MustVerifyEmail ';
        }
        $trait = preg_match('/\buse\s+[^;{}]*\bHasRoles\s*(?:[,;{])/', substr($code, $bodyOffset))
            ? '' : "\n    use \\Spatie\\Permission\\Traits\\HasRoles;\n";

        return substr($source, 0, $offset).$header.'{'.$trait.substr($source, $bodyOffset);
    }
}
