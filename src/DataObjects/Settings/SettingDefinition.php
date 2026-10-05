<?php

declare(strict_types=1);

namespace Frolax\Bedrock\DataObjects\Settings;

use Spatie\LaravelData\Data;

class SettingDefinition extends Data
{
    /**
     * @param  list<string>  $rules
     * @param  array<string, string>  $options
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly string $label,
        public readonly mixed $default = null,
        public readonly ?string $description = null,
        public readonly array $rules = [],
        public readonly array $options = [],
    ) {}

    public function isSecret(): bool
    {
        return $this->type === 'secret';
    }

    /** @return array<string, mixed> */
    public function forInertia(bool $configured = false): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type,
            'label' => $this->label,
            'description' => $this->description,
            'default' => $this->isSecret() ? null : $this->default,
            'rules' => $this->rules,
            'options' => $this->options,
            'secret' => $this->isSecret(),
            'configured' => $this->isSecret() && $configured,
        ];
    }
}
