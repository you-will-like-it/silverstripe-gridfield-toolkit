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

const { mountColumnManager } = await import('../src/js/features/column-manager.js');
const { mountHeaderHelp } = await import('../src/js/features/header-help.js');
const { mountNestedRelation } = await import('../src/js/features/nested-relation.js');

let reloads;
beforeEach(() => {
  reloads = [];
  document.body.innerHTML = '';
  window.jQuery = (el) => ({ entwine: () => ({ reload: () => reloads.push(el.dataset.name) }) });
  window.confirm = () => true;
});

const click = (el, init = {}) => el.dispatchEvent(new dom.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init }));
const key = (k) => document.dispatchEvent(new dom.window.KeyboardEvent('keydown', { key: k, bubbles: true }));

function grid(inner = '') {
  document.body.innerHTML = `<fieldset class="ss-gridfield" data-name="G"><input type="hidden" class="gridstate" value="{}">${inner}</fieldset>`;
  return document.querySelector('.ss-gridfield');
}

// ---------------------------------------------------------------- column manager

const CM = {
  stateKey: 'YWLIColumns',
  columns: [
    { name: 'Title', label: 'Title', hidden: false, locked: true },
    { name: 'Qty', label: 'Qty', hidden: false, locked: false },
    { name: 'City', label: 'City', hidden: true, locked: false },
  ],
  strings: {},
};
const stateOf = (root) => JSON.parse(root.querySelector('input.gridstate').value);

test('column manager: menu lists columns with current visibility; locked ones are disabled', () => {
  const root = grid('<button type="button" data-ywli-columns-toggle></button>');
  mountColumnManager(root, CM);
  click(root.querySelector('[data-ywli-columns-toggle]'));
  const box = (n) => root.querySelector(`input[data-ywli-column="${n}"]`);
  assert.equal(box('Qty').checked, true);
  assert.equal(box('City').checked, false);
  assert.equal(box('Title').disabled, true);
  key('Escape'); // module-level open state would otherwise leak into the next test
});

test('column manager: unticking writes Hidden into the GridState and reloads; the menu stays open across the reload', () => {
  const root = grid('<button type="button" data-ywli-columns-toggle></button>');
  let dispose = mountColumnManager(root, CM);
  click(root.querySelector('[data-ywli-columns-toggle]'));
  const qty = root.querySelector('input[data-ywli-column="Qty"]');
  qty.checked = false;
  qty.dispatchEvent(new dom.window.Event('change', { bubbles: true }));

  assert.equal(stateOf(root).YWLIColumns.Hidden, 'Qty,City');
  assert.deepEqual(reloads, ['G']);

  dispose();
  dispose = mountColumnManager(root, CM);
  assert.ok(root.querySelector('.ywli-columns-menu'), 'reopened after the reload');
  root.remove(); // grid gone: forgets the open state
  dispose();
});

test('column manager: "show all" clears everything that can be shown; Esc closes the menu', () => {
  const root = grid('<button type="button" data-ywli-columns-toggle></button>');
  const dispose = mountColumnManager(root, CM);
  click(root.querySelector('[data-ywli-columns-toggle]'));
  click(root.querySelector('.ywli-columns-menu__reset'));
  assert.equal(stateOf(root).YWLIColumns.Hidden, '');
  key('Escape');
  assert.equal(root.querySelector('.ywli-columns-menu'), null);
  dispose();
});

test('column manager: floating button when the config has no button row', () => {
  const root = grid();
  mountColumnManager(root, CM);
  assert.ok(root.querySelector('.ywli-columns-toggle--floating'));
});

// ---------------------------------------------------------------- header help

const head = '<table><thead><tr><th class="main col-Title">Title<button class="sort">s</button></th><th class="main col-Owner-Name">Owner</th></tr></thead></table>';

