/** Lists the heaviest images under public/images so the compression pass has a target list. */
import fs from 'node:fs';
import path from 'node:path';

const files = [];
const walk = (dir) => {
  for (const name of fs.readdirSync(dir)) {
    const full = path.join(dir, name);
    const stat = fs.statSync(full);
    if (stat.isDirectory()) walk(full);
    else files.push({ file: full.split(path.sep).join('/'), size: stat.size });
  }
};
walk('public/images');
files.sort((a, b) => b.size - a.size);

const total = files.reduce((sum, f) => sum + f.size, 0);
const big = files.filter((f) => f.size > 150 * 1024);
const byExt = {};
for (const f of files) {
  const ext = path.extname(f.file).toLowerCase();
  byExt[ext] = (byExt[ext] ?? 0) + 1;
}

console.log(`${files.length} files, ${(total / 1048576).toFixed(1)} MB total`);
console.log(`over 150 KB: ${big.length} files, ${(big.reduce((s, f) => s + f.size, 0) / 1048576).toFixed(1)} MB`);
console.log('by extension:', byExt);
console.log('\nheaviest 20:');
for (const f of files.slice(0, 20)) console.log(`${(f.size / 1024).toFixed(0).padStart(5)} KB  ${f.file}`);
