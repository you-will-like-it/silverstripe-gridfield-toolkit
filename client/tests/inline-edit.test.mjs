import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

const dom = new JSDOM('<!doctype html><body></body>', { pretendToBeVisual: true });
Object.assign(globalThis, {
  window: dom.window,
  document: dom.window.document,
  Element: dom.window.Element,
  Option: dom.window.Option,
  CustomEvent: dom.window.CustomEvent,
  AbortController: dom.window.AbortController,
  AbortSignal: dom.window.AbortSignal,
  CSS: { escape: (s) => String(s) },
});

const { mountInlineEdit } = await import('../src/js/features/inline-edit.js');

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const json = (status, body) => ({ ok: status < 400, status, json: async () => body });
const toggleHtml = (checked) => `<button type="button" class="ywli-toggle" role="switch" aria-checked="${checked}" data-ywli-toggle></button>`;
const valueHtml = (text, raw = text) => `<span class="ywli-value" tabindex="0" data-ywli-value="${raw}">${text}</span>`;

const CONFIG = {
  patchUrl: '/g/ywli/inline/patch',
  optionsUrl: '/g/ywli/inline/options',
  securityID: 'tok',
  conflictPauseMs: 30,
  editors: {
    Title: { type: 'text' },
    Qty: { type: 'number', min: 0, max: 99, allowNull: false },
    Status: { type: 'select', options: [{ value: 'a', label: 'Alpha' }, { value: 'b', label: 'Beta' }] },
    City: { type: 'select', dynamic: true, dependsOn: 'Country' },
  },
  strings: {},
};

const cell = (col, type, inner, etag) =>
  `<td data-ywli-column="${col}" data-ywli-editor="${type}" data-ywli-id="7" data-ywli-etag="${etag}" class="ywli-editable ywli-editable--${type}">${inner}</td>`;

function setup(etag = 'e0') {
  document.body.innerHTML = `
    <fieldset class="ss-gridfield" data-name="Grid"><table><tbody><tr>
      ${cell('Title', 'text', valueHtml('Foo'), etag)}
      ${cell('Qty', 'number', valueHtml('3'), etag)}
      ${cell('Status', 'select', valueHtml('Alpha', 'a'), etag)}
      ${cell('City', 'select', valueHtml('Graz', 'Graz'), etag)}
      ${cell('A', 'toggle', toggleHtml(false), etag)}
      ${cell('B', 'toggle', toggleHtml(false), etag)}
    </tr></tbody></table></fieldset>`;
  const root = document.querySelector('.ss-gridfield');
  const dispose = mountInlineEdit(root, CONFIG);
  const q = (col) => root.querySelector(`[data-ywli-column="${col}"]`);
  return { root, dispose, q };
}

const key = (el, k, init = {}) =>
  el.dispatchEvent(new dom.window.KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true, ...init }));
const dbl = (el) => el.dispatchEvent(new dom.window.MouseEvent('dblclick', { bubbles: true, cancelable: true }));
const ok = (extra = {}) => json(200, { ok: true, id: 7, etag: 'e1', previous: {}, changes: {}, undo: null, cells: {}, ...extra });

let calls;
beforeEach(() => {
  calls = [];
  document.body.innerHTML = '';
});
const mockFetch = (handler) => {
  globalThis.fetch = async (url, init = {}) => {
    calls.push({ url, init, body: init.body ? JSON.parse(init.body) : undefined });
    return handler(url, init, calls.length);
  };
};

test('toggle: optimistic flip, success swaps cells and refreshes every etag, emits saved', async () => {
  const { root, q } = setup();
  mockFetch(() => ok({ previous: { A: false }, changes: { A: true }, cells: { Title: valueHtml('Foo!'), A: toggleHtml(true) } }));
  let saved;
  root.addEventListener('ywli:cell:saved', (e) => { saved = e.detail; });

  const btn = q('A').querySelector('button');
  btn.click();
  assert.equal(btn.getAttribute('aria-checked'), 'true');
  assert.equal(btn.getAttribute('aria-busy'), 'true');
  await sleep(10);

  assert.equal(calls[0].init.headers['X-SecurityID'], 'tok');
  assert.deepEqual(calls[0].body, { id: 7, etag: 'e0', changes: { A: true } });
  assert.equal(q('Title').textContent, 'Foo!');
  assert.deepEqual([...root.querySelectorAll('[data-ywli-etag]')].map((c) => c.dataset.ywliEtag), Array(6).fill('e1'));
  assert.equal(saved.column, 'A');
});

