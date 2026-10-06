import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

const dom = new JSDOM('<!doctype html><body></body>', { pretendToBeVisual: true, url: 'http://localhost/admin/' });
Object.assign(globalThis, {
  window: dom.window,
  document: dom.window.document,
  location: dom.window.location,
  Element: dom.window.Element,
  CustomEvent: dom.window.CustomEvent,
  AbortController: dom.window.AbortController,
  CSS: { escape: (s) => String(s) },
});

const { mountStash } = await import('../src/js/features/stash.js');
const { mountMasterSelect } = await import('../src/js/features/master-select.js');
const { mountLinkedFilter } = await import('../src/js/features/linked-filter.js');
const { mountTransferSource, mountTransferTarget } = await import('../src/js/features/transfer.js');
const { clearSelection, getSelection } = await import('../src/js/core/selection.js');

const tick = (ms = 5) => new Promise((resolve) => setTimeout(resolve, ms));
const json = (status, body) => ({ ok: status < 400, status, json: async () => body });
const click = (el, init = {}) =>
  el.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init }));

let calls;
let reloads;
const mockFetch = (handler) => {
  globalThis.fetch = async (url, init = {}) => {
    calls.push({ url, init, body: init.body ? JSON.parse(init.body) : undefined });
    return handler(url, init);
  };
};

beforeEach(() => {
  calls = [];
  reloads = [];
  document.body.innerHTML = '';
  for (const name of ['A', 'B', 'M', 'S', 'T']) clearSelection(name);
  // The admin's GridField.js `reload` lives in Entwine's 'ss' namespace.
  window.jQuery = (el) => ({ entwine: () => ({ reload: () => reloads.push(el.dataset.name) }) });
  window.confirm = () => true;
});

const row = (id, extra = '') => `<tr class="ss-gridfield-item" data-id="${id}"><td class="ywli-stash-cell"><input type="checkbox" class="ywli-stash__box" data-ywli-stash-id="${id}"></td><td class="c">Row ${id}${extra}</td><td><a class="edit-link" href="/admin/item/${id}">Edit</a></td></tr>`;
const table = (ids) => `<table class="grid-field__table"><thead><tr><th>t</th></tr><tr><th></th><th>Title</th><th></th></tr></thead><tbody>${ids.map((id) => row(id)).join('')}</tbody></table>`;
const state = (obj = {}) => `<input type="hidden" class="gridstate" value='${JSON.stringify(obj)}'>`;

function grid(name, ids = [1, 2, 3], extra = '') {
  const root = document.createElement('fieldset');
  root.className = 'ss-gridfield';
  root.dataset.name = name;
  root.innerHTML = `${state()}${table(ids)}${extra}`;
  document.body.append(root);
  return root;
}

// ---------------------------------------------------------------- stash

const STASH = {
  url: '/g/ywli/stash/run',
  securityID: 'tok',
  max: 3,
  actions: [
    { name: 'archive', label: 'Archive', destructive: false, confirm: null },
    { name: 'delete', label: 'Delete', destructive: true, confirm: 'Really {count}?' },
  ],
  strings: {},
};
const box = (root, id) => root.querySelector(`.ywli-stash__box[data-ywli-stash-id="${id}"]`);
const toggleBox = (root, id, init = {}) => {
  const b = box(root, id);
  b.checked = !b.checked;
  b.dispatchEvent(new dom.window.MouseEvent('change', { bubbles: true, ...init }));
};

test('stash: selecting shows the bar, header box selects the page, clear empties', () => {
  const root = grid('A');
  mountStash(root, STASH);
  const bar = root.querySelector('.ywli-stash__bar');
  assert.ok(bar.hidden);

  toggleBox(root, 1);
  assert.ok(!bar.hidden);
  assert.equal(bar.querySelector('.ywli-stash__count').textContent, '1 selected');
  const all = root.querySelector('.ywli-stash__all');
  assert.ok(all, 'select-all sits in the header row that matches the body columns');
  assert.equal(all.indeterminate, true);

  all.checked = true;
  all.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
  assert.equal(getSelection('A').size, 3);
  assert.equal(all.indeterminate, false);

  bar.querySelector('.ywli-stash__clear').click();
  assert.equal(getSelection('A').size, 0);
  assert.ok(bar.hidden);
});

test('stash: selection survives a reload (remount) and is dropped when the grid is gone', () => {
  const root = grid('A');
  let dispose = mountStash(root, STASH);
  toggleBox(root, 2);
  dispose();

  root.innerHTML = state() + table([2, 3]); // e.g. page 2 shows other rows, one still selected
  dispose = mountStash(root, STASH);
  assert.equal(box(root, 2).checked, true);
  assert.ok(box(root, 2).closest('tr').classList.contains('ywli-stash-selected'));

  root.remove();
  dispose();
  assert.equal(getSelection('A').size, 0);
});

