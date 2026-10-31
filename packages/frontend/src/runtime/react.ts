import { loadRemote, registerRemotes } from '@module-federation/enhanced/runtime';
import { Component, type ReactNode } from 'react';
import { satisfies } from 'semver';
import type { BedrockPage, BedrockFrontendModule, PageResolver, ResolvedPage } from './types.js';
import {
    prepareNavigation,
    registerNavigationRefresh,
    type NavigationPreparationOptions,
    type NavigationRuntime,
} from './navigation.js';

export type {
    BedrockFrontendModule,
    BedrockSharedProps,
    PageResolver,
    ResolvedPage,
} from './types.js';
export {
    NavigationProvider,
    registerHostNavigation,
    resetNavigationStore,
    runNavigationAction,
    useNavigation,
    useNavigationAction,
} from './navigation.js';
export type { NavigationPreparationOptions, NavigationRuntime } from './navigation.js';

interface ResolverOptions {
    local: (name: string, page?: BedrockPage) => ResolvedPage | Promise<ResolvedPage>;
    versions?: Record<string, string>;
    navigation?: NavigationPreparationOptions;
}

interface RemoteModule extends NavigationRuntime {
    resolvePage(name: string): Promise<ResolvedPage> | ResolvedPage;
}

interface ErrorBoundaryProps {
    children: ReactNode;
    fallback: (error: Error, retry: () => void) => ReactNode;
    resetKey?: string;
}

interface ErrorBoundaryState {
    error: Error | null;
}

const remotes = new Map<string, Promise<RemoteModule>>();
const styles = new Set<string>();

export class BedrockModuleErrorBoundary extends Component<ErrorBoundaryProps, ErrorBoundaryState> {
    public state: ErrorBoundaryState = { error: null };

    public static getDerivedStateFromError(error: Error): ErrorBoundaryState {
        return { error };
    }

    public componentDidUpdate(previous: ErrorBoundaryProps): void {
        if (this.state.error && previous.resetKey !== this.props.resetKey) {
            this.setState({ error: null });
        }
    }

    public render(): ReactNode {
        if (this.state.error) {
            return this.props.fallback(this.state.error, () => this.setState({ error: null }));
        }

        return this.props.children;
    }
}

export function createBedrockResolver(options: ResolverOptions): PageResolver {
    let currentPage: BedrockPage | undefined;
    const loadNavigationRuntime = async (key: string) =>
        loadRuntime(descriptorFor(key, currentPage), options.versions);

    if (!isServer()) {
        registerNavigationRefresh(() => {
            if (!currentPage?.props.__bedrock) return;

            delete currentPage.props.__bedrock.resolvedNavigation;
            void prepareNavigation(currentPage, loadNavigationRuntime, options.navigation);
        });
    }

    const resolver = async (name: string, page?: BedrockPage): Promise<ResolvedPage> => {
        currentPage = page;

        if (page?.props.__bedrock) {
            await prepareNavigation(page, loadNavigationRuntime, options.navigation);
        }

        const match = /^module:([a-z][a-z0-9-]*)\/(.+)$/.exec(name);

        if (!match) {
            return options.local(name, page);
        }

        const [, key, modulePage] = match;
        const descriptor = descriptorFor(key!, page);
        ensureCompatible(descriptor, options.versions ?? hostVersions());

        if (isServer()) {
            const remote = await loadServer(descriptor);
            return remote.resolvePage(modulePage!);
        }

        loadStyles(descriptor.css);
        const remote = await loadClient(descriptor);

        return remote.resolvePage(modulePage!);
    };

    return resolver as unknown as PageResolver;
}

function descriptorFor(key: string, page?: BedrockPage): BedrockFrontendModule {
    const descriptor = page?.props.__bedrock?.modules[key];

    if (!descriptor) {
        throw new Error(`Frontend module [${key}] is unavailable.`);
    }

    return descriptor;
}

function ensureCompatible(descriptor: BedrockFrontendModule, versions: Record<string, string>) {
    for (const [dependency, constraint] of Object.entries(descriptor.requires)) {
        const installed = versions[dependency];

        if (!installed || !satisfies(installed, constraint, { includePrerelease: true })) {
            throw new Error(
                `Frontend module [${descriptor.key}] requires [${dependency} ${constraint}], ` +
                    `but the host provides [${installed ?? 'nothing'}].`,
            );
        }
    }
}

async function loadRuntime(
    descriptor: BedrockFrontendModule,
    versions?: Record<string, string>,
): Promise<RemoteModule> {
    ensureCompatible(descriptor, versions ?? hostVersions());

    if (isServer()) return loadServer(descriptor);

    loadStyles(descriptor.css);

    return loadClient(descriptor);
}

async function loadClient(descriptor: BedrockFrontendModule): Promise<RemoteModule> {
    const id = `${descriptor.key}@${descriptor.version}`;
    let pending = remotes.get(id);

    if (!pending) {
        pending = (async () => {
            registerRemotes(
                [
                    {
                        name: descriptor.key,
                        alias: descriptor.key,
                        entry: descriptor.client,
                        type: 'module',
                    },
                ],
                { force: true },
            );
            const loaded = await loadRemote<RemoteModule>(`${descriptor.key}/module`);

            if (!validRemote(loaded)) {
                throw new Error(
                    `Frontend module [${descriptor.key}] does not expose the ./module contract.`,
                );
            }

            return loaded;
        })();
        remember(id, pending);
    }

    return pending;
}

async function loadServer(descriptor: BedrockFrontendModule): Promise<RemoteModule> {
    const id = `ssr:${descriptor.key}@${descriptor.version}`;
    let pending = remotes.get(id);

    if (!pending) {
        const specifier = fileSpecifier(descriptor.server);
        pending = import(/* @vite-ignore */ specifier).then((loaded: unknown) => {
            const remote = loaded as RemoteModule;

            if (!validRemote(remote)) {
                throw new Error(
                    `SSR module [${descriptor.key}] does not export the module contract.`,
                );
            }

            return remote;
        });
        remember(id, pending);
    }

    return pending;
}

function remember(id: string, pending: Promise<RemoteModule>): void {
    remotes.set(id, pending);
    void pending.catch(() => {
        if (remotes.get(id) === pending) {
            remotes.delete(id);
        }
    });
}

function validRemote(remote: RemoteModule | undefined | null): remote is RemoteModule {
    return (
        Boolean(remote) &&
        typeof remote?.resolvePage === 'function' &&
        typeof remote.resolveNavigation === 'function' &&
        typeof remote.runNavigationAction === 'function'
    );
}

function fileSpecifier(path: string): string {
    if (path.startsWith('file:')) {
        return path;
    }

    const normalized = path.replace(/\\/g, '/');

    return normalized.match(/^[A-Za-z]:\//) ? `file:///${normalized}` : `file://${normalized}`;
}

function loadStyles(urls: string[]) {
    for (const url of urls) {
        if (styles.has(url)) continue;

        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = url;
        link.dataset.bedrockStyle = url;
        document.head.append(link);
        styles.add(url);
    }
}

function hostVersions(): Record<string, string> {
    return (
        (globalThis as typeof globalThis & { __BEDROCK_SHARED_VERSIONS__?: Record<string, string> })
            .__BEDROCK_SHARED_VERSIONS__ ?? {}
    );
}

function isServer() {
    return typeof window === 'undefined';
}
