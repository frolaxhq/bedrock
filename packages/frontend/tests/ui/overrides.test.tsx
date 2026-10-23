import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { afterEach, describe, expect, it } from 'vite-plus/test';
import {
    createOverridableComponent,
    createOverridableFunction,
    registerFrontendOverrides,
    resetFrontendOverrides,
} from '../../src/ui/runtime.js';

function DefaultButton({ children }: { children?: React.ReactNode }) {
    return createElement('button', { 'data-source': 'default' }, children);
}

function HostButton({ children }: { children?: React.ReactNode }) {
    return createElement('button', { 'data-source': 'host' }, children);
}

describe('frontend overrides', () => {
    afterEach(() => resetFrontendOverrides());

    it('uses the package default until a matching host module is registered', () => {
        const Button = createOverridableComponent('components/ui/button', 'Button', DefaultButton);

        expect(renderToStaticMarkup(createElement(Button, null, 'Save'))).toContain(
            'data-source="default"',
        );

        registerFrontendOverrides({
            'components/ui/button': { Button: HostButton },
        });

        expect(renderToStaticMarkup(createElement(Button, null, 'Save'))).toContain(
            'data-source="host"',
        );
    });

    it('ignores exports registered under a different exact path', () => {
        const Button = createOverridableComponent('components/ui/button', 'Button', DefaultButton);
        registerFrontendOverrides({
            'components/button': { Button: HostButton },
        });

        expect(renderToStaticMarkup(createElement(Button, null, 'Save'))).toContain(
            'data-source="default"',
        );
    });

    it('preserves methods on callable exports while resolving overrides', () => {
        const fallback = Object.assign((message: string) => `default:${message}`, {
            success: (message: string) => `default-success:${message}`,
        });
        const replacement = Object.assign((message: string) => `host:${message}`, {
            success: (message: string) => `host-success:${message}`,
        });
        const notify = createOverridableFunction('components/ui/sonner', 'toast', fallback);

        expect(notify('Saved')).toBe('default:Saved');
        expect(notify.success('Saved')).toBe('default-success:Saved');

        registerFrontendOverrides({ 'components/ui/sonner': { toast: replacement } });

        expect(notify('Saved')).toBe('host:Saved');
        expect(notify.success('Saved')).toBe('host-success:Saved');
    });
});
