import * as React from 'react';
import { Spinner } from '@frolax/bedrock/ui';
import { cn } from '@frolax/bedrock/lib';

export interface LoadingStateProps extends React.HTMLAttributes<HTMLDivElement> {
    label?: string;
}
export function LoadingState({ label = 'Loading', className, ...props }: LoadingStateProps) {
    return (
        <div
            className={cn(
                'flex min-h-32 items-center justify-center gap-2 text-sm text-muted-foreground',
                className,
            )}
            {...props}
        >
            <Spinner />
            <span>{label}</span>
        </div>
    );
}
