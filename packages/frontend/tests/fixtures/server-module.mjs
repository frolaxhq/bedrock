export function resolvePage(name) {
    return { default: () => `SSR:${name}` };
}

export function resolveNavigation() {
    return { groups: [], items: [] };
}

export function runNavigationAction() {}
