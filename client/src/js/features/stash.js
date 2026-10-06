import { ApiError, runBulk } from '../core/api.js';
import { fill, gridName, ownedBy, reloadGrid } from '../core/grid.js';
import { clearSelection, getSelection } from '../core/selection.js';
import { showToast } from '../core/toast.js';

const DEFAULT_STRINGS = {
  selected: '{count} selected',
  clear: 'Clear',
  selectAll: 'Select all on this page',
  confirmDefault: 'Run this action on {count} records?',
  done: '{count} done.',
  partial: '{count} done, {failed} failed.',
  failed: 'The action failed.',
  limit: 'At most {max} records can be selected.',
};

class Stash {
  #root;
  #config;
  #name;
  #selection;
  #abort = new AbortController();
  #bar;
  #count;
  #buttons = [];
  #header = null;
  #lastBox = null;
  #busy = false;

  constructor(root, config) {
    this.#root = root;
    this.#name = gridName(root);
    this.#config = { max: 500, actions: [], ...config, strings: { ...DEFAULT_STRINGS, ...config.strings } };
    this.#selection = getSelection(this.#name);

    this.#buildBar();
    this.#buildHeaderBox();

    const { signal } = this.#abort;
    // Capture: the whole checkbox cell is inert for the row's own click (edit form navigation).
    root.addEventListener('click', this.#onClick, { capture: true, signal });
    root.addEventListener('change', this.#onChange, { signal });
    this.#sync();
  }

  destroy() {
    this.#abort.abort();
    this.#bar.remove();
    this.#header?.remove();
    if (!this.#root.isConnected) {
      clearSelection(this.#name);
    }
  }

  #boxes() {
    return [...this.#root.querySelectorAll('.ywli-stash__box')].filter((box) => ownedBy(this.#root, box));
  }

  #buildBar() {
    const { strings, actions } = this.#config;
    this.#bar = document.createElement('div');
    this.#bar.className = 'ywli-stash__bar';
    this.#bar.setAttribute('role', 'toolbar');
    this.#bar.hidden = true;

    this.#count = this.#bar.appendChild(document.createElement('span'));
    this.#count.className = 'ywli-stash__count';
    this.#count.setAttribute('aria-live', 'polite');

    for (const action of actions) {
      const button = this.#bar.appendChild(document.createElement('button'));
      button.type = 'button';
      button.className = `btn btn-sm ${action.destructive ? 'btn-outline-danger' : 'btn-secondary'} ywli-stash__action`;
      button.dataset.ywliAction = action.name;
      button.textContent = action.label;
      button.addEventListener('click', () => this.#run(action), { signal: this.#abort.signal });
      this.#buttons.push(button);
    }

    const clear = this.#bar.appendChild(document.createElement('button'));
    clear.type = 'button';
    clear.className = 'btn btn-sm btn-link ywli-stash__clear';
    clear.textContent = strings.clear;
    clear.addEventListener('click', () => {
      this.#selection.clear();
      this.#sync();
    }, { signal: this.#abort.signal });

    const table = this.#root.querySelector('table.grid-field__table');
    if (table) {
      table.before(this.#bar);
    } else {
      this.#root.prepend(this.#bar);
    }
  }

  /** Select-all checkbox in the header cell above the checkbox column (the header row with as many cells as a body row). */
  #buildHeaderBox() {
    const cell = this.#root.querySelector('td.ywli-stash-cell');
    const row = cell?.parentElement;
    if (!row) return;
    const index = [...row.children].indexOf(cell);
    const headRow = [...this.#root.querySelectorAll('thead tr')].find((tr) => tr.children.length === row.children.length);
    const th = headRow?.children[index];
    if (!th) return;
    this.#header = document.createElement('input');
    this.#header.type = 'checkbox';
    this.#header.className = 'ywli-stash__all';
    this.#header.setAttribute('aria-label', this.#config.strings.selectAll);
    th.append(this.#header);
  }

  #sync() {
    const boxes = this.#boxes();
    for (const box of boxes) {
      box.checked = this.#selection.has(box.dataset.ywliStashId);
      box.closest('tr')?.classList.toggle('ywli-stash-selected', box.checked);
    }
    if (this.#header) {
      const checked = boxes.filter((box) => box.checked).length;
      this.#header.checked = boxes.length > 0 && checked === boxes.length;
      this.#header.indeterminate = checked > 0 && checked < boxes.length;
    }
    const size = this.#selection.size;
    this.#bar.hidden = size === 0;
    this.#count.textContent = fill(this.#config.strings.selected, { count: size });
    for (const button of this.#buttons) button.disabled = this.#busy;
  }

  #onClick = (event) => {
    const cell = event.target instanceof Element ? event.target.closest('.ywli-stash-cell') : null;
    if (cell && ownedBy(this.#root, cell)) {
      event.stopPropagation();
    }
  };

  #onChange = (event) => {
    const target = event.target;
    if (!(target instanceof Element) || !ownedBy(this.#root, target)) return;

    if (target === this.#header) {
      this.#toggleMany(this.#boxes(), target.checked);
    } else if (target.matches('.ywli-stash__box')) {
      const boxes = this.#boxes();
      // Shift-click selects the whole range since the last toggled box.
      if (event.shiftKey && this.#lastBox && boxes.includes(this.#lastBox)) {
        const [from, to] = [boxes.indexOf(this.#lastBox), boxes.indexOf(target)].sort((a, b) => a - b);
        this.#toggleMany(boxes.slice(from, to + 1), target.checked);
      } else {
        this.#toggleMany([target], target.checked);
      }
      this.#lastBox = target;
    } else {
      return;
    }
    this.#sync();
  };

  #toggleMany(boxes, on) {
    for (const box of boxes) {
      const id = box.dataset.ywliStashId;
      if (!on) {
        this.#selection.delete(id);
      } else if (this.#selection.size < this.#config.max || this.#selection.has(id)) {
        this.#selection.add(id);
      } else {
        showToast({ message: fill(this.#config.strings.limit, { max: this.#config.max }), type: 'error' });
        break;
      }
    }
  }

  async #run(action) {
    const ids = [...this.#selection];
    if (this.#busy || ids.length === 0) return;
    const { strings } = this.#config;
    if (action.destructive || action.confirm) {
      const message = fill(action.confirm ?? strings.confirmDefault, { count: ids.length });
      if (!window.confirm(message)) return;
    }

    this.#busy = true;
    this.#sync();
    try {
      const result = await runBulk({
        url: `${this.#config.url}/${encodeURIComponent(action.name)}`,
        securityID: this.#config.securityID,
        ids: ids.map(Number),
      });
      const failedIds = new Set(result.failed.map((f) => String(f.id)));
      for (const id of ids) {
        if (!failedIds.has(id)) this.#selection.delete(id);
      }
      const failed = result.failed.length;
      showToast({
        message: fill(failed ? strings.partial : strings.done, { count: result.processed, failed }),
        type: failed ? 'error' : 'success',
        duration: failed ? 8000 : 4000,
      });
      this.#root.dispatchEvent(new CustomEvent('ywli:stash:done', { bubbles: true, detail: { action: action.name, ...result } }));
      if (result.processed > 0) reloadGrid(this.#root, this.#name);
    } catch (error) {
      showToast({ message: error instanceof ApiError && error.status ? error.message : strings.failed, type: 'error' });
    } finally {
      this.#busy = false;
      this.#sync();
    }
  }
}

export function mountStash(root, config) {
  const instance = new Stash(root, config);
  return () => instance.destroy();
}
