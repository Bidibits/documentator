// Checks for the file viewer's plain functions (the CSV reader and the "what can be shown" lookup).
// Run with: npm run test:js   (needs only Node, no packages)
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '../..');
const source = readFileSync(join(root, 'resources/app/js/08-viewer.js'), 'utf8');
// the page's scripts share one scope, so load this one the same way and take out what is tested
const { parseCsv, previewKind } = new Function(`${source}\nreturn { parseCsv, previewKind };`)();

test('a plain CSV', () => {
  assert.deepEqual(parseCsv('a,b\n1,2\n'), [['a', 'b'], ['1', '2']]);
});

test('commas inside quotes stay in the field, and CRLF line ends work', () => {
  assert.deepEqual(parseCsv('a,b\r\n1,"x,y"\r\n'), [['a', 'b'], ['1', 'x,y']]);
});

test('a line break inside quotes stays in the field', () => {
  assert.deepEqual(parseCsv('a\n"line1\nline2"\n'), [['a'], ['line1\nline2']]);
});

test('a doubled quote is one quote, and the last line needs no line end', () => {
  assert.deepEqual(parseCsv('a,b\n"say ""hi""",2'), [['a', 'b'], ['say "hi"', '2']]);
});

test('empty fields are kept', () => {
  assert.deepEqual(parseCsv('a,,c\n'), [['a', '', 'c']]);
});

test('it stops reading after the row limit instead of parsing a huge file', () => {
  const rows = parseCsv(Array.from({ length: 5000 }, (_, i) => `r${i},x`).join('\n'));
  assert.ok(rows.length <= 1001, `read ${rows.length} rows`);
});

test('PDF, pictures, text, CSV, .docx and .xlsx can be viewed; the older Office files only downloaded', () => {
  const kinds = ['x.PDF', 'y.png', 'z.csv', 'w.docx', 'v.xlsx', 'u.pptx', 'o.doc', 'p.XLS', 'noext', 'q.JPeG', 't.txt'].map(previewKind);
  assert.deepEqual(kinds, ['pdf', 'image', 'csv', 'docx', 'xlsx', null, null, null, null, 'image', 'text']);
});