test('header help: adds a button to matching headers only, with an accessible name', () => {
  const root = grid(head);
  mountHeaderHelp(root, { help: { Title: 'The title', 'Owner.Name': 'Who owns it', Nope: 'x' }, strings: { label: 'Help for {column}' } });
  const buttons = root.querySelectorAll('.ywli-help');
  assert.equal(buttons.length, 2);
  assert.ok(buttons[0].classList.contains('ywli-help__button'));
  assert.match(buttons[0].getAttribute('aria-label'), /^Help for Title/);
});

test('header help: matches SilverStripe sortable action header class', () => {
  const root = grid('<table><thead><tr><th class="main col-action_SetOrderStatus"><button class="sort">Status</button></th></tr></thead></table>');
  mountHeaderHelp(root, { help: { Status: 'The order status' } });

  assert.equal(root.querySelectorAll('.ywli-help').length, 1);
});

test('header help: click pins a tooltip (text only), does not reach the sort button, Esc closes', () => {
  const root = grid(head);
  mountHeaderHelp(root, { help: { Title: '<img src=x onerror=alert(1)>' } });
  let reached = false;
  root.addEventListener('click', () => { reached = true; });
  const button = root.querySelector('.ywli-help');
  click(button);

  const tip = document.querySelector('.ywli-help__tip');
  assert.equal(tip.getAttribute('role'), 'tooltip');
  assert.equal(button.getAttribute('aria-describedby'), tip.id);
  assert.equal(tip.querySelector('img'), null);
  assert.equal(reached, false);
  key('Escape');
  assert.equal(document.querySelector('.ywli-help__tip'), null);
});

test('header help: disposer removes buttons and tooltip', () => {
  const root = grid(head);
  const dispose = mountHeaderHelp(root, { help: { Title: 'x' } });
  click(root.querySelector('.ywli-help'));
  dispose();
  assert.equal(root.querySelector('.ywli-help'), null);
  assert.equal(document.querySelector('.ywli-help__tip'), null);
});

// ---------------------------------------------------------------- nested relation

const nestedRow = '<table><tbody><tr class="ss-gridfield-item"><td><button type="button" class="ywli-nested__open" data-ywli-nested-url="/admin/g/item/5" data-ywli-nested-title="Order 5">Items (2)</button></td></tr></tbody></table>';

test('nested relation: button opens a modal with the item URL; the click does not reach the row', () => {
  const root = grid(nestedRow);
  mountNestedRelation(root, { tab: 'Root_Items', strings: {} });
  let reached = false;
  document.addEventListener('click', () => { reached = true; });
  click(root.querySelector('.ywli-nested__open'));

  const dialog = document.querySelector('dialog.ywli-modal');
  assert.ok(dialog);
  assert.match(dialog.querySelector('iframe').src, /\/admin\/g\/item\/5$/);
  assert.equal(dialog.querySelector('strong').textContent, 'Order 5');
  assert.equal(reached, false);
});

test('nested relation: close button removes the modal; no grid reload when nothing changed', () => {
  const root = grid(nestedRow);
  mountNestedRelation(root, { tab: 'Root_Items', strings: {} });
  click(root.querySelector('.ywli-nested__open'));
  click(document.querySelector('.ywli-modal__close'));
  assert.equal(document.querySelector('dialog.ywli-modal'), null);
  assert.deepEqual(reloads, []);
});

test('nested relation: Esc closes (engines without showModal) and only one modal exists at a time', () => {
  const root = grid(nestedRow);
  mountNestedRelation(root, { strings: {} });
  click(root.querySelector('.ywli-nested__open'));
  click(root.querySelector('.ywli-nested__open'));
  assert.equal(document.querySelectorAll('dialog.ywli-modal').length, 1);
  key('Escape');
  assert.equal(document.querySelector('dialog.ywli-modal'), null);
});

test('nested relation: destroying the feature removes an open modal', () => {
  const root = grid(nestedRow);
  const dispose = mountNestedRelation(root, { strings: {} });
  click(root.querySelector('.ywli-nested__open'));
  dispose();
  assert.equal(document.querySelector('dialog.ywli-modal'), null);
});
