import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { readFileSync, rmSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';

const packageRoot = resolve(import.meta.dirname, '..');
const repositoryRoot = resolve(packageRoot, '../..');
const artifactRoot = resolve(import.meta.dirname, '.artifacts');
const hostOutput = resolve(artifactRoot, 'host');
const moduleOutput = resolve(repositoryRoot, 'workbench/modules/example/dist');

rmSync(hostOutput, { recursive: true, force: true });
run('pnpm', ['exec', 'vp', 'build', '--config', 'e2e/host/vite.config.ts'], packageRoot);
const before = sha256(resolve(hostOutput, 'app.js'));

rmSync(moduleOutput, { recursive: true, force: true });
run('pnpm', ['exec', 'vp', 'run', '@modules/example#build'], repositoryRoot);
const after = sha256(resolve(hostOutput, 'app.js'));

writeFileSync(
    resolve(artifactRoot, 'build-order.json'),
    `${JSON.stringify({ before, after, unchanged: before === after }, null, 2)}\n`,
);

function run(command, args, cwd) {
    const result = spawnSync(command, args, { cwd, stdio: 'inherit' });

    if (result.status !== 0) process.exit(result.status ?? 1);
}

function sha256(file) {
    return createHash('sha256').update(readFileSync(file)).digest('hex');
}
