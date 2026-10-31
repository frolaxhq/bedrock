import * as React from 'react';
import { cn } from '@frolax/bedrock/lib';

export function PageContent({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('flex flex-1 flex-col gap-6 py-6', className)} {...props} />;
}
