<?php

declare(strict_types=1);

// This entry point runs only in a child PHP process. No module configuration or
// module/vendor autoloader is ever included. All input PHP is parsed as data.
use Humbug\PhpScoper\Configuration\Configuration;
use Humbug\PhpScoper\Configuration\RegexChecker;
use Humbug\PhpScoper\Configuration\SymbolsConfigurationFactory;
use Humbug\PhpScoper\Container;
use Humbug\PhpScoper\Patcher\PatcherChain;
use Humbug\PhpScoper\Symbol\SymbolsRegistry;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

require $argv[2].'/vendor/autoload.php';

$root = realpath($argv[3]) ?: throw new RuntimeException('Missing build root.');
$parser = (new ParserFactory)->createForNewestSupportedVersion();
$finder = new NodeFinder;

/** @return list<string> */
function runtimeFiles(string $directory): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $entry) {
        if ($entry->isLink()) {
            throw new RuntimeException('Links are forbidden in the private runtime.');
        }

        if ($entry->isFile()) {
            $files[] = $entry->getPathname();
        }
    }

    sort($files);

    return $files;
}

/** Exclude every symbol except exact declarations found in the private graph. */
function runtimeExclusions(array $names, bool $namespace = false): array
{
    if ($names === []) {
        return ['/^/'];
    }

    return ['/^(?!(?:'.implode('|', array_map(static fn (string $name): string => preg_quote($name, '/'), array_keys($names))).')'.($namespace ? '\\\\?' : '').'$)/'];
}

