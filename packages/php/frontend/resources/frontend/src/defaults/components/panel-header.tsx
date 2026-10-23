import * as React from 'react';
import { IconTextDirectionLtr, IconTextDirectionRtl } from '@tabler/icons-react';
import { Button, ThemeToggle } from '@frolax/bedrock/ui';
import { useDefaultTheme } from '@frolax/bedrock/theme';
import { cn } from 'cn';
import { PanelBreadcrumbs } from './panel-breadcrumbs.js';

export interface PanelHeaderProps extends React.ComponentProps<'div'> {
    url?: string;
}

/**
 * The default panel header: navigation-derived breadcrumbs on one side and
 * the theme and text direction controls on the other. The sidebar triggers
 * are rendered by the panel shell itself.
 */
export function PanelHeader({ className, url = '/', ...props }: PanelHeaderProps) {
    return (
        <div
            data-slot="panel-header-content"
            className={cn('flex min-w-0 flex-1 items-center justify-between gap-3', className)}
            {...props}
        >
            <PanelBreadcrumbs className="min-w-0" url={url} />
            <div className="flex shrink-0 items-center gap-1">
                <DirectionToggle />
                <ThemeToggle />
            </div>
        </div>
    );
}

function DirectionToggle() {
    const { direction, setDirection } = useDefaultTheme();
    const next = direction === 'rtl' ? 'ltr' : 'rtl';
    const Icon = direction === 'rtl' ? IconTextDirectionRtl : IconTextDirectionLtr;

    return (
        <Button
            type="button"
            variant="outline"
            size="icon"
            aria-label={`Text direction: ${direction}. Switch to ${next}.`}
            onClick={() => setDirection(next)}
        >
            <Icon aria-hidden="true" />
        </Button>
    );
}
