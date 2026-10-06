import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';

const dom = new JSDOM('<!doctype html><body></body>', { pretendToBeVisual: true, url: 'http://localhost/admin/grid/' });
Object.assign(globalThis, {
  window: dom.window,
  document: dom.window.document,
  location: dom.window.location,
  Element: dom.window.Element,
  CustomEvent: dom.window.CustomEvent,
  AbortController: dom.window.AbortController,
  CSS: { escape: (s) => String(s) },
});

const { mountFullscreen } = await import('../src/js/features/fullscreen.js');
const { mountAccordion } = await import('../src/js/features/accordion.js');
const { mountKanban } = await import('../src/js/features/kanban.js');
const { mountMasterDetail } = await import('../src/js/features/master-detail.js');

const tick = (ms = 5) => new Promise((resolve) => setTimeout(resolve, ms));
const json = (status, body) => ({ ok: status < 400, status, json: async () => body });
const key = (el, k, init = {}) =>
  el.dispatchEvent(new dom.window.KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true, ...init }));
const click = (el, init = {}) =>
  el.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init }));

let calls;
const mockFetch = (handler) => {
  globalThis.fetch = async (url, init = {}) => {
    calls.push({ url, init, body: init.body ? JSON.parse(init.body) : undefined });
    return handler(url, init, calls.length);
  };
};

beforeEach(() => {
  calls = [];
  document.body.innerHTML = '';
  document.documentElement.className = '';
  window.localStorage.clear();
});

const grid = (inner = '', name = 'G') => {
  document.body.innerHTML = `<form><fieldset class="ss-gridfield" data-name="${name}">${inner}</fieldset></form>`;
  return document.querySelector('.ss-gridfield');
};

// ---------------------------------------------------------------- fullscreen

test('fullscreen: toggles class on root and <html>, Esc exits, floating button when none rendered', () => {
  const root = grid();
  const dispose = mountFullscreen(root, { strings: { enter: 'Go', exit: 'Back' } });
  const button = root.querySelector('[data-ywli-fullscreen-toggle]');
  assert.ok(button, 'fallback button created');
  assert.equal(button.textContent, 'Go');

  click(button);
  assert.ok(root.classList.contains('ywli-fullscreen'));
  assert.ok(document.documentElement.classList.contains('ywli-has-fullscreen'));
  assert.equal(button.getAttribute('aria-pressed'), 'true');
  assert.equal(button.textContent, 'Back');

  key(document.body, 'Escape');
  assert.ok(!root.classList.contains('ywli-fullscreen'));
  assert.ok(!document.documentElement.classList.contains('ywli-has-fullscreen'));
  dispose();
});

test('fullscreen: state survives a remount (grid reload) and cleans up when the grid is gone', () => {
  const root = grid('<button type="button" data-ywli-fullscreen-toggle></button>');
  let dispose = mountFullscreen(root, {});
  click(root.querySelector('[data-ywli-fullscreen-toggle]'));
  dispose();
  assert.ok(root.classList.contains('ywli-fullscreen'), 'root stays fullscreen across reload');

  dispose = mountFullscreen(root, {});
  assert.equal(root.querySelector('[data-ywli-fullscreen-toggle]').getAttribute('aria-pressed'), 'true');

  root.remove();
  dispose();
  assert.ok(!document.documentElement.classList.contains('ywli-has-fullscreen'));
});

test('fullscreen: Esc already handled elsewhere (defaultPrevented) is ignored', () => {
  const root = grid('<button type="button" data-ywli-fullscreen-toggle></button>');
  mountFullscreen(root, {});
  click(root.querySelector('[data-ywli-fullscreen-toggle]'));
  const event = new dom.window.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true });
  event.preventDefault();
  document.body.dispatchEvent(event);
  assert.ok(root.classList.contains('ywli-fullscreen'));
});

// ---------------------------------------------------------------- accordion

const accordionRows = (ids) => ids.map((id) => `<tr><td><button type="button" class="ywli-accordion__toggle" aria-expanded="false" data-ywli-accordion-id="${id}"></button></td><td>Row ${id}</td></tr>`).join('');