test('stash: shift-click selects a range; the limit is enforced with a toast', () => {
  const root = grid('A', [1, 2, 3, 4]);
  mountStash(root, { ...STASH, max: 3 });
  toggleBox(root, 1);
  toggleBox(root, 3, { shiftKey: true });
  assert.deepEqual([...getSelection('A')].sort(), ['1', '2', '3']);

  toggleBox(root, 4);
  assert.equal(getSelection('A').size, 3, 'fourth is refused');
  assert.equal(box(root, 4).checked, false);
  assert.match(document.querySelector('.ywli-toast--error').textContent, /At most 3/);
});

test('stash: clicks in the checkbox cell never reach the row (no edit navigation)', () => {
  const root = grid('A');
  mountStash(root, STASH);
  let reached = false;
  document.addEventListener('click', () => { reached = true; });
  click(root.querySelector('.ywli-stash-cell'));
  assert.equal(reached, false);
});

test('stash: runs the action with the selected ids, drops succeeded ids, keeps failed ones, reloads', async () => {
  mockFetch(() => json(200, { ok: true, processed: 1, failed: [{ id: 2, message: 'x' }] }));
  const root = grid('A');
  mountStash(root, STASH);
  toggleBox(root, 1);
  toggleBox(root, 2);
  root.querySelector('[data-ywli-action="archive"]').click();
  await tick();

  assert.equal(calls[0].url, '/g/ywli/stash/run/archive');
  assert.deepEqual(calls[0].body, { ids: [1, 2] });
  assert.equal(calls[0].init.headers['X-SecurityID'], 'tok');
  assert.deepEqual([...getSelection('A')], ['2']);
  assert.deepEqual(reloads, ['A']);
  assert.match(document.querySelector('.ywli-toast--error').textContent, /1 done, 1 failed/);
});

test('stash: destructive action asks first and does nothing when declined', async () => {
  mockFetch(() => json(200, { ok: true, processed: 1, failed: [] }));
  let asked;
  window.confirm = (message) => { asked = message; return false; };
  const root = grid('A');
  mountStash(root, STASH);
  toggleBox(root, 1);
  root.querySelector('[data-ywli-action="delete"]').click();
  await tick();
  assert.equal(asked, 'Really 1?');
  assert.equal(calls.length, 0);
  assert.equal(getSelection('A').size, 1);
});

test('stash: a request error keeps the selection and shows an error', async () => {
  mockFetch(() => json(500, { ok: false, error: 'server_error' }));
  const root = grid('A');
  mountStash(root, STASH);
  toggleBox(root, 1);
  root.querySelector('[data-ywli-action="archive"]').click();
  await tick();
  assert.equal(getSelection('A').size, 1);
  assert.equal(reloads.length, 0);
  assert.ok(document.querySelector('.ywli-toast--error'));
});

// ---------------------------------------------------------------- master / slave

const stateOf = (root) => JSON.parse(root.querySelector('input.gridstate').value);

test('master-select: row click writes MasterID into the slave GridState (page reset) and reloads it', () => {
  const master = grid('M');
  const slave = grid('S');
  slave.querySelector('input.gridstate').value = JSON.stringify({ GridFieldPaginator: { currentPage: 4 } });
  mountMasterSelect(master, { slaves: ['S'] });

  let bubbled = false;
  document.addEventListener('click', () => { bubbled = true; });
  click(master.querySelector('tr[data-id="2"] .c'));

  assert.equal(bubbled, false, 'default row navigation suppressed');
  assert.equal(stateOf(slave).YWLILinkedFilter.MasterID, 2);
  assert.equal(stateOf(slave).GridFieldPaginator.currentPage, 1);
  assert.deepEqual(reloads, ['S']);
  assert.ok(master.querySelector('tr[data-id="2"]').classList.contains('ywli-master-selected'));
});

test('master-select: clicking the selected row again clears it; controls and modified clicks are ignored', () => {
  const master = grid('M');
  const slave = grid('S');
  mountMasterSelect(master, { slaves: ['S'] });

  click(master.querySelector('tr[data-id="1"] .c'));
  click(master.querySelector('tr[data-id="1"] .c'));
  assert.equal(stateOf(slave).YWLILinkedFilter.MasterID, 0);

  reloads.length = 0;
  click(master.querySelector('a.edit-link'));
  click(master.querySelector('tr[data-id="1"] .c'), { ctrlKey: true });
  assert.equal(reloads.length, 0);
});

