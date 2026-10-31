import {
    createContext,
    createElement,
    useContext,
    useSyncExternalStore,
    type ReactNode,
} from 'react';
import {
    defineBedrockModule,
    materializeNavigation,
    type NavigationContext,
    type NavigationDefinition,
    type NavigationDefinitionValue,
    type NavigationIcon,
    type NavigationItemDefinition,
    type NavigationNotifier,
    type NavigationResolution,
    type ResolvedNavigationItem,
} from '../module/index.js';
import type { BedrockFrontendModule, BedrockNavigationItem, BedrockPage } from './types.js';

export type { NavigationContext } from '../module/index.js';

export interface NavigationRuntime {
    resolveNavigation(
        this: void,
        context: NavigationContext,
    ): Promise<NavigationResolution> | NavigationResolution;
    runNavigationAction(this: void, key: string, context: NavigationContext): Promise<void> | void;
    materializeNavigation?(this: void): Promise<NavigationDefinitionValue>;
}

export interface NavigationPreparationOptions {
    timeout?: number;
    strict?: boolean;
    notify?: Partial<NavigationNotifier>;
}

interface BoundAction {
    runtime: NavigationRuntime;
    context: NavigationContext;
    key: string;
}

interface NavigationStore {
    host?: NavigationDefinition;
    refresh?: () => void;
    resolution: NavigationResolution;
    actions: Map<string, BoundAction>;
    loading: Set<string>;
    listeners: Set<() => void>;
    version: number;
    pending?: { controller: AbortController };
}

const storeKey = Symbol.for('frolax.bedrock.navigation');
const empty: NavigationResolution = { groups: [], items: [] };
const NavigationReactContext = createContext<NavigationResolution | null>(null);

function store(): NavigationStore {
    const target = globalThis as typeof globalThis & { [storeKey]?: NavigationStore };

    if (!target[storeKey]) {
        target[storeKey] = {
            resolution: empty,
            actions: new Map(),
            loading: new Set(),
            listeners: new Set(),
            version: 0,
        };
    }

    return target[storeKey];
}

export function registerHostNavigation(definition?: NavigationDefinition): void {
    const current = store();

    current.host = definition;
    current.refresh?.();
}

export function registerNavigationRefresh(refresh?: () => void): void {
    store().refresh = refresh;
}

/**
 * Clear the navigation store, actions and host registration. Intended for
 * tests and SSR isolation between requests.
 */
export function resetNavigationStore(): void {
    const target = globalThis as typeof globalThis & { [storeKey]?: NavigationStore | undefined };

    target[storeKey] = undefined;
}

export function useNavigation(): NavigationResolution {
    useRevision();

    return useContext(NavigationReactContext) ?? store().resolution;
}

export function NavigationProvider({
    children,
    value,
}: {
    children: ReactNode;
    value?: NavigationResolution;
}) {
    useRevision();
    const resolution = typeof window === 'undefined' ? (value ?? empty) : store().resolution;

    return createElement(NavigationReactContext.Provider, { value: resolution }, children);
}

export function useNavigationAction(key: string): {
    loading: boolean;
    run(this: void): Promise<void>;
} {
    useRevision();

    return {
        loading: store().loading.has(key),
        run: () => runNavigationAction(key),
    };
}

export async function runNavigationAction(this: void, key: string): Promise<void> {
    const current = store();
    const action = current.actions.get(key);

    if (!action || current.loading.has(key)) return;

    current.loading.add(key);
    updateLoading(key, true);
    emit();

    try {
        await action.runtime.runNavigationAction(action.key, action.context);
    } catch (error) {
        action.context.notify.error(
            error instanceof Error ? error.message : 'The navigation action failed.',
        );
        throw error;
    } finally {
        current.loading.delete(key);
        updateLoading(key, false);
        emit();
    }
}

