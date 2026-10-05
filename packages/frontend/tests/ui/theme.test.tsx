import { useDirection } from '@base-ui/react/direction-provider';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { beforeAll, describe, expect, it, vi } from 'vitest';
import { ThemeProvider, useDefaultTheme } from '../../src/ui/theme/index.js';

function ThemeProbe() {
    const direction = useDirection();
    const theme = useDefaultTheme();

    return createElement('span', {
        'data-direction': direction,
        'data-mode': theme.resolvedMode,
    });
}

describe('Bedrock theme foundation', () => {
    beforeAll(() => {
        vi.stubGlobal('matchMedia', vi.fn((query: string) => ({
            matches: false,
            media: query,
            onchange: null,
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
            addListener: vi.fn(),
            removeListener: vi.fn(),
            dispatchEvent: vi.fn(),
        })));
        window.matchMedia = globalThis.matchMedia;
    });

    it('provides Base UI with an explicit RTL direction', () => {
        const html = renderToStaticMarkup(
            createElement(
                ThemeProvider,
                { direction: 'rtl', mode: 'dark' },
                createElement(ThemeProbe),
            ),
        );

        expect(html).toContain('data-direction="rtl"');
        expect(html).toContain('data-mode="dark"');
    });

    it('ships pointer cursors for enabled interactive controls', () => {
        const css = readFileSync(path.join(process.cwd(), 'src/ui/theme/theme.css'), 'utf8');

        expect(css).toContain('button:not(:disabled)');
        expect(css).toContain("[role='button']:not(:disabled)");
        expect(css).toContain('cursor: pointer');
    });
});
