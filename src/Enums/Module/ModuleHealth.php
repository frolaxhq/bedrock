<?php

declare(strict_types=1);

namespace Frolax\Bedrock\Enums\Module;

use BackedEnum;
use Frolax\Bedrock\Contracts\Enums\HasColor;
use Frolax\Bedrock\Contracts\Enums\HasLabel;
use Frolax\Bedrock\Enums\Color;

enum ModuleHealth: string implements HasColor, HasLabel
{
    case Healthy = 'healthy';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Healthy => 'Healthy',
            self::Failed => 'Failed',
        };
    }

    public function getColor(): BackedEnum
    {
        return match ($this) {
            self::Healthy => Color::Success,
            self::Failed => Color::Danger,
        };
    }
}
