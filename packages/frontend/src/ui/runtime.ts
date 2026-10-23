import {
    createElement,
    forwardRef,
    useSyncExternalStore,
    type ComponentType,
    type ElementType,
} from 'react';

export type FrontendOverrideModule = Record<string, unknown>;
export type FrontendOverrideModules = Record<string, FrontendOverrideModule>;
export type FrontendPanelComponent = ComponentType<any>;

export interface FrontendPanelDefinition {
    shell?: FrontendPanelComponent;
    slots?: Record<string, FrontendPanelComponent>;
}

export type FrontendPanels = Record<string, FrontendPanelDefinition>;

export interface ResolvedFrontendPanel {
    key: string;
    shell?: FrontendPanelComponent;
    slots: Record<string, FrontendPanelComponent>;
}

interface OverrideStore {
    modules: Map<string, FrontendOverrideModule>;
    panels: Map<string, FrontendPanelDefinition>;
    listeners: Set<() => void>;
    version: number;
}

const storeKey = Symbol.for('frolax.bedrock.frontend-overrides');

function store(): OverrideStore {
    const target = globalThis as typeof globalThis & { [storeKey]?: OverrideStore };

    if (!target[storeKey]) {
        target[storeKey] = {
            modules: new Map(),
            panels: new Map(),
            listeners: new Set(),
            version: 0,
        };
    }

    return target[storeKey];
}

export function registerFrontendOverrides(modules: FrontendOverrideModules): void {
    const current = store();

    for (const [id, frontendModule] of Object.entries(modules)) {
        current.modules.set(id, frontendModule);
    }

    current.version++;
    current.listeners.forEach((listener) => listener());
}

export function resetFrontendOverrides(): void {
    const current = store();
    current.modules.clear();
    current.version++;
    current.listeners.forEach((listener) => listener());
}

export function registerFrontendPanels(panels: FrontendPanels): void {
    const current = store();
    current.panels.clear();

    for (const [key, panel] of Object.entries(panels)) {
        current.panels.set(key, panel);
    }

    current.version++;
    current.listeners.forEach((listener) => listener());
}

export function resetFrontendPanels(): void {
    const current = store();
    current.panels.clear();
    current.version++;
    current.listeners.forEach((listener) => listener());
}

export function resolveFrontendExport<T>(id: string, name: string, fallback: T): T {
    const candidate = store().modules.get(id)?.[name];

    return (candidate ?? fallback) as T;
}

export function useFrontendExport<T>(id: string, name: string, fallback: T): T {
    useFrontendRevision();

    return resolveFrontendExport(id, name, fallback);
}

export function resolveFrontendPanel(requested: string): ResolvedFrontendPanel {
    const current = store();
    const fallback = current.panels.get('default');
    const selected = requested === 'default' ? fallback : current.panels.get(requested);
    const key = requested === 'default' || selected ? requested : 'default';

    return {
        key,
        shell: selected?.shell ?? fallback?.shell,
        slots: {
            ...(fallback?.slots ?? {}),
            ...(selected?.slots ?? {}),
        },
    };
}

export function useFrontendPanel(requested: string): ResolvedFrontendPanel {
    useFrontendRevision();

    return resolveFrontendPanel(requested);
}

export function createOverridableComponent<T extends ElementType>(
    id: string,
    name: string,
    fallback: T,
): T {
    const Overridable = forwardRef<unknown, Record<string, unknown>>((props, ref) => {
        const Component = useFrontendExport(id, name, fallback) as ComponentType<
            Record<string, unknown>
        >;

        return createElement(Component, { ...props, ref });
    });

    Overridable.displayName = `Bedrock(${name})`;

    return Overridable as unknown as T;
}

export function createOverridableFunction<T extends (...args: never[]) => unknown>(
    id: string,
    name: string,
    fallback: T,
): T {
    const callable = ((...args: Parameters<T>): ReturnType<T> =>
        resolveFrontendExport(id, name, fallback)(...args) as ReturnType<T>) as T;

    return new Proxy(callable, {
        get(_target, property) {
            return Reflect.get(resolveFrontendExport(id, name, fallback), property);
        },
    });
}

function useFrontendRevision(): void {
    useSyncExternalStore(
        (listener) => {
            store().listeners.add(listener);

            return () => store().listeners.delete(listener);
        },
        () => store().version,
        () => store().version,
    );
}