if ($argv[1] === 'scope') {
    $namespaces = [];
    $classes = [];
    $functions = [];
    $constants = [];
    $privateFiles = runtimeFiles($root.'/vendor');

    foreach ($privateFiles as $file) {
        if (! str_ends_with($file, '.php')) {
            continue;
        }

        $nodes = $parser->parse((string) file_get_contents($file)) ?? [];
        $traverser = new NodeTraverser(new NameResolver);
        $nodes = $traverser->traverse($nodes);

        foreach ($finder->findInstanceOf($nodes, Node\Stmt\Namespace_::class) as $node) {
            $namespaces[$node->name?->toString() ?? ''] = true;
        }

        foreach ($finder->findInstanceOf($nodes, Node\Stmt\ClassLike::class) as $node) {
            if ($node->name !== null) {
                $classes[$node->namespacedName?->toString() ?? $node->name->toString()] = true;
            }
        }

        foreach ($finder->findInstanceOf($nodes, Node\Stmt\Function_::class) as $node) {
            $functions[$node->namespacedName->toString()] = true;
        }

        foreach ($finder->findInstanceOf($nodes, Node\Stmt\Const_::class) as $node) {
            foreach ($node->consts as $constant) {
                $constants[$constant->namespacedName->toString()] = true;
            }
        }

        // define() declarations are often used by SDK bootstrap files.
        foreach ($finder->findInstanceOf($nodes, Node\Expr\FuncCall::class) as $node) {
            if ($node->name instanceof Node\Name && strtolower($node->name->toString()) === 'define'
                && ($node->args[0]->value ?? null) instanceof Node\Scalar\String_) {
                $constants[$node->args[0]->value->value] = true;
            }
        }
    }

    // Global declarations also need scoping, even without a namespace statement.
    foreach ([$classes, $functions, $constants] as $symbols) {
        foreach (array_keys($symbols) as $symbol) {
            $position = strrpos($symbol, '\\');
            $namespaces[$position === false ? '' : substr($symbol, 0, $position)] = true;
        }
    }

    foreach (array_keys($namespaces) as $namespace) {
        while (($position = strrpos($namespace, '\\')) !== false) {
            $namespace = substr($namespace, 0, $position);
            $namespaces[$namespace] = true;
        }
    }

    $composer = json_decode((string) file_get_contents($root.'/composer.json'), true, 64, JSON_THROW_ON_ERROR);

    foreach (array_keys($composer['autoload']['psr-4'] ?? []) as $public) {
        foreach (array_keys($classes) as $private) {
            if (str_starts_with(strtolower($private), strtolower($public))) {
                throw new RuntimeException('A private dependency overlaps the module public namespace.');
            }
        }
    }

    $symbols = (new SymbolsConfigurationFactory(new RegexChecker))->createSymbolsConfiguration([
        'exclude-namespaces' => runtimeExclusions($namespaces, true),
        'exclude-classes' => runtimeExclusions($classes),
        'exclude-functions' => runtimeExclusions($functions),
        'exclude-constants' => runtimeExclusions($constants),
        'expose-global-classes' => false,
        'expose-global-functions' => false,
        'expose-global-constants' => false,
    ]);
    $configuration = new Configuration(null, null, $argv[4], null, [], [], new PatcherChain([]), $symbols);
    $scoper = (new Container)->getScoperFactory()->createScoper($configuration, SymbolsRegistry::create());

    foreach (array_merge(runtimeFiles($root.'/module'), $privateFiles, [$root.'/composer.json']) as $file) {
        $relative = substr($file, strlen($root) + 1);

        if (str_ends_with($file, '.php') || basename($file) === 'composer.json' || $relative === 'vendor/composer/installed.json') {
            $contents = (string) file_get_contents($file);
            $scoped = $scoper->scope($file, $contents);

            // Blade's @php/@inject directives are inline HTML to a PHP parser.
            // Rewrite known FQCN references in that HTML, preserving unknown host symbols.
            if (str_ends_with($file, '.blade.php')) {
                $tokens = token_get_all($scoped);
                $scoped = '';

                foreach ($tokens as $token) {
                    $text = is_array($token) ? $token[1] : $token;

                    if (is_array($token) && $token[0] === T_INLINE_HTML) {
                        foreach (array_keys($classes) as $class) {
                            $text = preg_replace('~(?<![a-zA-Z0-9_\\\\])'.preg_quote($class, '~').'(?![a-zA-Z0-9_\\\\])~', str_replace('\\', '\\\\', $argv[4].'\\'.$class), $text);
                        }
                    }

                    $scoped .= $text;
                }
            }

            file_put_contents($file, $scoped);
        }
    }
} elseif ($argv[1] === 'export') {
    // Composer-generated arrays are interpreted with a tiny AST whitelist.
    // Even generated loader PHP is never required or evaluated.
    $maps = ['psr4' => [], 'classmap' => [], 'files' => []];

    foreach (['psr4', 'classmap', 'files'] as $kind) {
        $file = $root.'/vendor/composer/autoload_'.$kind.'.php';

        if (! is_file($file)) {
            continue;
        }

        $variables = [];
        $read = function (Node $node) use (&$read, &$variables, $file): mixed {
            if ($node instanceof Node\Scalar\String_ || $node instanceof Node\Scalar\Int_) {
                return $node->value;
            }

            if ($node instanceof Node\Scalar\MagicConst\Dir) {
                return dirname($file);
            }

            if ($node instanceof Node\Expr\Variable && is_string($node->name) && array_key_exists($node->name, $variables)) {
                return $variables[$node->name];
            }

            if ($node instanceof Node\Expr\BinaryOp\Concat) {
                return $read($node->left).$read($node->right);
            }

            if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && $node->name->toString() === 'dirname' && count($node->args) === 1) {
                return dirname($read($node->args[0]->value));
            }

            if ($node instanceof Node\Expr\Array_) {
                $result = [];

                foreach ($node->items as $item) {
                    if ($item === null || $item->unpack || $item->byRef) {
                        throw new RuntimeException('Unsupported Composer autoload expression.');
                    }

                    if ($item->key === null) {
                        $result[] = $read($item->value);
                    } else {
                        $result[$read($item->key)] = $read($item->value);
                    }
                }

                return $result;
            }

            throw new RuntimeException('Unsupported Composer autoload expression.');
        };

        foreach ($parser->parse((string) file_get_contents($file)) ?? [] as $statement) {
            if ($statement instanceof Node\Stmt\Expression && $statement->expr instanceof Node\Expr\Assign
                && $statement->expr->var instanceof Node\Expr\Variable && is_string($statement->expr->var->name)) {
                $variables[$statement->expr->var->name] = $read($statement->expr->expr);
            } elseif ($statement instanceof Node\Stmt\Return_) {
                $maps[$kind] = $read($statement->expr);
            } else {
                throw new RuntimeException('Unsupported Composer autoload statement.');
            }
        }
    }

    unset($maps['classmap']['Composer\\InstalledVersions']);
    $privateRoot = realpath($root.'/vendor') ?: throw new RuntimeException('Missing private vendor directory.');
    $privatePath = static function (string $path) use ($privateRoot): bool {
        $resolved = realpath($path);

        return $resolved !== false && str_starts_with($resolved, $privateRoot.DIRECTORY_SEPARATOR);
    };

    // Composer can retain pre-scoping PSR-4 prefixes and skip rewritten classes
    // during optimization (notably on Windows). Export only declarations found
    // in the scoped private PHP, without executing any module/vendor code.
    foreach ($maps['psr4'] as $prefix => $paths) {
        $paths = array_values(array_filter($paths, static fn (string $path): bool => ! $privatePath($path)));

        if ($paths === []) {
            unset($maps['psr4'][$prefix]);
        } else {
            $maps['psr4'][$prefix] = $paths;
        }
    }

    foreach ($maps['classmap'] as $class => $file) {
        if ($privatePath($file)) {
            unset($maps['classmap'][$class]);
        }
    }

    foreach (runtimeFiles($privateRoot) as $file) {
        $relativeFile = str_replace('\\', '/', substr($file, strlen($privateRoot) + 1));

        if (! str_ends_with($file, '.php') || str_starts_with($relativeFile, 'composer/')) {
            continue;
        }

        $nodes = (new NodeTraverser(new NameResolver))->traverse($parser->parse((string) file_get_contents($file)) ?? []);

        foreach ($finder->findInstanceOf($nodes, Node\Stmt\ClassLike::class) as $node) {
            if ($node->name === null) {
                continue;
            }

            $class = $node->namespacedName?->toString() ?? $node->name->toString();

            if (! str_starts_with($class, 'BedrockPrivate\\')) {
                throw new RuntimeException('A private dependency class was not scoped.');
            }

            if (isset($maps['classmap'][$class])) {
                throw new RuntimeException('The private runtime declares a duplicate class.');
            }

            $maps['classmap'][$class] = $file;
        }
    }

    ksort($maps['classmap']);
    $relative = static function (string $path) use ($root): string {
        $absolute = realpath($path);

        if ($absolute === false || ! str_starts_with($absolute, $root.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Composer autoload path escapes the private runtime.');
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, strlen($root) + 1));
    };
    $maps['psr4'] = array_map(static fn (array $paths): array => array_map($relative, $paths), $maps['psr4']);
    $maps['classmap'] = array_map($relative, $maps['classmap']);
    $maps['files'] = array_values(array_unique(array_map($relative, $maps['files'])));
    file_put_contents($root.'/autoload.json', json_encode($maps, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
} else {
    throw new RuntimeException('Unknown private runtime build operation.');
}
