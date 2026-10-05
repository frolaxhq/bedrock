import * as React from 'react';
import { Link, router } from '@inertiajs/react';
import { IconLogout, IconUser } from '@tabler/icons-react';
import {
    Avatar,
    AvatarFallback,
    AvatarImage,
    Button,
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@frolax/bedrock/ui';
import { cn } from 'cn';
import type { BedrockViewer } from '../../runtime/types.js';

export interface PanelUserMenuProps extends React.ComponentProps<typeof Button> {
    profileLabel?: string;
    logoutLabel?: string;
    viewer?: BedrockViewer | null;
}

/**
 * The default sidebar footer menu for the authenticated viewer. Profile and
 * logout actions come from the PHP viewer envelope; the profile action is
 * omitted when the host does not define its route. Renders nothing for
 * guests.
 */
export function PanelUserMenu({
    className,
    profileLabel = 'Profile',
    logoutLabel = 'Log out',
    viewer = null,
    ...props
}: PanelUserMenuProps) {
    if (!viewer) return null;

    const initials = viewer.name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part: string) => part[0]!.toUpperCase())
        .join('');

    return (
        <div data-slot="panel-user-menu" className="border-t border-sidebar-border p-3">
            <DropdownMenu>
                <DropdownMenuTrigger
                    render={
                        <Button
                            type="button"
                            variant="ghost"
                            aria-label={`Account menu for ${viewer.name}`}
                            className={cn('h-auto w-full justify-start gap-3 rounded-lg p-2 font-normal text-sidebar-foreground', className)}
                            {...props}
                        >
                            <Avatar size="sm">
                                {viewer.avatar && <AvatarImage src={viewer.avatar} alt="" />}
                                <AvatarFallback>{initials || <IconUser aria-hidden="true" />}</AvatarFallback>
                            </Avatar>
                            <span className="min-w-0 flex-1 text-start">
                                <span className="block truncate text-sm font-medium">{viewer.name}</span>
                                {viewer.email && <span className="block truncate text-xs text-muted-foreground">{viewer.email}</span>}
                            </span>
                        </Button>
                    }
                />
                <DropdownMenuContent align="start" side="top">
                    <DropdownMenuLabel>{viewer.name}</DropdownMenuLabel>
                    {viewer.email && <DropdownMenuLabel className="font-normal text-muted-foreground">{viewer.email}</DropdownMenuLabel>}
                    <DropdownMenuSeparator />
                    {viewer.actions.profile && (
                        <DropdownMenuItem render={<Link href={viewer.actions.profile} />}>
                            <IconUser aria-hidden="true" />
                            {profileLabel}
                        </DropdownMenuItem>
                    )}
                    {viewer.actions.logout && (
                        <DropdownMenuItem
                            onClick={() => {
                                router.post(viewer.actions.logout!);
                            }}
                        >
                            <IconLogout aria-hidden="true" />
                            {logoutLabel}
                        </DropdownMenuItem>
                    )}                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}
