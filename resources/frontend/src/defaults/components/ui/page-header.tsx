import * as React from 'react';
import { cn } from '@frolax/bedrock/lib';

export function PageHeader({ className, ...props }: React.HTMLAttributes<HTMLElement>) {
    return (
        <header
            className={cn(
                'flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between',
                className,
            )}
            {...props}
        />
    );
}
export function PageTitle({ className, ...props }: React.HTMLAttributes<HTMLHeadingElement>) {
    return <h1 className={cn('text-2xl font-semibold tracking-tight', className)} {...props} />;
}
export function PageDescription({
    className,
    ...props
}: React.HTMLAttributes<HTMLParagraphElement>) {
    return <p className={cn('text-sm text-muted-foreground', className)} {...props} />;
}
export function PageActions({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('flex flex-wrap items-center gap-2', className)} {...props} />;
}
