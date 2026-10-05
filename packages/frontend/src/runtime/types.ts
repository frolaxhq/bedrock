import type { ResolvedComponent } from '@inertiajs/react';
import type { NavigationDescriptor, NavigationResolution, NavigationViewer } from '../module/index.js';

export interface BedrockFrontendModule {
    key: string;
    version: string;
    driver: 'inertia-react';
    client: string;
    server: string;
    css: string[];
    requires: Record<string, string>;
    navigation: NavigationDescriptor;
    development: boolean;
}

export interface BedrockSharedProps extends Record<string, unknown> {
    __bedrock?: {
        schema: 1;
        viewer?: BedrockViewer | null;
        modules: Record<string, BedrockFrontendModule>;
        navigation?: BedrockNavigationItem[];
        resolvedNavigation?: NavigationResolution;
        /** Request-local SSR state. Non-enumerable and never serialized into the Inertia payload. */
        runtimeNavigation?: NavigationResolution;
    };
}

export interface BedrockViewer extends NavigationViewer {}

export interface BedrockNavigationItem {
    module: string;
    key: string;
    kind: 'link' | 'action';
    label: string;
    route?: string | null;
    href?: string | null;
    group?: string | null;
    order: number;
    permission?: string | null;
    panels: string[];
    active: boolean;
    parent?: string | null;
    children: BedrockNavigationItem[];
}

export interface BedrockPage {
    component?: string;
    url?: string;
    props: BedrockSharedProps;
}

export type ResolvedPage =
    | ResolvedComponent
    | { default: ResolvedComponent };

export type PageResolver = (
    name: string,
    page?: BedrockPage,
) => ResolvedComponent | Promise<ResolvedComponent> | { default: ResolvedComponent };
