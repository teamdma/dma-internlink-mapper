import { execFileSync } from 'node:child_process';
import { readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';

const root = new URL('../admin/js/', import.meta.url);
const directory = decodeURIComponent(root.pathname);
const files = readdirSync(directory)
  .map((name) => join(directory, name))
  .filter((file) => statSync(file).isFile() && file.endsWith('.js'))
  .sort();

for (const file of files) {
  execFileSync(process.execPath, ['--check', file], { stdio: 'inherit' });
}

console.log(`JavaScript syntax OK: ${files.length} files`);
