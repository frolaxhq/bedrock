import * as React from 'react';
import { Link } from '@inertiajs/react';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@frolax/bedrock/ui';
import { cn } from 'cn';
import { useNavigation } from '../../runtime/navigation.js';
import type { ResolvedNavigationItem } from '../../module/index.js';

export interface PanelBreadcrumbsProps extends React.ComponentProps<'nav'> {
    homeLabel?: string;
    url?: string;
}

/**
 * The default header breadcrumbs. The trail derives from the resolved
 * navigation: every active item from the group root down to the current page.
 * When no navigation item matches, the current URL segments are shown.
 */
export function PanelBreadcrumbs({
    className,
    homeLabel = 'Home',
    url = '/',
    ...props
}: PanelBreadcrumbsProps) {
    const navigation = useNavigation();
    const trail = activeTrail(navigation.items);
    const segments = trail.length === 0 ? segmentsFromUrl(url) : [];

    return (
        <Breadcrumb aria-label="Breadcrumb" className={cn('min-w-0 text-sm', className)} {...props}>
            <BreadcrumbList>
                <BreadcrumbItem>
                    {trail.length === 0 && segments.length === 0 ? (
                        <BreadcrumbPage>{homeLabel}</BreadcrumbPage>
                    ) : (
                        <BreadcrumbLink render={<Link href="/" />}>{homeLabel}</BreadcrumbLink>
                    )}
                </BreadcrumbItem>
                {trail.map((item, index) => (
                    <React.Fragment key={item.key}>
                        <BreadcrumbSeparator />
                        <BreadcrumbItem>
                            {index === trail.length - 1 || !item.href ? (
                                <BreadcrumbPage>{item.label}</BreadcrumbPage>
                            ) : (
                                <BreadcrumbLink render={<Link href={item.href} />}>
                                    {item.label}
                                </BreadcrumbLink>
                            )}
                        </BreadcrumbItem>
                    </React.Fragment>
                ))}
                {segments.map((segment, index) => (
                    <React.Fragment key={segment.href}>
                        <BreadcrumbSeparator />
                        <BreadcrumbItem>
                            {index === segments.length - 1 ? (
                                <BreadcrumbPage>{segment.label}</BreadcrumbPage>
                            ) : (
                                <BreadcrumbLink render={<Link href={segment.href} />}>
                                    {segment.label}
                                </BreadcrumbLink>
                            )}
                        </BreadcrumbItem>
                    </React.Fragment>
                ))}
            </BreadcrumbList>
        </Breadcrumb>
    );
}

/**
 * The chain of active items: each item that is itself active or contains an
 * active descendant, from the group root down to the current page.
 */
function activeTrail(items: ResolvedNavigationItem[]): ResolvedNavigationItem[] {
    for (const item of items) {
        if (!item.visible) continue;

        if (item.active) {
            return [item, ...activeTrail(item.children).filter((child) => child.active)];
        }

        const nested = activeTrail(item.children);

        if (nested.length > 0) return [item, ...nested];
    }

    return [];
}

function segmentsFromUrl(url: string): { label: string; href: string }[] {
    const parts = url.split('?')[0]!.split('/').filter(Boolean);

    return parts.map((part, index) => ({
        label: part.replace(/[-_]+/g, ' ').replace(/^\w/, (character) => character.toUpperCase()),
        href: `/${parts.slice(0, index + 1).join('/')}`,
    }));
}
