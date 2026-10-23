import {
    createContext,
    createElement,
    useContext,
    useEffect,
    useMemo,
    useState,
    type ReactNode,
} from 'react';
import { DirectionProvider, type TextDirection } from '@base-ui/react/direction-provider';
import { ThemeProvider as NextThemeProvider } from 'next-themes';
import { resolveFrontendExport } from '../runtime.js';

export type ThemeMode = 'light' | 'dark' | 'system';

export type ThemeToken =
    | 'background'
    | 'foreground'
    | 'card'
    | 'card-foreground'
    | 'popover'
    | 'popover-foreground'
    | 'primary'
    | 'primary-foreground'
    | 'secondary'
    | 'secondary-foreground'
    | 'muted'
    | 'muted-foreground'
    | 'accent'
    | 'accent-foreground'
    | 'destructive'
    | 'border'
    | 'input'
    | 'ring'
    | 'radius'
    | 'sidebar'
    | 'sidebar-foreground'
    | 'sidebar-primary'
    | 'sidebar-primary-foreground'
    | 'sidebar-accent'
    | 'sidebar-accent-foreground'
    | 'sidebar-border'
    | 'sidebar-ring'
    | 'chart-1'
    | 'chart-2'
    | 'chart-3'
    | 'chart-4'
    | 'chart-5'
    | 'font-sans'
    | 'font-mono';

export type ThemeTokens = Partial<Record<ThemeToken, string>>;

export interface BedrockTheme {
    name: string;
    light: ThemeTokens;
    dark: ThemeTokens;
}

export interface ThemeContextValue {
    direction: TextDirection;
    mode: ThemeMode;
    resolvedMode: Exclude<ThemeMode, 'system'>;
    setDirection: (direction: TextDirection) => void;
    setMode: (mode: ThemeMode) => void;
    theme?: BedrockTheme;
}

export interface ThemeProviderProps {
    children?: ReactNode;
    defaultMode?: ThemeMode;
    defaultDirection?: TextDirection;
    direction?: TextDirection;
    mode?: ThemeMode;
    onModeChange?: (mode: ThemeMode) => void;
    storageKey?: string;
    theme?: BedrockTheme;
}

const ThemeContext = createContext<ThemeContextValue | null>(null);

export function defineTheme(theme: BedrockTheme): BedrockTheme {
    return theme;
}

export function ThemeProvider({
    children,
    defaultMode = 'system',
    defaultDirection = 'ltr',
    direction: controlledDirection,
    mode: controlledMode,
    onModeChange,
    storageKey = 'bedrock.theme.mode',
    theme,
}: ThemeProviderProps) {
    const [storedMode, setStoredMode] = useState<ThemeMode>(
        () => readMode(storageKey) ?? defaultMode,
    );
    const [storedDirection, setStoredDirection] = useState<TextDirection>(
        () => readDirection() ?? defaultDirection,
    );
    const [systemMode, setSystemMode] = useState<'light' | 'dark'>(() => preferredMode());
    const mode = controlledMode ?? storedMode;
    const direction = controlledDirection ?? storedDirection;
    const resolvedMode = mode === 'system' ? systemMode : mode;

    useEffect(() => {
        if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return;

        const media = window.matchMedia('(prefers-color-scheme: dark)');
        const update = () => setSystemMode(media.matches ? 'dark' : 'light');
        update();
        media.addEventListener('change', update);

        return () => media.removeEventListener('change', update);
    }, []);

    useEffect(() => {
        if (typeof document === 'undefined') return;

        const root = document.documentElement;
        root.classList.toggle('dark', resolvedMode === 'dark');
        root.dir = direction;
        root.dataset.theme = theme?.name ?? 'bedrock';
        applyTokens(root, resolvedMode === 'dark' ? theme?.dark : theme?.light);
    }, [direction, resolvedMode, theme]);

    useEffect(() => {
        const stored = readStoredDirection();

        if (stored && stored !== direction && controlledDirection === undefined) {
            setStoredDirection(stored);
        }
        // Runs once after hydration so a persisted preference can win over the
        // server-rendered direction without a hydration mismatch.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const setMode = (next: ThemeMode) => {
        if (controlledMode === undefined) setStoredMode(next);
        if (typeof window !== 'undefined') window.localStorage.setItem(storageKey, next);
        onModeChange?.(next);
    };
    const setDirection = (next: TextDirection) => {
        if (controlledDirection === undefined) setStoredDirection(next);
        if (typeof window !== 'undefined') window.localStorage.setItem(directionKey, next);
    };
    const value = useMemo<ThemeContextValue>(
        () => ({ direction, mode, resolvedMode, setDirection, setMode, theme }),
        [direction, mode, resolvedMode, theme],
    );

    return createElement(
        DirectionProvider,
        { direction },
        createElement(
            NextThemeProvider,
            { attribute: 'class', forcedTheme: resolvedMode },
            createElement(ThemeContext.Provider, { value }, children),
        ),
    );
}

export function useDefaultTheme(): ThemeContextValue {
    const context = useContext(ThemeContext);

    if (!context) {
        throw new Error('useTheme must be used inside ThemeProvider.');
    }

    return context;
}

export function useTheme(): ThemeContextValue {
    const hook = resolveFrontendExport('hooks/use-theme', 'useTheme', useDefaultTheme);

    return hook();
}

function readMode(storageKey: string): ThemeMode | undefined {
    if (typeof window === 'undefined') return undefined;

    const value = window.localStorage.getItem(storageKey);

    return value === 'light' || value === 'dark' || value === 'system' ? value : undefined;
}

const directionKey = 'bedrock.theme.direction';

function readStoredDirection(): TextDirection | undefined {
    if (typeof window === 'undefined') return undefined;

    const value = window.localStorage.getItem(directionKey);

    return value === 'rtl' || value === 'ltr' ? value : undefined;
}

function preferredMode(): 'light' | 'dark' {
    return typeof window !== 'undefined' &&
        typeof window.matchMedia === 'function' &&
        window.matchMedia('(prefers-color-scheme: dark)').matches
        ? 'dark'
        : 'light';
}

function readDirection(): TextDirection | undefined {
    if (typeof document === 'undefined') return undefined;

    return document.documentElement.dir === 'rtl'
        ? 'rtl'
        : document.documentElement.dir === 'ltr'
          ? 'ltr'
          : undefined;
}

function applyTokens(root: HTMLElement, tokens?: ThemeTokens): void {
    for (const [key, value] of Object.entries(tokens ?? {})) {
        root.style.setProperty(`--${key}`, value);
    }
}
