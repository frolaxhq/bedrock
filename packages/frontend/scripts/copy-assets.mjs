import { copyFile, mkdir } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';

const destination = resolve(import.meta.dirname, '../dist/ui/theme/theme.css');

await mkdir(dirname(destination), { recursive: true });
await copyFile(resolve(import.meta.dirname, '../src/ui/theme/theme.css'), destination);
