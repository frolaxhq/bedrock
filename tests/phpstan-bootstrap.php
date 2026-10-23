<?php

declare(strict_types=1);

// Business modules are deliberately not auto-discovered Composer providers.
// Register their view namespaces without activating them during static analysis.
foreach (glob(dirname(__DIR__).'/modules/*/resources/views') ?: [] as $views) {
    view()->addNamespace(basename(dirname($views, 2)), $views);
}
