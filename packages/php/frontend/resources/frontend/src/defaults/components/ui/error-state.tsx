import * as React from 'react';
import { Alert, AlertDescription, AlertTitle } from '@frolax/bedrock/ui';

export interface ErrorStateProps extends Omit<React.HTMLAttributes<HTMLDivElement>, 'title'> {
    title?: React.ReactNode;
    error?: Error | string;
    action?: React.ReactNode;
}

export function ErrorState({
    title = 'Something went wrong',
    error,
    action,
    ...props
}: ErrorStateProps) {
    return (
        <Alert variant="destructive" {...props}>
            <AlertTitle>{title}</AlertTitle>
            {error && (
                <AlertDescription>
                    {typeof error === 'string' ? error : error.message}
                </AlertDescription>
            )}
            {action}
        </Alert>
    );
}