test('accordion: expand loads detail row, collapse removes it, row click is not triggered', async () => {
  mockFetch(() => json(200, { ok: true, html: '<dl><dt>A</dt><dd>1</dd></dl>' }));
  const root = grid(`<table><tbody>${accordionRows([1, 2])}</tbody></table>`);
  mountAccordion(root, { url: '/g/ywli/accordion', multiple: true, strings: {} });

  let rowClicks = 0;
  document.addEventListener('click', () => { rowClicks += 1; });
  const [t1] = root.querySelectorAll('.ywli-accordion__toggle');
  click(t1);
  await tick();

  assert.equal(calls[0].url, '/g/ywli/accordion/1');
  assert.equal(rowClicks, 0, 'propagation stopped');
  assert.equal(t1.getAttribute('aria-expanded'), 'true');
  const panel = root.querySelector('.ywli-accordion__row td');
  assert.equal(panel.colSpan, 2);
  assert.match(panel.innerHTML, /<dd>1<\/dd>/);

  click(t1);
  assert.equal(root.querySelector('.ywli-accordion__row'), null);
  assert.equal(t1.getAttribute('aria-expanded'), 'false');
});

test('accordion: multiple=false closes the other row; open rows are restored after a reload', async () => {
  mockFetch(() => json(200, { ok: true, html: 'x' }));
  const root = grid(`<table><tbody>${accordionRows([1, 2])}</tbody></table>`);
  let dispose = mountAccordion(root, { url: '/u', multiple: false, strings: {} });
  const [t1, t2] = root.querySelectorAll('.ywli-accordion__toggle');
  click(t1);
  await tick();
  click(t2);
  await tick();
  assert.equal(t1.getAttribute('aria-expanded'), 'false');
  assert.equal(root.querySelectorAll('.ywli-accordion__row').length, 1);

  // Simulated GridField reload: children replaced, root kept.
  dispose();
  root.innerHTML = `<table><tbody>${accordionRows([1, 2])}</tbody></table>`;
  calls.length = 0;
  dispose = mountAccordion(root, { url: '/u', multiple: false, strings: {} });
  await tick();
  assert.equal(calls.length, 1);
  assert.equal(calls[0].url, '/u/2');
  assert.equal(root.querySelectorAll('.ywli-accordion__toggle')[1].getAttribute('aria-expanded'), 'true');
  dispose();
});

test('accordion: failed load shows a message and stays collapsible', async () => {
  mockFetch(() => json(500, { ok: false, error: 'server_error' }));
  const root = grid(`<table><tbody>${accordionRows([1])}</tbody></table>`);
  mountAccordion(root, { url: '/u', strings: { loadFailed: 'Nope' } });
  const toggle = root.querySelector('.ywli-accordion__toggle');
  click(toggle);
  await tick();
  assert.equal(root.querySelector('.ywli-accordion__panel').textContent, 'Nope');
  click(toggle);
  assert.equal(root.querySelector('.ywli-accordion__row'), null);
});

// ---------------------------------------------------------------- kanban

const BOARD = {
  ok: true,
  truncated: false,
  columns: [{ value: 'a', label: 'Alpha', locked: false }, { value: 'b', label: 'Beta', locked: false }, { value: '', label: 'Other', locked: true }],
  cards: [
    { id: 1, group: 'a', title: 'One', fields: [{ label: 'Qty', value: '3' }, { label: 'Empty', value: '' }], link: '/g/item/1', canEdit: true },
    { id: 2, group: 'a', title: 'Two', fields: [], link: '/g/item/2', canEdit: false },
    { id: 3, group: '', title: 'Three', fields: [], link: null, canEdit: true },
  ],
};
const KCONFIG = { dataUrl: '/g/ywli/kanban/data', moveUrl: '/g/ywli/kanban/move', securityID: 'tok', strings: {} };

async function board(handler = (url) => (url.endsWith('/data') ? json(200, structuredClone(BOARD)) : json(200, { ok: true, id: 1, group: 'b' }))) {
  mockFetch(handler);
  const root = grid('<table class="grid-field__table"></table>');
  const dispose = mountKanban(root, KCONFIG);
  root.querySelector('[data-ywli-kanban-toggle]').click();
  await tick();
  return { root, dispose, lane: (v) => root.querySelector(`.ywli-kanban__lane[data-value="${v}"]`), card: (id) => root.querySelector(`.ywli-kanban__card[data-id="${id}"]`) };
}