export async function prepareNavigation(
    page: BedrockPage,
    loadModule: (key: string) => Promise<NavigationRuntime>,
    options: NavigationPreparationOptions = {},
): Promise<NavigationResolution> {
    const shared = page.props.__bedrock;

    if (!shared) return publish(empty);

    const browser = typeof window !== 'undefined';

    if (browser) {
        store().pending?.controller.abort(
            new Error('A newer navigation replaced this resolution.'),
        );
    }

    if (shared.resolvedNavigation) {
        return complete(page, await rebind(page, loadModule, options));
    }

    const controller = new AbortController();
    if (browser) store().pending = { controller };
    const timeout = Math.max(1, options.timeout ?? 2_000);
    const timer = setTimeout(
        () => controller.abort(new Error(`Navigation resolution exceeded ${timeout}ms.`)),
        timeout,
    );
    const notifier = createNotifier(options.notify);
    const actions = new Map<string, BoundAction>();

    try {
        const resolutions: NavigationResolution[] = [];
        const host = store().host;

        if (host) {
            const runtime = defineBedrockModule({ pages: {}, navigation: host });
            const context = contextFor('host', undefined, page, controller.signal, notifier);
            const resolution = await withTimeout(
                Promise.resolve(runtime.resolveNavigation(context)),
                timeout,
                `Navigation resolution exceeded ${timeout}ms.`,
                controller.signal,
            );
            resolutions.push(filterHost(resolution, context));
            bindActions(actions, resolution.items, runtime, context, 'host');
        }

        const byModule = new Map<string, BedrockNavigationItem[]>();

        for (const item of shared.navigation ?? []) {
            const entries = byModule.get(item.module) ?? [];

            entries.push(item);
            byModule.set(item.module, entries);
        }

        for (const [key, envelope] of byModule) {
            if (key === '@host') {
                resolutions.push(staticResolution(key, envelope, []));
                continue;
            }
            const runtime = await loadModule(key);
            const context = contextFor('module', key, page, controller.signal, notifier);

            try {
                const dynamic = await withTimeout(
                    Promise.resolve(runtime.resolveNavigation(context)),
                    timeout,
                    `Navigation resolution exceeded ${timeout}ms.`,
                    controller.signal,
                );
                const groups = shared.modules[key]?.navigation.groups ?? [];
                const resolution = enforceEnvelope(key, envelope, dynamic, groups);
                resolutions.push(resolution);
                bindActions(actions, resolution.items, runtime, context, key);
            } catch (error) {
                if (
                    controller.signal.aborted &&
                    controller.signal.reason instanceof Error &&
                    controller.signal.reason.message.includes('newer navigation')
                ) {
                    throw controller.signal.reason;
                }
                if (options.strict) throw error;
                resolutions.push(
                    staticResolution(key, envelope, shared.modules[key]?.navigation.groups ?? []),
                );
            }
        }

        const resolution = merge(resolutions);
        if (browser) store().actions = actions;
        return complete(page, resolution);
    } catch (error) {
        if (
            controller.signal.aborted &&
            controller.signal.reason instanceof Error &&
            controller.signal.reason.message.includes('newer navigation')
        ) {
            throw controller.signal.reason;
        }
        if (options.strict) throw error;

        return complete(page, staticFallback(shared.navigation ?? [], shared.modules));
    } finally {
        clearTimeout(timer);

        if (browser) {
            const finished = store();

            if (finished.pending?.controller === controller) {
                finished.pending = undefined;
            }
        }
    }
}

/**
 * Reuse the SSR resolution snapshot on the client and rebind the runtime
 * presentation that cannot survive serialization: React icons and action
 * handlers. Dynamic resolvers never run again, so hydration does not issue
 * duplicate requests.
 */
