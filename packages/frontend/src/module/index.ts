import type { ComponentType } from 'react';

export type ModulePageImport = () => Promise<{ default: ComponentType<Record<string, unknown>> }>;
export type NavigationIcon = ComponentType<{
    className?: string;
    'aria-hidden'?: boolean | 'true' | 'false';
}>;
export type NavigationSide = 'host' | 'module';

export interface NavigationViewer {
    id: string | number;
    name: string;
    email: string | null;
    avatar: string | null;
    panel: string;
    permissions: string[];
    actions: {
        profile?: string;
        logout?: string;
    };
}

export interface NavigationNotifier {
    success(message: string): void;
    error(message: string): void;
}

export interface NavigationContext {
    side: NavigationSide;
    module?: string;
    page: { component?: string; url?: string; props: Record<string, unknown> };
    viewer: NavigationViewer | null;
    panel: string;
    signal: AbortSignal;
    can(permission: string): boolean;
    notify: NavigationNotifier;
}

export interface NavigationGroupDefinition {
    key: string;
    label: string;
    order?: number;
}

export interface NavigationPresentation {
    label?: string;
    icon?: NavigationIcon | null;
    badge?: string | number | null;
    visible?: boolean;
    disabled?: boolean;
    children?: NavigationChildPresentation[];
}

export interface NavigationChildPresentation extends NavigationPresentation {
    key: string;
}

interface NavigationBaseDefinition {
    key: string;
    label: string;
    group?: string;
    icon?: NavigationIcon;
    permission?: string;
    panels?: string[];
    active?: string[];
    order?: number;
    parent?: string;
    children?: NavigationLinkDefinition[];
    resolve?: (
        context: NavigationContext,
    ) => NavigationPresentation | Promise<NavigationPresentation>;
}

export interface NavigationLinkDefinition extends NavigationBaseDefinition {
    kind: 'link';
    route?: string;
    href?: string | { url: string; method?: string };
}

export interface NavigationActionDefinition extends NavigationBaseDefinition {
    kind: 'action';
    run(context: NavigationContext): void | Promise<void>;
}

export type NavigationItemDefinition = NavigationLinkDefinition | NavigationActionDefinition;

export interface NavigationDefinitionValue {
    groups?: NavigationGroupDefinition[];
    items: NavigationItemDefinition[];
}

export type NavigationDefinitionSource =
    | NavigationDefinitionValue
    | NavigationItemDefinition[]
    | (() =>
          | NavigationDefinitionValue
          | NavigationItemDefinition[]
          | Promise<NavigationDefinitionValue | NavigationItemDefinition[]>);

export interface NavigationDefinition {
    readonly source: NavigationDefinitionSource;
}

export interface NavigationDescriptorGroup {
    key: string;
    label: string;
    order: number;
}

export interface NavigationDescriptorItem {
    key: string;
    kind: 'link' | 'action';
    label: string;
    route?: string;
    href?: string;
    group?: string;
    permission?: string;
    panels: string[];
    active: string[];
    order: number;
    parent?: string;
    children: NavigationDescriptorItem[];
}

export interface NavigationDescriptor {
    groups: NavigationDescriptorGroup[];
    items: NavigationDescriptorItem[];
}

export interface ResolvedNavigationItem extends Omit<
    NavigationDescriptorItem,
    'active' | 'children'
> {
    module?: string;
    icon?: NavigationIcon;
    badge?: string | number | null;
    active: boolean;
    visible: boolean;
    disabled: boolean;
    loading: boolean;
    children: ResolvedNavigationItem[];
}

export interface NavigationResolution {
    groups: NavigationDescriptorGroup[];
    items: ResolvedNavigationItem[];
}

export interface BedrockModuleDefinition {
    pages: Record<string, ModulePageImport>;
    navigation?: NavigationDefinition;
}

export function defineNavigation(source: NavigationDefinitionSource): NavigationDefinition {
    return { source };
}