test('toggle: control click is claimed (row never sees it), padding click is not', async () => {
  const { q } = setup();
  mockFetch(() => ok());
  let rowSaw = 0;
  document.addEventListener('click', () => { rowSaw += 1; });
  q('A').querySelector('button').click();
  await sleep(5);
  assert.equal(rowSaw, 0);
  q('B').click(); // td padding
  assert.equal(rowSaw, 1);
});

test('409: rolls back, toasts, waits, only then applies server cells', async () => {
  const { root, q } = setup();
  mockFetch(() => json(409, { ok: false, error: 'conflict', message: 'Conflict: x', id: 7, etag: 'e9',
    cells: { Title: valueHtml('Server'), A: toggleHtml(true) } }));
  const btn = q('A').querySelector('button');
  btn.click();
  await sleep(10);
  assert.equal(btn.getAttribute('aria-checked'), 'false');
  assert.ok(document.querySelector('.ywli-toast--error'));
  assert.equal(q('Title').textContent, 'Foo', 'row not reloaded during the pause');
  await sleep(60);
  assert.equal(q('Title').textContent, 'Server');
  assert.equal(root.querySelector('[data-ywli-etag]').dataset.ywliEtag, 'e9');
});

test('two toggles in one row are serialised; the second uses the refreshed etag', async () => {
  const { q } = setup();
  let n = 0;
  mockFetch(async () => { await sleep(15); n += 1; return ok({ etag: `e${n}` }); });
  q('A').querySelector('button').click();
  q('B').querySelector('button').click();
  await sleep(80);
  assert.deepEqual(calls.map((c) => c.body.etag), ['e0', 'e1']);
});

test('network failure: rollback, error toast, busy cleared', async () => {
  const { q } = setup();
  mockFetch(async () => { throw new TypeError('offline'); });
  const btn = q('A').querySelector('button');
  btn.click();
  await sleep(10);
  assert.equal(btn.getAttribute('aria-checked'), 'false');
  assert.equal(btn.hasAttribute('aria-busy'), false);
  assert.ok(document.querySelector('.ywli-toast--error'));
});

test('destroy removes listeners', async () => {
  const { q, dispose } = setup();
  mockFetch(() => ok());
  dispose();
  q('A').querySelector('button').click();
  dbl(q('Title').querySelector('.ywli-value'));
  await sleep(5);
  assert.equal(calls.length, 0);
  assert.equal(q('Title').querySelector('input'), null);
});

test('text: dblclick opens input; Enter commits; pending value shown; server cells replace it', async () => {
  const { root, q } = setup();
  let release;
  mockFetch(() => new Promise((resolve) => { release = () => resolve(ok({ cells: { Title: valueHtml('Bar') }, changes: { Title: 'Bar' }, previous: { Title: 'Foo' } })); }));

  dbl(q('Title').querySelector('.ywli-value'));
  const input = q('Title').querySelector('input.ywli-input');
  assert.ok(input);
  assert.equal(input.value, 'Foo');

  input.value = 'Bar';
  key(input, 'Enter');
  await sleep(5);
  assert.equal(q('Title').querySelector('input'), null);
  assert.equal(q('Title').textContent, 'Bar', 'pending text');
  assert.equal(q('Title').getAttribute('aria-busy'), 'true');
  assert.deepEqual(calls[0].body, { id: 7, etag: 'e0', changes: { Title: 'Bar' } });

  release();
  await sleep(10);
  assert.equal(q('Title').hasAttribute('aria-busy'), false);
  assert.equal(q('Title').querySelector('.ywli-value').dataset.ywliValue, 'Bar', 'server-rendered cell replaced the pending one');
  assert.equal(q('Title').textContent, 'Bar');
  assert.equal(document.activeElement, q('Title').querySelector('.ywli-value'), 'focus returns to the cell');
  assert.ok(root);
});

test('text: Enter on the focused value opens the editor; Escape cancels without a request', async () => {
  const { q } = setup();
  mockFetch(() => ok());
  const value = q('Title').querySelector('.ywli-value');
  value.focus();
  key(value, 'Enter');
  const input = q('Title').querySelector('input');
  assert.ok(input);
  input.value = 'changed';
  key(input, 'Escape');
  await sleep(5);
  assert.equal(calls.length, 0);
  assert.equal(q('Title').textContent, 'Foo');
  assert.equal(q('Title').querySelector('input'), null);
});