async function rebind(
    page: BedrockPage,
    loadModule: (key: string) => Promise<NavigationRuntime>,
    options: NavigationPreparationOptions,
): Promise<NavigationResolution> {
    const shared = page.props.__bedrock!;
    const snapshot = shared.resolvedNavigation!;
    const controller = new AbortController();
    store().pending = { controller };
    const notifier = createNotifier(options.notify);
    const icons = new Map<string, NavigationIcon>();
    const actions = new Map<string, BoundAction>();
    const local: NavigationResolution[] = [];

    try {
        const host = store().host;

        if (host) {
            const runtime = defineBedrockModule({ pages: {}, navigation: host });
            const context = contextFor('host', undefined, page, controller.signal, notifier);

            try {
                const value = await materializeNavigation(host);
                collectIcons('host', value.items, icons);
                bindDefinitionActions(
                    actions,
                    'host',
                    value.items,
                    runtime,
                    context,
                    snapshotKeys(snapshot.items, undefined),
                );
                local.push(filterHost(staticDefinitions(value), context));
            } catch (error) {
                if (options.strict) throw error;
            }
        }

        const modules = new Set(
            snapshot.items
                .map((item) => item.module)
                .filter((module): module is string => Boolean(module) && module !== '@host'),
        );

        for (const key of modules) {
            const context = contextFor('module', key, page, controller.signal, notifier);

            try {
                const runtime = await loadModule(key);

                if (typeof runtime.materializeNavigation !== 'function') continue;

                const value = await runtime.materializeNavigation();
                collectIcons(key, value.items, icons);
                bindDefinitionActions(
                    actions,
                    key,
                    value.items,
                    runtime,
                    context,
                    snapshotKeys(snapshot.items, key),
                );
            } catch (error) {
                if (options.strict) throw error;
            }
        }

        if (typeof window !== 'undefined') store().actions = actions;

        const rebound = attachIcons(snapshot, icons);
        const existing = new Set(
            flatten(rebound.items)
                .filter((item) => item.module === undefined)
                .map((item) => item.key),
        );
        const hostOnly = local.map((resolution) => ({
            ...resolution,
            items: resolution.items.filter((item) => !existing.has(item.key)),
        }));

        return merge([...hostOnly, rebound]);
    } finally {
        const finished = store();

        if (finished.pending?.controller === controller) {
            finished.pending = undefined;
        }
    }
}

/**
 * The authorized action keys present in the snapshot for one namespace.
 */
function snapshotKeys(items: ResolvedNavigationItem[], module: string | undefined): Set<string> {
    const keys = new Set<string>();

    const visit = (item: ResolvedNavigationItem): void => {
        if ((item.module ?? undefined) === module && item.kind === 'action') keys.add(item.key);
        item.children.forEach(visit);
    };

    items.forEach(visit);

    return keys;
}

function collectIcons(
    namespace: string,
    items: NavigationItemDefinition[],
    icons: Map<string, NavigationIcon>,
): void {
    for (const item of items) {
        if (item.icon) icons.set(`${namespace}:${item.key}`, item.icon);
        if (item.children) collectIcons(namespace, item.children, icons);
    }
}

function bindDefinitionActions(
    actions: Map<string, BoundAction>,
    namespace: string,
    items: NavigationItemDefinition[],
    runtime: NavigationRuntime,
    context: NavigationContext,
    allowed: Set<string>,
): void {
    for (const item of items) {
        if (item.kind === 'action' && allowed.has(item.key)) {
            actions.set(`${namespace}:${item.key}`, { runtime, context, key: item.key });
        }

        if (item.children)
            bindDefinitionActions(actions, namespace, item.children, runtime, context, allowed);
    }
}

function attachIcons(
    resolution: NavigationResolution,
    icons: Map<string, NavigationIcon>,
): NavigationResolution {
    const visit = (item: ResolvedNavigationItem): ResolvedNavigationItem => ({
        ...item,
        loading: false,
        icon: icons.get(`${item.module ?? 'host'}:${item.key}`) ?? item.icon,
        children: item.children.map(visit),
    });

    return { groups: resolution.groups, items: resolution.items.map(visit) };
}

function contextFor(
    side: 'host' | 'module',
    module: string | undefined,
    page: BedrockPage,
    signal: AbortSignal,
    notify: NavigationNotifier,
): NavigationContext {
    const viewer = page.props.__bedrock?.viewer ?? null;

    return {
        side,
        ...(module ? { module } : {}),
        page: { component: page.component, url: page.url, props: page.props },
        viewer,
        panel: viewer?.panel ?? 'default',
        signal,
        can(permission) {
            const name =
                side === 'module' && module && !permission.includes('.')
                    ? `${module}.${permission}`
                    : permission;

            return viewer?.permissions.includes(name) ?? false;
        },
        notify,
    };
}

/**
 * Reject when a promise settles later than the deadline so a resolver that
 * ignores its abort signal cannot stall navigation preparation.
 */
