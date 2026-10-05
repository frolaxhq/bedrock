import * as React from 'react';
import { cn } from 'cn';

export interface PanelBrandProps extends React.ComponentProps<'div'> {
    name?: string;
}

/**
 * The default sidebar brand. Renders the application name shared through
 * Inertia; hosts override it through the `components/panel-brand`
 * convention for logos and wordmarks.
 */
export function PanelBrand({ className, name, ...props }: PanelBrandProps) {
    const label = name && name !== '' ? name : 'Bedrock';

    return (
        <div
            data-slot="panel-brand"
            className={cn('flex min-h-14 items-center gap-2 px-3 text-sm font-semibold tracking-tight', className)}
            {...props}
        >
            <span className="truncate" title={label}>{label}</span>
        </div>
    );
}
