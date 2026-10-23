import * as React from 'react';
import { Link } from '@inertiajs/react';
import { IconChevronRight } from '@tabler/icons-react';
import {
    Badge,
    Button,
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
    Separator,
    Spinner,
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@frolax/bedrock/ui';
import { cn } from 'cn';
import { useNavigation, useNavigationAction } from '../../runtime/navigation.js';
import { usePanel } from '../../ui/panel/index.js';
import type { ResolvedNavigationItem } from '../../module/index.js';

export interface PanelNavigationProps extends React.ComponentProps<'nav'> {
    label?: string;
}

/**
 * The default sidebar navigation. Renders the resolved TypeScript
 * navigation of the host and every authorized module: grouped links,
 * promise-aware actions, badges and one level of nested children.
 */
export function PanelNavigation({ className, label = 'Primary', ...props }: PanelNavigationProps) {
    const navigation = useNavigation();
    const items = navigation.items.filter((item) => item.visible);

    return (
        <nav
            data-slot="panel-navigation"
            aria-label={label}
            className={cn('grid gap-1 p-3 text-sm', className)}
            {...props}
        >
            {items.map((item, index) => {
                const previous = index > 0 ? items[index - 1]! : undefined;
                const showGroup = item.group !== undefined && item.group !== previous?.group;
                const declared =
                    item.group === undefined
                        ? undefined
                        : navigation.groups.find((candidate) => candidate.key === item.group);
                const showSeparator =
                    index > 0 &&
                    (showGroup || (item.group === undefined && previous?.group !== undefined));

                return (
                    <React.Fragment key={item.key}>
                        {showSeparator && <Separator className="my-1" />}
                        {showGroup && (
                            <div
                                data-slot="panel-navigation-group"
                                className="px-2 pb-1 pt-3 text-xs font-medium tracking-wide text-muted-foreground first:pt-1"
                            >
                                <GroupLabel label={declared?.label ?? item.group!} />
                            </div>
                        )}
                        <PanelNavigationEntry item={item} />
                    </React.Fragment>
                );
            })}
        </nav>
    );
}

function GroupLabel({ label }: { label: string }) {
    const { sidebarCollapsed } = usePanel();

    return <span className={cn('truncate', sidebarCollapsed && 'sr-only')}>{label}</span>;
}

function PanelNavigationEntry({ item }: { item: ResolvedNavigationItem }) {
    if (item.children.length > 0) {
        return <PanelNavigationParent item={item} />;
    }

    if (item.kind === 'action') {
        return <PanelNavigationAction item={item} />;
    }

    return <PanelNavigationLink item={item} />;
}

function actionKey(item: ResolvedNavigationItem): string {
    return `${item.module ?? 'host'}:${item.key}`;
}

function PanelNavigationLink({ item }: { item: ResolvedNavigationItem }) {
    const { sidebarCollapsed, setMobileSidebarOpen } = usePanel();

    if (sidebarCollapsed && item.icon) {
        return (
            <CollapsedItem label={item.label} badge={item.badge}>
                <LinkBody item={item} onNavigate={() => setMobileSidebarOpen(false)} />
            </CollapsedItem>
        );
    }

    return (
        <LinkBody
            item={item}
            className={cn(!sidebarCollapsed && 'px-2')}
            onNavigate={() => setMobileSidebarOpen(false)}
        />
    );
}

function LinkBody({
    item,
    className,
    onNavigate,
}: {
    item: ResolvedNavigationItem;
    className?: string;
    onNavigate?: () => void;
}) {
    const active = item.active && item.href !== undefined;

    return (
        <Link
            href={item.href ?? '#'}
            aria-current={active ? 'page' : undefined}
            aria-disabled={item.disabled || !item.href ? true : undefined}
            data-active={active ? 'true' : undefined}
            className={cn(
                'flex min-h-9 items-center gap-3 rounded-lg px-2 py-1.5 outline-none transition-colors',
                'hover:bg-sidebar-accent hover:text-sidebar-accent-foreground',
                'focus-visible:ring-2 focus-visible:ring-sidebar-ring',
                'data-[active=true]:bg-sidebar-accent data-[active=true]:font-medium data-[active=true]:text-sidebar-accent-foreground',
                'aria-disabled:pointer-events-none aria-disabled:opacity-50',
                className,
            )}
            onClick={onNavigate}
        >
            <ItemContent item={item} />
        </Link>
    );
}

function PanelNavigationAction({ item }: { item: ResolvedNavigationItem }) {
    const { sidebarCollapsed, setMobileSidebarOpen } = usePanel();
    const key = actionKey(item);
    const { loading, run } = useNavigationAction(key);
    const activate = () => {
        void run().catch(() => {
            // The notifier already surfaced the failure; nothing to rethrow in the DOM event.
        });
        setMobileSidebarOpen(false);
    };

    if (sidebarCollapsed && item.icon) {
        return (
            <CollapsedItem label={item.label} badge={item.badge}>
                <ActionBody
                    item={item}
                    loading={loading}
                    disabled={item.disabled || loading}
                    onClick={activate}
                    className="px-2"
                />
            </CollapsedItem>
        );
    }

    return (
        <ActionBody
            item={item}
            loading={loading}
            disabled={item.disabled || loading}
            onClick={activate}
            className={cn(!sidebarCollapsed && 'px-2')}
        />
    );
}

function ActionBody({
    item,
    loading,
    disabled,
    onClick,
    className,
}: {
    item: ResolvedNavigationItem;
    loading: boolean;
    disabled: boolean;
    onClick: () => void;
    className?: string;
}) {
    return (
        <Button
            type="button"
            variant="ghost"
            aria-label={item.label}
            disabled={disabled}
            onClick={onClick}
            className={cn(
                'h-auto min-h-9 w-full justify-start gap-3 rounded-lg py-1.5 font-normal text-sidebar-foreground',
                'hover:bg-sidebar-accent hover:text-sidebar-accent-foreground',
                className,
            )}
        >
            <ItemContent item={item} loading={loading} />
        </Button>
    );
}

function PanelNavigationParent({ item }: { item: ResolvedNavigationItem }) {
    const { sidebarCollapsed, setMobileSidebarOpen } = usePanel();
    const childActive = item.active || item.children.some((child) => child.active);

    if (sidebarCollapsed) {
        const leaf = item.children.find((child) => child.href) ?? item.children[0];

        if (leaf) {
            return (
                <CollapsedItem label={item.label} badge={item.badge}>
                    <PanelNavigationEntry
                        item={{
                            ...leaf,
                            icon: item.icon ?? leaf.icon,
                            active: childActive && leaf.active,
                        }}
                    />
                </CollapsedItem>
            );
        }

        return <PanelNavigationAction item={{ ...item, children: [] }} />;
    }

    return (
        <Collapsible defaultOpen={childActive}>
            <CollapsibleTrigger
                data-active={item.active ? 'true' : undefined}
                className={cn(
                    'group/parent flex min-h-9 w-full items-center gap-3 rounded-lg px-2 py-1.5 text-left outline-none transition-colors',
                    'hover:bg-sidebar-accent hover:text-sidebar-accent-foreground',
                    'focus-visible:ring-2 focus-visible:ring-sidebar-ring',
                    'data-[active=true]:bg-sidebar-accent data-[active=true]:font-medium data-[active=true]:text-sidebar-accent-foreground',
                )}
            >
                <ItemContent
                    item={item}
                    trailing={
                        <IconChevronRight
                            aria-hidden="true"
                            className="ms-auto size-4 shrink-0 text-muted-foreground transition-transform group-data-[panel-open]/parent:rotate-90 rtl:rotate-180 rtl:group-data-[panel-open]/parent:-rotate-90"
                        />
                    }
                />
            </CollapsibleTrigger>
            <CollapsibleContent>
                <ul className="ms-4 grid gap-1 border-s border-sidebar-border py-1 ps-2">
                    {item.children
                        .filter((child) => child.visible)
                        .map((child) => (
                            <li key={child.key} className="grid">
                                {child.kind === 'action' ? (
                                    <PanelNavigationAction item={child} />
                                ) : (
                                    <PanelNavigationChildLink
                                        item={child}
                                        onNavigate={() => setMobileSidebarOpen(false)}
                                    />
                                )}
                            </li>
                        ))}
                </ul>
            </CollapsibleContent>
        </Collapsible>
    );
}

function PanelNavigationChildLink({
    item,
    onNavigate,
}: {
    item: ResolvedNavigationItem;
    onNavigate: () => void;
}) {
    const active = item.active && item.href !== undefined;

    return (
        <Link
            href={item.href ?? '#'}
            aria-current={active ? 'page' : undefined}
            data-active={active ? 'true' : undefined}
            className={cn(
                'flex min-h-8 items-center rounded-md px-2 py-1 text-sm text-muted-foreground outline-none transition-colors',
                'hover:bg-sidebar-accent hover:text-sidebar-accent-foreground',
                'focus-visible:ring-2 focus-visible:ring-sidebar-ring',
                'data-[active=true]:font-medium data-[active=true]:text-sidebar-accent-foreground',
            )}
            onClick={onNavigate}
        >
            <span className="truncate">{item.label}</span>
            {badgeFor(item)}
        </Link>
    );
}

function CollapsedItem({
    label,
    badge,
    children,
}: {
    label: string;
    badge?: string | number | null;
    children: React.ReactNode;
}) {
    return (
        <Tooltip>
            <TooltipTrigger render={children as React.ReactElement} />
            <TooltipContent side="inline-end" className="flex items-center gap-2">
                <span>{label}</span>
                {badge !== null && badge !== undefined && (
                    <span className="text-xs text-muted-foreground">{badge}</span>
                )}
            </TooltipContent>
        </Tooltip>
    );
}

function ItemContent({
    item,
    loading = false,
    trailing,
}: {
    item: ResolvedNavigationItem;
    loading?: boolean;
    trailing?: React.ReactNode;
}) {
    return (
        <>
            <span className="grid size-4 shrink-0 place-items-center">
                {loading ? (
                    <Spinner className="size-4" />
                ) : item.icon ? (
                    <item.icon aria-hidden="true" className="size-4 shrink-0" />
                ) : null}
            </span>
            <span className="min-w-0 flex-1 truncate">{item.label}</span>
            {badgeFor(item)}
            {trailing}
        </>
    );
}

function badgeFor(item: ResolvedNavigationItem): React.ReactNode {
    if (item.badge === null || item.badge === undefined || item.badge === '') return null;

    return (
        <Badge variant="secondary" className="shrink-0 tabular-nums">
            {item.badge}
        </Badge>
    );
}