function withTimeout<T>(
    promise: Promise<T>,
    milliseconds: number,
    message: string,
    signal: AbortSignal,
): Promise<T> {
    return new Promise<T>((resolve, reject) => {
        let timer: ReturnType<typeof setTimeout>;
        const finish = (callback: () => void) => {
            clearTimeout(timer);
            signal.removeEventListener('abort', aborted);
            callback();
        };
        const aborted = () =>
            finish(() =>
                reject(
                    signal.reason instanceof Error
                        ? signal.reason
                        : new Error('Navigation resolution was aborted.'),
                ),
            );
        timer = setTimeout(() => finish(() => reject(new Error(message))), milliseconds);

        signal.addEventListener('abort', aborted, { once: true });

        if (signal.aborted) return aborted();

        promise.then(
            (value) => finish(() => resolve(value)),
            (error) =>
                finish(() => reject(error instanceof Error ? error : new Error(String(error)))),
        );
    });
}

function staticDefinitions(value: NavigationDefinitionValue): NavigationResolution {
    const convert = (
        item: NavigationItemDefinition,
        parent?: NavigationItemDefinition,
    ): ResolvedNavigationItem => ({
        key: item.key,
        kind: item.kind,
        label: item.label,
        ...(item.kind === 'link' && item.route ? { route: item.route } : {}),
        ...(item.kind === 'link' && item.href
            ? { href: typeof item.href === 'string' ? item.href : item.href.url }
            : {}),
        ...((item.group ?? parent?.group) ? { group: item.group ?? parent?.group } : {}),
        ...((item.permission ?? parent?.permission)
            ? { permission: item.permission ?? parent?.permission }
            : {}),
        panels: item.panels ?? parent?.panels ?? [],
        active: false,
        order: item.order ?? 0,
        ...(parent ? { parent: parent.key } : item.parent ? { parent: item.parent } : {}),
        icon: item.icon,
        visible: true,
        disabled: false,
        loading: false,
        children: (item.children ?? []).map((child) => convert(child, item)),
    });

    return {
        groups: (value.groups ?? []).map((group) => ({ ...group, order: group.order ?? 0 })),
        items: value.items.map((item) => convert(item)),
    };
}

function filterHost(
    resolution: NavigationResolution,
    context: NavigationContext,
): NavigationResolution {
    const filter = (item: ResolvedNavigationItem): ResolvedNavigationItem | null => {
        if (item.panels.length > 0 && !item.panels.includes(context.panel)) return null;
        if (item.permission && !context.can(item.permission)) return null;

        return {
            ...item,
            children: item.children
                .map(filter)
                .filter((child): child is ResolvedNavigationItem => child !== null),
        };
    };

    return {
        ...resolution,
        items: resolution.items
            .map(filter)
            .filter((item): item is ResolvedNavigationItem => item !== null),
    };
}

function enforceEnvelope(
    module: string,
    envelope: BedrockNavigationItem[],
    dynamic: NavigationResolution,
    groups: NavigationResolution['groups'],
): NavigationResolution {
    const dynamicItems = new Map(flatten(dynamic.items).map((item) => [item.key, item]));
    const convert = (item: BedrockNavigationItem): ResolvedNavigationItem => {
        const presentation = dynamicItems.get(item.key);

        return {
            module,
            key: item.key,
            kind: item.kind,
            label: presentation?.label ?? item.label,
            ...(item.route ? { route: item.route } : {}),
            ...(item.href ? { href: item.href } : {}),
            ...(item.group ? { group: item.group } : {}),
            ...(item.permission ? { permission: item.permission } : {}),
            panels: item.panels,
            active: item.active,
            order: item.order,
            ...(item.parent ? { parent: item.parent } : {}),
            icon: presentation?.icon,
            badge: presentation?.badge,
            visible: presentation?.visible ?? true,
            disabled: presentation?.disabled ?? false,
            loading: false,
            children: item.children.map(convert),
        };
    };

    return { groups, items: envelope.map(convert) };
}

function staticResolution(
    module: string,
    envelope: BedrockNavigationItem[],
    groups: NavigationResolution['groups'] = [],
): NavigationResolution {
    const convert = (item: BedrockNavigationItem): ResolvedNavigationItem => ({
        module,
        key: item.key,
        kind: item.kind,
        label: item.label,
        ...(item.route ? { route: item.route } : {}),
        ...(item.href ? { href: item.href } : {}),
        ...(item.group ? { group: item.group } : {}),
        ...(item.permission ? { permission: item.permission } : {}),
        panels: item.panels,
        active: item.active,
        order: item.order,
        ...(item.parent ? { parent: item.parent } : {}),
        visible: true,
        disabled: false,
        loading: false,
        children: item.children.map(convert),
    });

    return { groups, items: envelope.map(convert) };
}