test('master-select: highlights the row that the slave state points at after a reload', () => {
  const master = grid('M');
  const slave = grid('S');
  slave.querySelector('input.gridstate').value = JSON.stringify({ YWLILinkedFilter: { MasterID: 3 } });
  mountMasterSelect(master, { slaves: ['S'] });
  assert.ok(master.querySelector('tr[data-id="3"]').classList.contains('ywli-master-selected'));
});

test('linked-filter: hint while nothing is selected, none once a master is chosen', () => {
  const idle = grid('S');
  mountLinkedFilter(idle, { emptyUntilSelected: true, stateKey: 'YWLILinkedFilter', strings: { hint: 'Pick a row' } });
  assert.equal(idle.querySelector('.ywli-linked__hint').textContent, 'Pick a row');
  assert.ok(idle.classList.contains('ywli-linked--idle'));

  const chosen = grid('T');
  chosen.querySelector('input.gridstate').value = JSON.stringify({ YWLILinkedFilter: { MasterID: 5 } });
  mountLinkedFilter(chosen, { emptyUntilSelected: true, strings: { hint: 'Pick a row' } });
  assert.equal(chosen.querySelector('.ywli-linked__hint'), null);
});

// ---------------------------------------------------------------- transfer

const dragEvent = (type, target, init = {}) => {
  const event = new dom.window.Event(type, { bubbles: true, cancelable: true, ...init });
  event.dataTransfer = { setData() {}, effectAllowed: '', dropEffect: '' };
  target.dispatchEvent(event);
  return event;
};

const TARGET = { url: '/t/ywli/transfer/receive', securityID: 'tok', accept: ['A'], modes: ['move', 'copy'], strings: { move: 'Move here', copy: 'Copy here' } };

test('transfer: source rows become draggable', () => {
  const root = grid('A');
  mountTransferSource(root);
  assert.ok([...root.querySelectorAll('tr.ss-gridfield-item')].every((r) => r.draggable));
});

test('transfer: dropping on a target with two modes asks, then posts the dragged row', async () => {
  mockFetch(() => json(200, { ok: true, processed: 1, failed: [] }));
  const source = grid('A');
  const target = grid('T', [9]);
  mountTransferSource(source);
  mountTransferTarget(target, TARGET);

  dragEvent('dragstart', source.querySelector('tr[data-id="2"]'));
  assert.ok(dragEvent('dragover', target).defaultPrevented, 'accepted source');
  dragEvent('drop', target);

  const menu = target.querySelector('.ywli-transfer-menu');
  assert.ok(menu);
  menu.querySelector('[data-ywli-mode="copy"]').click();
  await tick();

  assert.deepEqual(calls[0].body, { source: 'A', ids: [2], mode: 'copy' });
  assert.deepEqual(reloads.sort(), ['A', 'T']);
  assert.equal(target.querySelector('.ywli-transfer-menu'), null);
});

test('transfer: a stashed row carries the whole stash; a single mode posts immediately', async () => {
  mockFetch(() => json(200, { ok: true, processed: 2, failed: [{ id: 3, message: 'no' }] }));
  const source = grid('A');
  const target = grid('T', [9]);
  mountTransferSource(source);
  mountTransferTarget(target, { ...TARGET, modes: ['move'] });
  getSelection('A').add('1').add('3');

  dragEvent('dragstart', source.querySelector('tr[data-id="1"]'));
  dragEvent('drop', target);
  await tick();

  assert.deepEqual(calls[0].body, { source: 'A', ids: [1, 3], mode: 'move' });
  assert.deepEqual([...getSelection('A')], ['3'], 'only the failed id stays stashed');
});

test('transfer: sources that are not accepted (and the grid itself) are refused', () => {
  const other = grid('B');
  const target = grid('T', [9]);
  mountTransferSource(other);
  mountTransferTarget(target, TARGET);
  dragEvent('dragstart', other.querySelector('tr[data-id="1"]'));
  assert.ok(!dragEvent('dragover', target).defaultPrevented);
  dragEvent('drop', target);
  assert.equal(target.querySelector('.ywli-transfer-menu'), null);
  assert.equal(calls.length, 0);
});

test('transfer: Esc or Cancel closes the menu without a request', () => {
  const source = grid('A');
  const target = grid('T', [9]);
  mountTransferSource(source);
  mountTransferTarget(target, TARGET);
  dragEvent('dragstart', source.querySelector('tr[data-id="1"]'));
  dragEvent('drop', target);
  assert.ok(target.querySelector('.ywli-transfer-menu'));
  document.dispatchEvent(new dom.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
  assert.equal(target.querySelector('.ywli-transfer-menu'), null);
  assert.equal(calls.length, 0);
});