export function navigationItem(
    definition: Omit<NavigationLinkDefinition, 'kind'>,
): NavigationLinkDefinition {
    return { ...definition, kind: 'link' };
}

export function navigationAction(
    definition: Omit<NavigationActionDefinition, 'kind'>,
): NavigationActionDefinition {
    return { ...definition, kind: 'action' };
}

export function defineBedrockModule(definition: BedrockModuleDefinition) {
    const navigation = definition.navigation ?? defineNavigation({ items: [] });

    return {
        async resolvePage(this: void, name: string) {
            const normalized = `./pages/${name.replace(/^\/+/, '')}.tsx`;
            const page = definition.pages[normalized];

            if (!page) {
                throw new Error(`Module page [${name}] was not found.`);
            }

            return page();
        },

        async describeNavigation(this: void): Promise<NavigationDescriptor> {
            const value = await materialize(navigation);

            validate(value);

            return {
                groups: (value.groups ?? []).map((group) => ({
                    ...group,
                    order: group.order ?? 0,
                })),
                items: value.items.map((item) => describeItem(item)),
            };
        },

        materializeNavigation(this: void): Promise<NavigationDefinitionValue> {
            return materializeNavigation(navigation);
        },

        async resolveNavigation(
            this: void,
            context: NavigationContext,
        ): Promise<NavigationResolution> {
            const value = await materialize(navigation);

            validate(value);

            return {
                groups: (value.groups ?? []).map((group) => ({
                    ...group,
                    order: group.order ?? 0,
                })),
                items: await Promise.all(value.items.map((item) => resolveItem(item, context))),
            };
        },

        async runNavigationAction(
            this: void,
            key: string,
            context: NavigationContext,
        ): Promise<void> {
            const value = await materialize(navigation);
            const action = flatten(value.items).find(
                (item): item is NavigationActionDefinition =>
                    item.key === key && item.kind === 'action',
            );

            if (!action) {
                throw new Error(`Navigation action [${key}] was not found.`);
            }

            await action.run(context);
        },
    };
}

async function materialize(definition: NavigationDefinition): Promise<NavigationDefinitionValue> {
    const source =
        typeof definition.source === 'function' ? await definition.source() : definition.source;

    return Array.isArray(source) ? { items: source } : source;
}

/**
 * Materialize and validate a navigation definition without running any
 * dynamic resolvers. Used to rebind icons and actions on the client from an
 * SSR resolution snapshot.
 */
export async function materializeNavigation(
    definition: NavigationDefinition,
): Promise<NavigationDefinitionValue> {
    const value = await materialize(definition);

    validate(value);

    return value;
}

function describeItem(
    item: NavigationItemDefinition,
    parent?: NavigationItemDefinition,
): NavigationDescriptorItem {
    const effective = parent && item.kind === 'link' ? inheritChild(item, parent) : item;

    return {
        key: effective.key,
        kind: effective.kind,
        label: effective.label,
        ...(effective.kind === 'link' && effective.route ? { route: effective.route } : {}),
        ...(effective.kind === 'link' && effective.href ? { href: hrefOf(effective.href) } : {}),
        ...(effective.group ? { group: effective.group } : {}),
        ...(effective.permission ? { permission: effective.permission } : {}),
        panels: effective.panels ?? [],
        active: effective.active ?? [],
        order: effective.order ?? 0,
        ...(effective.parent ? { parent: effective.parent } : {}),
        children: (effective.children ?? []).map((child) => describeItem(child, effective)),
    };
}

async function resolveItem(
    item: NavigationItemDefinition,
    context: NavigationContext,
): Promise<ResolvedNavigationItem> {
    const presentation = item.resolve ? await item.resolve(context) : {};
    const childPresentations = new Map(
        (presentation.children ?? []).map((child) => [child.key, child]),
    );
    const children = await Promise.all(
        (item.children ?? []).map(async (child) => {
            const resolved = await resolveItem(inheritChild(child, item), context);
            const patch = childPresentations.get(child.key);

            return patch ? mergePresentation(resolved, patch) : resolved;
        }),
    );

    return mergePresentation(
        {
            ...describeItem(item),
            icon: item.icon,
            active: isActiveUrl(item.active, context.page.url),
            visible: true,
            disabled: false,
            loading: false,
            children,
        },
        presentation,
    );
}