function staticFallback(
    envelope: BedrockNavigationItem[],
    modules: Record<string, BedrockFrontendModule>,
): NavigationResolution {
    const byModule = new Map<string, BedrockNavigationItem[]>();

    for (const item of envelope) {
        const entries = byModule.get(item.module) ?? [];

        entries.push(item);
        byModule.set(item.module, entries);
    }

    const items: ResolvedNavigationItem[] = [];
    const groups: NavigationResolution['groups'] = [];

    for (const [module, entries] of byModule) {
        const resolved = staticResolution(
            module,
            entries,
            modules[module]?.navigation.groups ?? [],
        );
        items.push(...resolved.items);
        groups.push(...resolved.groups);
    }

    return merge([{ groups, items }]);
}

function merge(resolutions: NavigationResolution[]): NavigationResolution {
    const groups = new Map(
        resolutions.flatMap((resolution) => resolution.groups).map((group) => [group.key, group]),
    );

    return {
        groups: [...groups.values()].sort(
            (left, right) => left.order - right.order || left.label.localeCompare(right.label),
        ),
        items: resolutions
            .flatMap((resolution) => resolution.items)
            .sort(
                (left, right) => left.order - right.order || left.label.localeCompare(right.label),
            ),
    };
}

function bindActions(
    actions: Map<string, BoundAction>,
    items: ResolvedNavigationItem[],
    runtime: NavigationRuntime,
    context: NavigationContext,
    namespace: string,
): void {
    for (const item of flatten(items)) {
        if (item.kind === 'action')
            actions.set(`${namespace}:${item.key}`, { runtime, context, key: item.key });
    }
}

function flatten(items: ResolvedNavigationItem[]): ResolvedNavigationItem[] {
    return items.flatMap((item) => [item, ...flatten(item.children ?? [])]);
}

function serializable(resolution: NavigationResolution): NavigationResolution {
    const item = (value: ResolvedNavigationItem): ResolvedNavigationItem => {
        const { icon: _icon, ...safe } = value;

        return { ...safe, children: value.children.map(item) };
    };

    return JSON.parse(
        JSON.stringify({
            groups: resolution.groups,
            items: resolution.items.map(item),
        }),
    ) as NavigationResolution;
}

function complete(page: BedrockPage, resolution: NavigationResolution): NavigationResolution {
    const shared = page.props.__bedrock;

    if (shared) {
        shared.resolvedNavigation = serializable(resolution);
        Object.defineProperty(shared, 'runtimeNavigation', {
            configurable: true,
            enumerable: false,
            value: resolution,
        });
    }

    return publish(resolution);
}

function publish(resolution: NavigationResolution): NavigationResolution {
    if (typeof window === 'undefined') return resolution;

    store().resolution = resolution;
    emit();

    return resolution;
}

function updateLoading(key: string, loading: boolean): void {
    const visit = (item: ResolvedNavigationItem): ResolvedNavigationItem => ({
        ...item,
        loading: item.kind === 'action' && key.endsWith(`:${item.key}`) ? loading : item.loading,
        children: item.children.map(visit),
    });

    store().resolution = { ...store().resolution, items: store().resolution.items.map(visit) };
}

function createNotifier(overrides?: Partial<NavigationNotifier>): NavigationNotifier {
    const dispatch = (type: 'success' | 'error', message: string) => {
        if (typeof window !== 'undefined') {
            window.dispatchEvent(
                new CustomEvent('bedrock:navigation-notification', { detail: { type, message } }),
            );
        }
    };

    return {
        success: overrides?.success ?? ((message) => dispatch('success', message)),
        error: overrides?.error ?? ((message) => dispatch('error', message)),
    };
}

function emit(): void {
    const current = store();
    current.version++;
    current.listeners.forEach((listener) => listener());
}

function useRevision(): void {
    useSyncExternalStore(
        (listener) => {
            store().listeners.add(listener);
            return () => store().listeners.delete(listener);
        },
        () => store().version,
        () => store().version,
    );
}