test('text: unchanged value commits nothing', async () => {
  const { q } = setup();
  mockFetch(() => ok());
  dbl(q('Title').querySelector('.ywli-value'));
  key(q('Title').querySelector('input'), 'Enter');
  await sleep(5);
  assert.equal(calls.length, 0);
  assert.equal(q('Title').textContent, 'Foo');
});

test('text: blur commits', async () => {
  const { q } = setup();
  mockFetch(() => ok({ cells: { Title: valueHtml('Blurred') } }));
  dbl(q('Title').querySelector('.ywli-value'));
  const input = q('Title').querySelector('input');
  input.value = 'Blurred';
  input.dispatchEvent(new dom.window.FocusEvent('blur'));
  await sleep(10);
  assert.deepEqual(calls[0].body.changes, { Title: 'Blurred' });
});

test('Tab commits and moves focus to the next editable cell', async () => {
  const { q } = setup();
  mockFetch(() => ok());
  dbl(q('Title').querySelector('.ywli-value'));
  const input = q('Title').querySelector('input');
  input.value = 'Tabbed';
  key(input, 'Tab');
  await sleep(10);
  assert.deepEqual(calls[0].body.changes, { Title: 'Tabbed' });
  assert.equal(document.activeElement, q('Qty').querySelector('.ywli-value'));
});

test('number: input carries min/max/step and sends the raw string', async () => {
  const { q } = setup();
  q('Qty').dataset.ywliStep = '1';
  mockFetch(() => ok());
  dbl(q('Qty').querySelector('.ywli-value'));
  const input = q('Qty').querySelector('input');
  assert.equal(input.type, 'number');
  assert.equal(input.min, '0');
  assert.equal(input.max, '99');
  assert.equal(input.step, '1');
  input.value = '12';
  key(input, 'Enter');
  await sleep(10);
  assert.deepEqual(calls[0].body.changes, { Qty: '12' });
});

test('select (static): change commits, pending shows the option label', async () => {
  const { q } = setup();
  let release;
  mockFetch(() => new Promise((resolve) => { release = () => resolve(ok({ cells: { Status: valueHtml('Beta', 'b') } })); }));
  dbl(q('Status').querySelector('.ywli-value'));
  const select = q('Status').querySelector('select');
  assert.deepEqual([...select.options].map((o) => o.value), ['a', 'b']);
  assert.equal(select.value, 'a');
  select.value = 'b';
  select.dispatchEvent(new dom.window.Event('change'));
  await sleep(5);
  assert.equal(q('Status').textContent, 'Beta');
  assert.deepEqual(calls[0].body.changes, { Status: 'b' });
  release();
  await sleep(10);
  assert.equal(q('Status').querySelector('.ywli-value').dataset.ywliValue, 'b');
});

test('select (dynamic): options fetched from the options endpoint when the editor opens', async () => {
  const { q } = setup();
  mockFetch((url) => {
    if (url.includes('/options/')) {
      return json(200, { ok: true, options: [{ value: '', label: '' }, { value: 'Graz', label: 'Graz' }, { value: 'Wien', label: 'Wien' }] });
    }
    return ok({ cells: { City: valueHtml('Wien', 'Wien') } });
  });
  dbl(q('City').querySelector('.ywli-value'));
  await sleep(10);
  assert.equal(calls[0].url, '/g/ywli/inline/options/City?id=7');
  assert.equal(calls[0].init.method, 'GET');
  assert.equal(calls[0].init.headers['X-SecurityID'], undefined, 'GET carries no CSRF header');
  const select = q('City').querySelector('select');
  assert.deepEqual([...select.options].map((o) => o.value), ['', 'Graz', 'Wien']);
  assert.equal(select.value, 'Graz');
  select.value = 'Wien';
  select.dispatchEvent(new dom.window.Event('change'));
  await sleep(10);
  assert.deepEqual(calls[1].body.changes, { City: 'Wien' });
});

test('select (dynamic): options request failure shows a toast and leaves the cell intact', async () => {
  const { q } = setup();
  mockFetch(() => json(403, { ok: false, error: 'forbidden', message: 'Nope' }));
  dbl(q('City').querySelector('.ywli-value'));
  await sleep(10);
  assert.ok(document.querySelector('.ywli-toast--error'));
  assert.equal(q('City').textContent, 'Graz');
  assert.equal(q('City').hasAttribute('aria-busy'), false);
  // editor is re-openable afterwards
  mockFetch(() => json(200, { ok: true, options: [{ value: 'Graz', label: 'Graz' }] }));
  dbl(q('City').querySelector('.ywli-value'));
  await sleep(10);
  assert.ok(q('City').querySelector('select'));
});

