import { Dialog as DialogPrimitive } from '@base-ui/react/dialog';
import {
    IconLayoutSidebarLeftCollapse,
    IconLayoutSidebarLeftExpand,
    IconMenu2,
    IconX,
} from '@tabler/icons-react';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
    type CSSProperties,
    type HTMLAttributes,
    type ReactNode,
} from 'react';
import { cn } from 'cn';
import { Button, PanelBrand, PanelHeader, PanelNavigation, PanelUserMenu, Toaster, toast } from '../generated.js';
import { useFrontendPanel, type FrontendPanelComponent } from '../runtime.js';
import type { BedrockNavigationItem } from '../../runtime/types.js';
import type { BedrockViewer } from '../../runtime/types.js';

export interface PanelContextValue {
    panel: string;
    requestedPanel: string;
    navigation: BedrockNavigationItem[];
    viewer: BedrockViewer | null;
    url: string;
    brand: string;
    sidebarCollapsed: boolean;
    mobileSidebarOpen: boolean;
    setSidebarCollapsed(collapsed: boolean): void;
    setMobileSidebarOpen(open: boolean): void;
    toggleSidebar(): void;
    hasSlot(name: string): boolean;
}

interface InternalPanelContextValue extends PanelContextValue {
    preferenceReady: boolean;
    shell?: FrontendPanelComponent;
    slots: Record<string, FrontendPanelComponent>;
}

export interface PanelProviderProps {
    children: ReactNode;
    panel?: string | null;
    navigation?: BedrockNavigationItem[];
    viewer?: BedrockViewer | null;
    url?: string;
    brand?: string;
}

export interface PanelShellProps extends HTMLAttributes<HTMLDivElement> {
    children: ReactNode;
}

export interface PanelSlotProps {
    name: string;
    fallback?: ReactNode;
}

export type PanelSidebarTriggerProps = React.ComponentProps<typeof Button> & {
    mode?: 'desktop' | 'mobile';
};

const PanelContext = createContext<InternalPanelContextValue | null>(null);

export function PanelProvider({
    children,
    panel = 'default',
    navigation = [],
    viewer = null,
    url = '/',
    brand = 'Bedrock',
}: PanelProviderProps) {
    const requestedPanel = validPanelKey(panel) ? panel : 'default';
    const resolved = useFrontendPanel(requestedPanel);
    const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
    const [mobileSidebarOpen, setMobileSidebarOpen] = useState(false);
    const [preferenceReady, setPreferenceReady] = useState(false);
    const storageKey = `bedrock:panel:${resolved.key}:sidebar-collapsed`;
    const previousPanel = useRef(resolved.key);

    useEffect(() => {
        setPreferenceReady(false);
        setSidebarCollapsed(readSidebarPreference(storageKey));
        setPreferenceReady(true);
    }, [storageKey]);

    useEffect(() => {
        if (previousPanel.current === resolved.key) return;

        previousPanel.current = resolved.key;
        setMobileSidebarOpen(false);
    }, [resolved.key]);

    useEffect(() => {
        if (preferenceReady) {
            writeSidebarPreference(storageKey, sidebarCollapsed);
        }
    }, [preferenceReady, sidebarCollapsed, storageKey]);

    const hasSlot = useCallback((name: string) => Boolean(resolved.slots[name]), [resolved.slots]);
    const toggleSidebar = useCallback(() => {
        if (typeof window.matchMedia === 'function' && window.matchMedia('(min-width: 768px)').matches) {
            setSidebarCollapsed((collapsed) => !collapsed);
        } else {
            setMobileSidebarOpen((open) => !open);
        }
    }, []);
    const value = useMemo<InternalPanelContextValue>(() => ({
        panel: resolved.key,
        requestedPanel,
        navigation,
        viewer,
        url,
        brand,
        sidebarCollapsed,
        mobileSidebarOpen,
        setSidebarCollapsed,
        setMobileSidebarOpen,
        toggleSidebar,
        hasSlot,
        preferenceReady,
        shell: resolved.shell,
        slots: resolved.slots,
    }), [
        hasSlot,
        mobileSidebarOpen,
        navigation,
        viewer,
        url,
        brand,
        preferenceReady,
        requestedPanel,
        resolved.key,
        resolved.shell,
        resolved.slots,
        sidebarCollapsed,
        toggleSidebar,
    ]);

    return (
        <PanelContext.Provider value={value}>
            <DialogPrimitive.Root open={mobileSidebarOpen} onOpenChange={setMobileSidebarOpen}>
                {children}
            </DialogPrimitive.Root>
        </PanelContext.Provider>
    );
}

