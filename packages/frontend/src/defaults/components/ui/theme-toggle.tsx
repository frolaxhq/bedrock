import * as React from 'react';
import { IconDeviceDesktop, IconMoon, IconSun } from '@tabler/icons-react';
import { Button } from '@frolax/bedrock/ui';
import { useDefaultTheme } from '@frolax/bedrock/theme';

export interface ThemeToggleProps extends Omit<React.ComponentProps<typeof Button>, 'onClick'> {
    labels?: Partial<Record<'light' | 'dark' | 'system', string>>;
}

export function ThemeToggle({ labels, ...props }: ThemeToggleProps) {
    const { mode, setMode } = useDefaultTheme();
    const next = mode === 'light' ? 'dark' : mode === 'dark' ? 'system' : 'light';
    const Icon = mode === 'light' ? IconSun : mode === 'dark' ? IconMoon : IconDeviceDesktop;

    return (
        <Button variant="outline" size="icon" onClick={() => setMode(next)} aria-label={`Theme: ${mode}. Switch to ${next}.`} title={labels?.[mode] ?? mode} {...props}>
            <Icon aria-hidden="true" />
        </Button>
    );
}