test('422: server message toasted, original cell restored', async () => {
  const { q } = setup();
  mockFetch(() => json(422, { ok: false, error: 'invalid', message: 'Maximum value is 99.' }));
  dbl(q('Qty').querySelector('.ywli-value'));
  const input = q('Qty').querySelector('input');
  input.value = '500';
  key(input, 'Enter');
  await sleep(10);
  assert.equal(q('Qty').textContent, '3');
  assert.match(document.querySelector('.ywli-toast--error').textContent, /Maximum value is 99\./);
});

test('undo: toast with action; click posts to the undo URL and applies the returned cells', async () => {
  const { root, q } = setup();
  const undo = { token: 't', ttl: 5, url: '/g/ywli/undo/t', message: 'Change saved.', label: 'Undo' };
  mockFetch((url) => {
    if (url.endsWith('/undo/t')) {
      return ok({ etag: 'e2', cells: { Title: valueHtml('Foo') }, changes: { Title: 'Foo' } });
    }
    return ok({ undo, cells: { Title: valueHtml('Bar') } });
  });
  let undone;
  root.addEventListener('ywli:cell:undone', (e) => { undone = e.detail; });

  dbl(q('Title').querySelector('.ywli-value'));
  const input = q('Title').querySelector('input');
  input.value = 'Bar';
  key(input, 'Enter');
  await sleep(10);
  assert.equal(q('Title').textContent, 'Bar');

  const toast = document.querySelector('.ywli-toast');
  assert.match(toast.textContent, /Change saved\./);
  assert.equal(toast.querySelector('.ywli-toast__bar').style.getPropertyValue('--ywli-toast-duration'), '5000ms');

  toast.querySelector('.ywli-toast__action').click();
  await sleep(10);
  assert.equal(calls[1].url, '/g/ywli/undo/t');
  assert.equal(calls[1].init.method, 'POST');
  assert.equal(calls[1].init.headers['X-SecurityID'], 'tok');
  assert.equal(calls[1].init.body, undefined);
  assert.equal(q('Title').textContent, 'Foo');
  assert.deepEqual([...root.querySelectorAll('[data-ywli-etag]')].map((c) => c.dataset.ywliEtag), Array(6).fill('e2'));
  assert.deepEqual(undone.changes, { Title: 'Foo' });
});

test('undo: repeated edits of one cell keep a single undo toast', async () => {
  const { q } = setup();
  let n = 0;
  mockFetch(() => { n += 1; return ok({ etag: `e${n}`, undo: { token: `t${n}`, ttl: 5, url: `/g/ywli/undo/t${n}`, message: `Saved ${n}`, label: 'Undo' } }); });
  for (const text of ['One', 'Two']) {
    dbl(q('Title').querySelector('.ywli-value'));
    const input = q('Title').querySelector('input');
    input.value = text;
    key(input, 'Enter');
    await sleep(10);
  }
  const toasts = [...document.querySelectorAll('.ywli-toast')];
  assert.equal(toasts.length, 1);
  assert.match(toasts[0].textContent, /Saved 2/);
});

test('undo: expired token (410) shows the server message', async () => {
  const { q } = setup();
  mockFetch((url) => (url.includes('/undo/')
    ? json(410, { ok: false, error: 'undo_expired', message: 'The undo period has expired.' })
    : ok({ undo: { token: 't', ttl: 5, url: '/g/ywli/undo/t', message: 'Change saved.', label: 'Undo' } })));
  q('A').querySelector('button').click();
  await sleep(10);
  document.querySelector('.ywli-toast__action').click();
  await sleep(10);
  assert.match([...document.querySelectorAll('.ywli-toast--error')].map((t) => t.textContent).join(), /undo period has expired/);
});

test('undo: conflict (409) toasts first, then applies the server row after the pause', async () => {
  const { q } = setup();
  mockFetch((url) => (url.includes('/undo/')
    ? json(409, { ok: false, error: 'conflict', message: 'Cannot undo: changed meanwhile.', id: 7, etag: 'e7', cells: { Title: valueHtml('Theirs') } })
    : ok({ undo: { token: 't', ttl: 5, url: '/g/ywli/undo/t', message: 'Change saved.', label: 'Undo' } })));
  q('A').querySelector('button').click();
  await sleep(10);
  document.querySelector('.ywli-toast__action').click();
  await sleep(10);
  assert.equal(q('Title').textContent, 'Foo');
  await sleep(60);
  assert.equal(q('Title').textContent, 'Theirs');
});