function mergePresentation(
    item: ResolvedNavigationItem,
    patch: NavigationPresentation,
): ResolvedNavigationItem {
    return {
        ...item,
        ...(patch.label !== undefined ? { label: patch.label } : {}),
        ...(patch.icon !== undefined ? { icon: patch.icon ?? undefined } : {}),
        ...(patch.badge !== undefined ? { badge: patch.badge } : {}),
        ...(patch.visible !== undefined ? { visible: patch.visible } : {}),
        ...(patch.disabled !== undefined ? { disabled: patch.disabled } : {}),
    };
}

function inheritChild(
    child: NavigationLinkDefinition,
    parent: NavigationItemDefinition,
): NavigationLinkDefinition {
    return {
        ...child,
        group: child.group ?? parent.group,
        panels: child.panels ?? parent.panels,
        permission: child.permission ?? parent.permission,
        parent: parent.key,
    };
}

function flatten(items: NavigationItemDefinition[]): NavigationItemDefinition[] {
    return items.flatMap((item) => [item, ...(item.children ?? [])]);
}

function validate(value: NavigationDefinitionValue): void {
    const groups = new Set<string>();
    const items = new Set<string>();

    for (const group of value.groups ?? []) {
        assertKey(group.key, 'group');
        if (!group.label.trim())
            throw new Error(`Navigation group [${group.key}] must have a label.`);
        if (groups.has(group.key)) throw new Error(`Duplicate navigation group [${group.key}].`);
        groups.add(group.key);
    }

    const visit = (item: NavigationItemDefinition, nested = false) => {
        assertKey(item.key, 'item');
        if (!item.label.trim()) throw new Error(`Navigation item [${item.key}] must have a label.`);
        if (items.has(item.key)) throw new Error(`Duplicate navigation item [${item.key}].`);
        items.add(item.key);

        if (item.kind === 'link' && Boolean(item.route) === Boolean(item.href)) {
            throw new Error(`Navigation link [${item.key}] must define exactly one route or href.`);
        }

        if (item.group && !groups.has(item.group))
            throw new Error(`Navigation item [${item.key}] uses unknown group [${item.group}].`);
        for (const panel of item.panels ?? []) assertKey(panel, 'panel');
        if (nested && (item.children?.length ?? 0) > 0)
            throw new Error(`Navigation item [${item.key}] exceeds one level of nesting.`);
        item.children?.forEach((child) => visit(child, true));
    };

    value.items.forEach((item) => visit(item));

    for (const item of value.items) {
        if (item.parent && !items.has(item.parent))
            throw new Error(`Navigation item [${item.key}] has unknown parent [${item.parent}].`);
    }
}

function assertKey(key: string, subject: string): void {
    if (!/^[a-z][a-z0-9-]*$/.test(key)) {
        throw new Error(`Navigation ${subject} [${key}] must use lowercase kebab-case.`);
    }
}

/**
 * Match active patterns against the current page path using `*` wildcards.
 * Leading slashes are ignored on both sides so patterns stay readable.
 */
function isActiveUrl(patterns: string[] | undefined, url: string | undefined): boolean {
    if (!patterns || patterns.length === 0 || !url) return false;

    const path = url.split('?')[0]!.replace(/^\//, '');

    return patterns.some((pattern) => {
        const source = pattern
            .replace(/^\//, '')
            .replace(/[.+?^${}()|[\]\\]/g, '\\$&')
            .replace(/\*/g, '.*');

        return new RegExp(`^${source}$`).test(path);
    });
}

function hrefOf(href: string | { url: string }): string {
    return typeof href === 'string' ? href : href.url;
}
