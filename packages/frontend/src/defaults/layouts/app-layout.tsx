import * as React from 'react';
import { Head, usePage } from '@inertiajs/react';
import { PanelProvider, PanelShell } from '@bedrock/panel';
import type { BedrockSharedProps } from '@bedrock/react';
import { NavigationProvider } from '../../runtime/navigation.js';

export interface AppLayoutProps extends React.HTMLAttributes<HTMLDivElement> {
    title: string;
    heading?: string;
    description?: string;
    children: React.ReactNode;
}

export function AppLayout({ children, ...props }: AppLayoutProps) {
    const page = usePage<BedrockSharedProps>();
    const bedrock = page.props.__bedrock;

    return (
        <>
            <Head title={props.title}/>

            <NavigationProvider
                value={bedrock?.runtimeNavigation ?? bedrock?.resolvedNavigation}>
                <PanelProvider
                    panel={bedrock?.viewer?.panel}
                    navigation={bedrock?.navigation ?? []}
                    viewer={bedrock?.viewer}
                    url={page.url}
                    brand={typeof page.props.name === 'string' ? page.props.name : undefined}
                >
                    <PanelShell {...props}>{children}</PanelShell>
                </PanelProvider>
            </NavigationProvider>
        </>
    );
}
