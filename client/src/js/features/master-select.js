import { liveRoot, readGridState, reloadGrid, ROW_CONTROL, rowId, updateGridState, gridName } from '../core/grid.js';

const STATE_KEY = 'YWLILinkedFilter';

class MasterSelect {
  #root;
  #slaves;
  #abort = new AbortController();

  constructor(root, config) {
    this.#root = root;
    this.#slaves = config.slaves ?? [];

    root.addEventListener('click', this.#onClick, { capture: true, signal: this.#abort.signal });
    this.#markSelected(this.#currentId());
  }

  destroy() {
    this.#abort.abort();
  }

  /** The selection is read from the first slave's GridState, which is the single source of truth. */
  #currentId() {
    const slave = liveRoot(null, this.#slaves[0]);
    return String(readGridState(slave)[STATE_KEY]?.MasterID ?? 0);
  }

  #markSelected(id) {
    for (const row of this.#root.querySelectorAll('tr.ss-gridfield-item')) {
      const selected = id !== '0' && rowId(row) === id;
      row.classList.toggle('ywli-master-selected', selected);
      if (selected) row.setAttribute('aria-current', 'true');
      else row.removeAttribute('aria-current');
    }
  }

  #onClick = (event) => {
    if (event.button !== 0 || event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const target = event.target instanceof Element ? event.target : null;
    const row = target?.closest('tr.ss-gridfield-item');
    if (!row || row.closest('.ss-gridfield') !== this.#root || target.closest(ROW_CONTROL)) return;
    const id = rowId(row);
    if (!id) return;

    event.preventDefault();
    event.stopPropagation();

    // Clicking the selected row again clears the selection.
    const next = this.#currentId() === id ? '0' : id;
    for (const name of this.#slaves) {
      const slave = liveRoot(null, name);
      const changed = updateGridState(slave, (state) => {
        state[STATE_KEY] = { ...state[STATE_KEY], MasterID: Number(next) };
        // A different master means a different result set: back to page 1.
        if (state.GridFieldPaginator) state.GridFieldPaginator = { ...state.GridFieldPaginator, currentPage: 1 };
      });
      if (changed) reloadGrid(slave, name);
    }
    this.#markSelected(next);
    this.#root.dispatchEvent(new CustomEvent('ywli:master:select', {
      bubbles: true,
      detail: { grid: gridName(this.#root), id: next === '0' ? null : next, slaves: this.#slaves },
    }));
  };
}

export function mountMasterSelect(root, config) {
  const instance = new MasterSelect(root, config);
  return () => instance.destroy();
}