export function usePanel(): PanelContextValue {
    return usePanelContext();
}

export function PanelSlot({ name, fallback = null }: PanelSlotProps) {
    const { slots } = usePanelContext();
    const Slot = slots[name];

    return Slot ? <Slot /> : <>{fallback}</>;
}

export function PanelShell(props: PanelShellProps) {
    const { shell: Shell } = usePanelContext();

    return Shell ? <Shell {...props} /> : <DefaultPanelShell {...props} />;
}

export function PanelSidebarTrigger({ className, disabled, id, mode = 'desktop', onClick, ...props }: PanelSidebarTriggerProps) {
    const panel = usePanelContext();
    const mobile = mode === 'mobile';
    const expanded = mobile ? panel.mobileSidebarOpen : !panel.sidebarCollapsed;
    const label = mobile
        ? (expanded ? 'Close navigation' : 'Open navigation')
        : (expanded ? 'Collapse navigation' : 'Expand navigation');

    const button = (
        <Button
            type="button"
            variant="ghost"
            size="icon"
            aria-label={label}
            aria-expanded={expanded}
            aria-controls={mobile ? 'bedrock-panel-mobile-sidebar' : 'bedrock-panel-sidebar'}
            id={id ?? (mobile ? 'bedrock-panel-mobile-sidebar-trigger' : undefined)}
            className={cn('shrink-0', className)}
            disabled={disabled || !panel.preferenceReady}
            onClick={mobile ? onClick : (event) => {
                onClick?.(event);

                if (!event.defaultPrevented) panel.setSidebarCollapsed(!panel.sidebarCollapsed);
            }}
            {...props}
        >
            {mobile
                ? <IconMenu2 aria-hidden="true" />
                : panel.sidebarCollapsed
                    ? <IconLayoutSidebarLeftExpand className="rtl:scale-x-[-1]" aria-hidden="true" />
                    : <IconLayoutSidebarLeftCollapse className="rtl:scale-x-[-1]" aria-hidden="true" />}
        </Button>
    );

    return mobile
        ? <DialogPrimitive.Trigger render={button} />
        : button;
}

function DefaultPanelShell({ children, className, style, ...props }: PanelShellProps) {
    const panel = usePanelContext();
    const sidebarWidth = panel.sidebarCollapsed ? '4.5rem' : '18rem';
    const shellStyle = {
        '--bedrock-panel-sidebar-width': sidebarWidth,
        ...style,
    } as CSSProperties;

    return (
        <div
            data-slot="panel-shell"
            data-panel={panel.panel}
            data-sidebar-collapsed={panel.sidebarCollapsed ? 'true' : 'false'}
            data-mobile-sidebar-open={panel.mobileSidebarOpen ? 'true' : 'false'}
            className={cn(
                'grid min-h-screen bg-background text-foreground transition-[grid-template-columns] duration-200',
                'md:grid-cols-[var(--bedrock-panel-sidebar-width)_minmax(0,1fr)]',
                className,
            )}
            style={shellStyle}
            {...props}
        >
            <DesktopSidebar />
            <div className="flex min-h-screen min-w-0 flex-col">
                <header data-slot="panel-header" className="flex min-h-14 items-center gap-2 border-b border-border px-3 md:px-4">
                    <PanelSidebarTrigger mode="mobile" className="md:hidden" />
                    <PanelSidebarTrigger mode="desktop" className="hidden md:inline-flex" />
                    <div className="min-w-0 flex-1"><PanelSlot name="header" fallback={<PanelHeader url={panel.url} />} /></div>
                </header>
                <main data-slot="panel-content" className="min-w-0 flex-1">{children}</main>
                {panel.hasSlot('footer') && (
                    <footer data-slot="panel-footer" className="border-t border-border">
                        <PanelSlot name="footer" />
                    </footer>
                )}
            </div>
            <MobileSidebar />
            <NavigationNotificationBridge />
            <Toaster />
        </div>
    );
}