test('kanban: renders lanes + cards, escapes text, remembers the view', async () => {
  const { root, lane, card } = await board();
  assert.ok(root.classList.contains('ywli-kanban-active'));
  assert.equal(lane('a').querySelectorAll('.ywli-kanban__card').length, 2);
  assert.equal(lane('b').querySelector('.ywli-kanban__empty')?.textContent, 'No cards');
  assert.equal(card(1).querySelectorAll('.ywli-kanban__field').length, 1, 'empty field values are skipped');
  assert.equal(card(2).draggable, false, 'read-only card is not draggable');
  assert.equal(window.localStorage.getItem('ywli:view:G'), 'board');
});

test('kanban: titles are text, never markup', async () => {
  const evil = { ...BOARD, cards: [{ id: 9, group: 'a', title: '<img src=x onerror=alert(1)>', fields: [], link: null, canEdit: true }] };
  const { root } = await board((url) => json(200, url.endsWith('/data') ? evil : { ok: true }));
  assert.equal(root.querySelector('img'), null);
  assert.match(root.querySelector('.ywli-kanban__title').textContent, /<img/);
});

test('kanban: drop moves the card optimistically and posts {id,to,from}', async () => {
  const { lane, card } = await board();
  const drag = (type, target) => {
    const event = new dom.window.Event(type, { bubbles: true, cancelable: true });
    event.dataTransfer = { setData() {}, effectAllowed: '', dropEffect: '' };
    target.dispatchEvent(event);
    return event;
  };
  drag('dragstart', card(1));
  assert.ok(drag('dragover', lane('b')).defaultPrevented, 'unlocked lane accepts the drop');
  assert.ok(!drag('dragover', lane('')).defaultPrevented, 'locked lane refuses');
  drag('drop', lane('b'));
  assert.equal(lane('b').querySelectorAll('.ywli-kanban__card').length, 1, 'moved before the server answered');
  await tick();

  const move = calls.find((c) => c.url.endsWith('/move'));
  assert.deepEqual(move.body, { id: 1, to: 'b', from: 'a' });
  assert.equal(move.init.headers['X-SecurityID'], 'tok');
});

test('kanban: failed move rolls back and shows an error toast', async () => {
  const { lane } = await board((url) => (url.endsWith('/data') ? json(200, structuredClone(BOARD)) : json(422, { ok: false, error: 'validation', message: 'Nope' })));
  key(document.querySelector('.ywli-kanban__card[data-id="1"]'), 'ArrowRight', { altKey: true });
  await tick();
  assert.equal(lane('a').querySelectorAll('.ywli-kanban__card').length, 2, 'rolled back');
  assert.match(document.querySelector('.ywli-toast--error').textContent, /Nope/);
});

test('kanban: 409 reloads the board from the server', async () => {
  let dataCalls = 0;
  await board((url) => {
    if (url.endsWith('/data')) { dataCalls += 1; return json(200, structuredClone(BOARD)); }
    return json(409, { ok: false, error: 'conflict', group: 'b' });
  });
  key(document.querySelector('.ywli-kanban__card[data-id="1"]'), 'ArrowRight', { altKey: true });
  await tick(15);
  assert.equal(dataCalls, 2);
  assert.match(document.querySelector('.ywli-toast--error').textContent, /changed by someone else/);
});

test('kanban: Alt+Arrow skips locked lanes; plain arrows move focus; read-only cards never move', async () => {
  const { card, lane } = await board();
  key(card(2), 'ArrowRight', { altKey: true });
  await tick();
  assert.equal(calls.filter((c) => c.url.endsWith('/move')).length, 0, 'read-only card');

  key(card(1), 'ArrowRight', { altKey: true });
  await tick();
  key(document.querySelector('.ywli-kanban__card[data-id="1"]'), 'ArrowRight', { altKey: true });
  await tick();
  assert.equal(calls.filter((c) => c.url.endsWith('/move')).length, 1, 'no move into the locked lane');
  assert.equal(lane('b').querySelectorAll('.ywli-kanban__card').length, 1);

  card(2).focus();
  key(card(2), 'ArrowUp');
  assert.equal(document.activeElement, document.querySelector('.ywli-kanban__card[data-id="2"]') ?? null);
});

