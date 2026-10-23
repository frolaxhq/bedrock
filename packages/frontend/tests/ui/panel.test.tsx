import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import {
    PanelProvider,
    PanelShell,
    PanelSidebarTrigger,
    PanelSlot,
    usePanel,
    type PanelShellProps,
} from '../../src/ui/panel/index.js';
import { registerFrontendPanels, resetFrontendPanels } from '../../src/ui/runtime.js';

function DefaultSidebar() {
    return <nav>Default navigation</nav>;
}

function DefaultFooter() {
    return <span>Default footer</span>;
}

function AdminFooter() {
    const panel = usePanel();

    return <span>{panel.panel} footer</span>;
}

function EmptyHeader() {
    return null;
}

function CustomShell({ children }: PanelShellProps) {
    return (
        <section data-custom-shell>
            <PanelSlot name="workspace-switcher" />
            {children}
        </section>
    );
}

describe('panel composition', () => {
    afterEach(() => {
        resetFrontendPanels();
        window.localStorage.clear();
        vi.unstubAllGlobals();
    });

    it('inherits default slots and replaces only the active panel regions', () => {
        registerFrontendPanels({
            default: {
                slots: {
                    header: EmptyHeader,
                    sidebar: DefaultSidebar,
                    'sidebar-footer': DefaultFooter,
                },
            },
            admin: { slots: { 'sidebar-footer': AdminFooter } },
        });

        const html = renderToStaticMarkup(
            <PanelProvider panel="admin">
                <PanelShell>Dashboard</PanelShell>
            </PanelProvider>,
        );

        expect(html).toContain('data-panel="admin"');
        expect(html).toContain('Default navigation');
        expect(html).toContain('admin footer');
        expect(html).not.toContain('Default footer');
    });

    it('falls unknown and invalid panel keys back to the default panel', () => {
        registerFrontendPanels({
            default: { slots: { header: EmptyHeader, sidebar: DefaultSidebar } },
        });

        const unknown = renderToStaticMarkup(
            <PanelProvider panel="vendor">
                <PanelShell>Unknown</PanelShell>
            </PanelProvider>,
        );
        const invalid = renderToStaticMarkup(
            <PanelProvider panel="../admin">
                <PanelShell>Invalid</PanelShell>
            </PanelProvider>,
        );

        expect(unknown).toContain('data-panel="default"');
        expect(invalid).toContain('data-panel="default"');
    });

    it('supports a complete panel shell and arbitrary custom slots', () => {
        registerFrontendPanels({
            default: { slots: { 'workspace-switcher': () => <span>Default workspace</span> } },
            admin: {
                shell: CustomShell,
                slots: { 'workspace-switcher': () => <span>Admin workspace</span> },
            },
        });

        const html = renderToStaticMarkup(
            <PanelProvider panel="admin">
                <PanelShell>Content</PanelShell>
            </PanelProvider>,
        );

        expect(html).toContain('data-custom-shell');
        expect(html).toContain('Admin workspace');
        expect(html).toContain('Content');
    });

    it('renders an SSR-stable sidebar state and restores the browser preference after hydration', async () => {
        registerFrontendPanels({
            default: { slots: { header: EmptyHeader, sidebar: DefaultSidebar } },
        });
        window.localStorage.setItem('bedrock:panel:default:sidebar-collapsed', 'true');

        const server = renderToStaticMarkup(
            <PanelProvider>
                <PanelShell>Content</PanelShell>
            </PanelProvider>,
        );

        expect(server).toContain('data-sidebar-collapsed="false"');

        const container = document.createElement('div');
        const root = createRoot(container);

        await act(async () => {
            root.render(
                <PanelProvider>
                    <PanelShell>Content</PanelShell>
                </PanelProvider>,
            );
        });

        expect(
            container
                .querySelector('[data-slot="panel-shell"]')
                ?.getAttribute('data-sidebar-collapsed'),
        ).toBe('true');

        await act(async () => root.unmount());
    });

    it('exposes an accessible trigger that controls desktop panel state', async () => {
        registerFrontendPanels({
            default: { slots: { header: EmptyHeader, sidebar: DefaultSidebar } },
        });
        const container = document.createElement('div');
        const root = createRoot(container);

        await act(async () => {
            root.render(
                <PanelProvider>
                    <PanelSidebarTrigger mode="desktop" />
                    <PanelShell>Content</PanelShell>
                </PanelProvider>,
            );
        });

        const trigger = container.querySelector('button');
        expect(trigger?.getAttribute('aria-controls')).toBe('bedrock-panel-sidebar');
        expect(trigger?.getAttribute('aria-label')).toBe('Collapse navigation');

        await act(async () => trigger?.dispatchEvent(new MouseEvent('click', { bubbles: true })));

        expect(
            container
                .querySelector('[data-slot="panel-shell"]')
                ?.getAttribute('data-sidebar-collapsed'),
        ).toBe('true');
        expect(trigger?.getAttribute('aria-label')).toBe('Expand navigation');
        expect(window.localStorage.getItem('bedrock:panel:default:sidebar-collapsed')).toBe('true');

        await act(async () => root.unmount());
    });
});
