#!/usr/bin/env node
// Builds the Documentator app page from resources/app (the same way PMS builds Bench):
//
//   index.html   the page, with <!-- documentator:head --> and <!-- documentator:app --> where the code goes
//   head.js      runs before the first paint (theme)
//   css/*.css    the stylesheet, in file-name order
//   js/*.js      the app, in file-name order, run as one script in one scope
//   vendor/*.js  third-party readers (Word, Excel), copied as they are and loaded on demand by the viewer
//
// Output: public/build/documentator/{head,app}-<hash>.js and app-<hash>.css, and resources/app/build/index.html that
// links them (served by AppController). No inline script, so the page can run under a strict Content-Security-Policy.
import { createHash } from 'node:crypto';
import { copyFileSync, mkdirSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const src = join(root, 'resources/app');
const out = join(src, 'build');
const assets = join(root, 'public/build/documentator');

const read = file => readFileSync(file, 'utf8');
const concat = folder => readdirSync(join(src, folder)).filter(f => !f.startsWith('.')).sort()
  .map(f => read(join(src, folder, f))).join('');

const page = read(join(src, 'index.html'));
const head = read(join(src, 'head.js'));
const css = concat('css');
const js = '(function(){\n"use strict";\n\n' + concat('js') + '})();\n';

const hash = text => createHash('sha256').update(text).digest('hex').slice(0, 12);
const files = {
  [`head-${hash(head)}.js`]: head,
  [`app-${hash(css)}.css`]: css,
  [`app-${hash(js)}.js`]: js,
};
rmSync(assets, { recursive: true, force: true });
mkdirSync(assets, { recursive: true });
mkdirSync(out, { recursive: true });
for (const [name, text] of Object.entries(files)) writeFileSync(join(assets, name), text);
mkdirSync(join(assets, 'vendor'));
for (const name of readdirSync(join(src, 'vendor')).filter(f => f.endsWith('.js'))) copyFileSync(join(src, 'vendor', name), join(assets, 'vendor', name));

const [headJs, appCss, appJs] = Object.keys(files).map(name => `/build/documentator/${name}`);
const html = page
  .replace('<!-- documentator:head -->\n', () => `<script src="${headJs}"></script>\n<link rel="stylesheet" href="${appCss}">\n`)
  .replace('<!-- documentator:app -->\n', () => `<script src="${appJs}"></script>\n`);
if (html === page) throw new Error('index.html has no documentator:head / documentator:app markers');
writeFileSync(join(out, 'index.html'), html);

console.log(`built ${Object.keys(files).join(', ')} and resources/app/build/index.html`);
