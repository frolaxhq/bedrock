import * as React from 'react';
import { cn } from '@frolax/bedrock/lib';

export interface AuthLayoutProps extends React.HTMLAttributes<HTMLDivElement> {
    header?: React.ReactNode;
    aside?: React.ReactNode;
    footer?: React.ReactNode;
}

export function AuthLayout({
    header,
    aside,
    footer,
    children,
    className,
    ...props
}: AuthLayoutProps) {
    return (
        <div
            className={cn(
                'grid min-h-screen bg-background text-foreground lg:grid-cols-2',
                className,
            )}
            {...props}
        >
            {aside && (
                <aside className="hidden min-h-screen border-e border-border bg-muted/30 lg:block">
                    {aside}
                </aside>
            )}
            <div className={cn('flex min-h-screen min-w-0 flex-col', !aside && 'lg:col-span-2')}>
                {header && <header className="shrink-0">{header}</header>}
                <main className="flex flex-1 items-center justify-center p-6">
                    <div className="w-full max-w-md">{children}</div>
                </main>
                {footer && <footer className="shrink-0">{footer}</footer>}
            </div>
        </div>
    );
}
