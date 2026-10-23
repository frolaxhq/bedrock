import * as React from 'react';
import { cn } from '@frolax/bedrock/lib';

export interface EmptyStateProps extends Omit<React.HTMLAttributes<HTMLDivElement>, 'title'> {
    title: React.ReactNode;
    description?: React.ReactNode;
    action?: React.ReactNode;
    icon?: React.ReactNode;
}

export function EmptyState({
    title,
    description,
    action,
    icon,
    className,
    ...props
}: EmptyStateProps) {
    return (
        <div
            className={cn(
                'flex min-h-56 flex-col items-center justify-center gap-3 rounded-xl border border-dashed p-8 text-center',
                className,
            )}
            {...props}
        >
            {icon}
            <h2 className="text-lg font-semibold">{title}</h2>
            {description && <p className="max-w-md text-sm text-muted-foreground">{description}</p>}
            {action}
        </div>
    );
}