test('kanban: opening a card fires a cancelable ywli:record:open; unhandled falls back to navigation target', async () => {
  const { root, card } = await board();
  let detail;
  root.addEventListener('ywli:record:open', (event) => { detail = event.detail; event.preventDefault(); });
  click(card(1));
  assert.deepEqual(detail, { id: 1, url: '/g/item/1' });
});

test('kanban: the toggle returns to the list view', async () => {
  const { root } = await board();
  root.querySelector('[data-ywli-kanban-toggle]').click();
  assert.ok(!root.classList.contains('ywli-kanban-active'));
  assert.equal(window.localStorage.getItem('ywli:view:G'), 'list');
  assert.equal(root.querySelector('.ywli-kanban').hidden, true);
});

// ---------------------------------------------------------------- master / detail

const mdRow = (id) => `<tr class="ss-gridfield-item"><td class="col-Title"><span class="ywli-value">T${id}</span> plain</td><td><a class="edit-link" href="/admin/grid/EditForm/field/G/item/${id}">Edit</a> <button type="button" class="act">x</button></td></tr>`;

function masterDetail(width = 50) {
  const root = grid(`<table><tbody>${mdRow(1)}${mdRow(2)}</tbody></table>`);
  const dispose = mountMasterDetail(root, { width, strings: {} });
  return { root, dispose, panel: () => document.querySelector('aside.ywli-detail') };
}

test('master-detail: row click opens the item URL in the side panel and marks the row', () => {
  const { root, panel } = masterDetail(60);
  assert.ok(panel().hidden);
  let bubbled = false;
  document.addEventListener('click', () => { bubbled = true; });
  click(root.querySelector('tr .col-Title'));

  assert.ok(!panel().hidden);
  assert.match(panel().querySelector('iframe').src, /\/item\/1$/);
  assert.equal(bubbled, false, 'default row navigation is suppressed');
  assert.ok(root.classList.contains('ywli-split-active'));
  assert.equal(panel().style.getPropertyValue('--ywli-detail-width'), '60');
  assert.ok(root.querySelector('tr').classList.contains('ywli-detail-active'));
});

test('master-detail: controls, inline-edit cells and modified clicks keep their default behaviour', () => {
  const { root, panel } = masterDetail();
  click(root.querySelector('.act'));
  click(root.querySelector('.ywli-value'));
  click(root.querySelector('.col-Title'), { ctrlKey: true });
  click(root.querySelector('.col-Title'), { metaKey: true });
  assert.ok(panel().hidden);
});

test('master-detail: the edit link itself opens the panel; Esc and the close button close it', () => {
  const { root, panel } = masterDetail();
  const link = root.querySelector('a.edit-link');
  click(link);
  assert.ok(!panel().hidden);
  key(document.body, 'Escape');
  assert.ok(panel().hidden);
  assert.ok(!root.classList.contains('ywli-split-active'));

  click(link);
  panel().querySelector('.ywli-detail__close').click();
  assert.ok(panel().hidden);
});

test('master-detail: ywli:record:open from another view opens the panel and is cancelled', () => {
  const { root, panel } = masterDetail();
  const event = new dom.window.CustomEvent('ywli:record:open', { bubbles: true, cancelable: true, detail: { id: 2, url: 'http://localhost/admin/grid/EditForm/field/G/item/2' } });
  root.dispatchEvent(event);
  assert.ok(event.defaultPrevented);
  assert.match(panel().querySelector('iframe').src, /item\/2$/);
});

test('master-detail: the panel survives a grid reload and is removed with the grid', () => {
  const { root, dispose, panel } = masterDetail();
  click(root.querySelector('a.edit-link'));
  dispose();
  root.innerHTML = `<table><tbody>${mdRow(1)}</tbody></table>`;
  const dispose2 = mountMasterDetail(root, { width: 50, strings: {} });
  assert.equal(document.querySelectorAll('aside.ywli-detail').length, 1);
  assert.ok(!panel().hidden);
  assert.ok(root.classList.contains('ywli-split-active'));

  root.remove();
  dispose2();
  assert.equal(document.querySelectorAll('aside.ywli-detail').length, 0);
});