function NavigationNotificationBridge() {
    useEffect(() => {
        const notify = (event: Event) => {
            const detail = (event as CustomEvent<{ type?: 'success' | 'error'; message?: string }>).detail;

            if (!detail?.message) return;

            if (detail.type === 'error') {
                toast.error(detail.message);
            } else {
                toast.success(detail.message);
            }
        };

        window.addEventListener('bedrock:navigation-notification', notify);

        return () => window.removeEventListener('bedrock:navigation-notification', notify);
    }, []);

    return null;
}

function DesktopSidebar() {
    return (
        <aside
            id="bedrock-panel-sidebar"
            data-slot="panel-sidebar"
            className="hidden min-h-screen min-w-0 flex-col overflow-hidden border-e border-sidebar-border bg-sidebar text-sidebar-foreground md:flex"
        >
            <PanelSidebarRegions />
        </aside>
    );
}

function MobileSidebar() {
    return (
        <DialogPrimitive.Portal>
            <DialogPrimitive.Backdrop className="fixed inset-0 z-50 bg-black/30 backdrop-blur-xs transition-opacity data-ending-style:opacity-0 data-starting-style:opacity-0 md:hidden" />
            <DialogPrimitive.Popup
                id="bedrock-panel-mobile-sidebar"
                data-slot="panel-mobile-sidebar"
                className="fixed inset-y-0 start-0 z-50 flex w-[min(18rem,85vw)] flex-col overflow-hidden border-e border-sidebar-border bg-sidebar text-sidebar-foreground shadow-xl transition-transform data-ending-style:-translate-x-full data-starting-style:-translate-x-full rtl:data-ending-style:translate-x-full rtl:data-starting-style:translate-x-full md:hidden"
            >
                <DialogPrimitive.Title className="sr-only">Navigation</DialogPrimitive.Title>
                <DialogPrimitive.Close
                    render={<Button type="button" variant="ghost" size="icon" className="absolute end-2 top-2" />}
                >
                    <IconX aria-hidden="true" />
                    <span className="sr-only">Dismiss navigation</span>
                </DialogPrimitive.Close>
                <PanelSidebarRegions />
            </DialogPrimitive.Popup>
        </DialogPrimitive.Portal>
    );
}

function PanelSidebarRegions() {
    const panel = usePanelContext();

    return (
        <>
            <div data-slot="panel-sidebar-header" className="shrink-0 border-b border-sidebar-border">
                <PanelSlot name="sidebar-header" fallback={<PanelBrand name={panel.brand} />} />
            </div>
            <div data-slot="panel-sidebar-content" className="min-h-0 flex-1 overflow-y-auto">
                <PanelSlot name="sidebar" fallback={<PanelNavigation />} />
            </div>
            {(panel.hasSlot('sidebar-footer') || panel.viewer) && (
                <div data-slot="panel-sidebar-footer" className="shrink-0 border-t border-sidebar-border">
                    <PanelSlot name="sidebar-footer" fallback={<PanelUserMenu viewer={panel.viewer} />} />
                </div>
            )}
        </>
    );
}

function usePanelContext(): InternalPanelContextValue {
    const panel = useContext(PanelContext);

    if (!panel) {
        throw new Error('Panel components must be rendered inside PanelProvider.');
    }

    return panel;
}

function validPanelKey(value: string | null | undefined): value is string {
    return typeof value === 'string' && /^[a-z][a-z0-9-]*$/.test(value);
}

function readSidebarPreference(key: string): boolean {
    try {
        return window.localStorage.getItem(key) === 'true';
    } catch {
        return false;
    }
}

function writeSidebarPreference(key: string, collapsed: boolean): void {
    try {
        window.localStorage.setItem(key, String(collapsed));
    } catch {
        // Browser storage is an optional enhancement; the panel remains usable without it.
    }
}
