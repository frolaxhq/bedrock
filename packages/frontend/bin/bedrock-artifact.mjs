#!/usr/bin/env node

import { createHash } from 'node:crypto';
import { existsSync, readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import { pathToFileURL } from 'node:url';

const root = process.cwd();
const manifest = JSON.parse(readFileSync(join(root, 'module.json'), 'utf8'));
const output = join(root, 'dist/bedrock');
const clientManifest = join(output, 'client/mf-manifest.json');
const serverEntry = join(output, 'server/entry.mjs');

if (!existsSync(clientManifest) || !existsSync(serverEntry)) {
    throw new Error(
        'The client federation manifest and SSR entry must be built before creating the artifact descriptor.',
    );
}

const runtime = await import(`${pathToFileURL(serverEntry).href}?bedrock-artifact=${Date.now()}`);

if (typeof runtime.describeNavigation !== 'function') {
    throw new Error('The SSR entry must export describeNavigation().');
}

const navigation = await runtime.describeNavigation();
const serializedNavigation = JSON.parse(JSON.stringify(navigation));

if (
    !serializedNavigation ||
    !Array.isArray(serializedNavigation.groups) ||
    !Array.isArray(serializedNavigation.items)
) {
    throw new Error('describeNavigation() returned an invalid static navigation descriptor.');
}

const files = {};

for (const area of ['client', 'server']) {
    for (const file of walk(join(output, area))) {
        const name = relative(output, file).replaceAll('\\', '/');

        if (!isRuntimeArtifact(name)) continue;

        files[name] = createHash('sha256').update(readFileSync(file)).digest('hex');
    }
}

function isRuntimeArtifact(name) {
    return (
        !name.startsWith('client/.vite/') &&
        !name.startsWith('client/@mf-types') &&
        name !== 'client/mf-stats.json'
    );
}

const css = Object.keys(files).filter(
    (file) => file.startsWith('client/') && file.endsWith('.css'),
);
const descriptor = {
    schema: 1,
    module: manifest.key,
    version: manifest.version,
    client: { manifest: 'client/mf-manifest.json', css },
    server: { entry: 'server/entry.mjs' },
    navigation: serializedNavigation,
    files,
};

writeFileSync(join(output, 'frontend.json'), `${JSON.stringify(descriptor, null, 2)}\n`);

function walk(directory) {
    return readdirSync(directory)
        .sort()
        .flatMap((name) => {
            const path = join(directory, name);
            return statSync(path).isDirectory() ? walk(path) : [path];
        });
}
