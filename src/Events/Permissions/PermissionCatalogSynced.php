<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Events\Permissions;

class PermissionCatalogSynced
{
    public function __construct(public readonly ?string $module = null) {}
}
