<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Enums\Module;

use BackedEnum;
use Frolax\Bedrock\Contracts\Enums\HasColor;
use Frolax\Bedrock\Contracts\Enums\HasLabel;
use Frolax\Bedrock\Enums\Color;

enum ModuleStatus: string implements HasColor, HasLabel
{
    case Discovered = 'discovered';
    case Installed = 'installed';
    case Enabled = 'enabled';
    case Disabled = 'disabled';

    /**
     * Determine whether the module is active in the application runtime.
     */
    public function isActive(): bool
    {
        return $this === self::Enabled;
    }

    /**
     * The human-readable label for the status.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::Discovered => 'Discovered',
            self::Installed => 'Installed',
            self::Enabled => 'Enabled',
            self::Disabled => 'Disabled',
        };
    }

    public function getColor(): BackedEnum
    {
        return match ($this) {
            self::Discovered => Color::Primary,
            self::Installed => Color::Info,
            self::Enabled => Color::Success,
            self::Disabled => Color::Warning,
        };
    }
}
