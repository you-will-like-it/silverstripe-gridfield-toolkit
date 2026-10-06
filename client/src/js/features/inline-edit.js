import { ApiError, ConflictError, fetchOptions, patchRecord, undoPatch } from '../core/api.js';
import { showToast } from '../core/toast.js';
import { createFieldEditor } from './field-editor.js';

const DEFAULT_STRINGS = {
  conflict: 'Conflict: record modified by another user.',
  saveFailed: 'Could not save the change.',
  notFound: 'This record no longer exists.',
  loadFailed: 'Could not load the choices.',
  undone: 'Change undone.',
};

const FIELD_TYPES = new Set(['text', 'number', 'select']);
const FOCUSABLE = 'button, input, select, textarea, a[href], [tabindex="0"]';

const wait = (ms, signal) => new Promise((resolve, reject) => {
  const timer = setTimeout(resolve, ms);
  signal.addEventListener('abort', () => {
    clearTimeout(timer);
    reject(new DOMException('Aborted', 'AbortError'));
  }, { once: true });
});

class InlineEdit {
  #root;
  #config;
  #gridName;
  #abort = new AbortController();
  /** record id -> tail of that record's request chain */
  #queues = new Map();
  /** `${id}:${column}` -> live undo toast, so repeated edits of one cell keep a single toast */
  #undoToasts = new Map();
  #editing = false;

  constructor(root, config) {
    this.#root = root;
    this.#gridName = root.dataset.name ?? '';
    this.#config = {
      conflictPauseMs: 2500,
      ...config,
      strings: { ...DEFAULT_STRINGS, ...config.strings },
    };

    // Capture phase on the grid root: runs before the admin's delegated Entwine row-click handler
    // (`.grid-field .ss-gridfield-item` onclick, bubble phase at document level), so stopPropagation() on a claimed
    // click keeps the row from opening its edit form.
    const { signal } = this.#abort;
    root.addEventListener('click', this.#onClick, { capture: true, signal });
    root.addEventListener('dblclick', this.#onDblClick, { capture: true, signal });
    root.addEventListener('keydown', this.#onKeydown, { signal });
  }

  destroy() {
    this.#abort.abort();
    this.#queues.clear();
  }

  // ------------------------------------------------------------------ event routing

  /** The editable cell the event happened in, if it belongs to this grid (nested grids mount their own instance). */
  #ownCell(target) {
    const cell = target instanceof Element ? target.closest('.ywli-editable') : null;
    return cell && cell.closest('.ss-gridfield') === this.#root ? cell : null;
  }

  #onClick = (event) => {
    const cell = this.#ownCell(event.target);
    if (!cell) {
      return;
    }

    if (cell.dataset.ywliEditor === 'toggle') {
      // Only the switch itself is claimed; clicks on cell padding still open the row.
      const control = event.target.closest('[data-ywli-toggle]');
      if (control) {
        event.stopPropagation();
        event.preventDefault();
        void this.#toggle(cell, control);
      }
      return;
    }

    // Text / number / select: the whole cell is claimed (a double-click must not be preceded by row navigation).
    // A single click only focuses; double-click, Enter or F2 edits.
    event.stopPropagation();
    if (!this.#editing) {
      cell.querySelector('.ywli-value')?.focus({ preventScroll: true });
    }
  };

  #onDblClick = (event) => {
    const cell = this.#ownCell(event.target);
    if (cell && FIELD_TYPES.has(cell.dataset.ywliEditor)) {
      event.stopPropagation();
      event.preventDefault();
      void this.#open(cell);
    }
  };

  #onKeydown = (event) => {
    if ((event.key !== 'Enter' && event.key !== 'F2') || !(event.target instanceof Element)
      || !event.target.matches('.ywli-value')) {
      return;
    }
    const cell = this.#ownCell(event.target);
    if (cell && FIELD_TYPES.has(cell.dataset.ywliEditor)) {
      event.preventDefault();
      event.stopPropagation();
      void this.#open(cell);
    }
  };

  // ------------------------------------------------------------------ toggle

  async #toggle(cell, control) {
    if (control.getAttribute('aria-busy') === 'true') {
      return;
    }

    const { ywliColumn: column, ywliId: id } = cell.dataset;
    const row = cell.closest('tr');
    const previous = control.getAttribute('aria-checked') === 'true';
    const next = !previous;

    // Optimistic: flip immediately, roll back on failure.
    control.setAttribute('aria-checked', String(next));
    control.setAttribute('aria-busy', 'true');

    try {
      this.#applyResult(row, await this.#send(cell, column, next), { id, column });
    } catch (error) {
      if (error?.name === 'AbortError') {
        return; // grid was reloaded/unmounted; its fresh render is the truth
      }
      control.setAttribute('aria-checked', String(previous));
      await this.#fail(row, error);
    } finally {
      control.removeAttribute('aria-busy');
    }
  }

  // ------------------------------------------------------------------ text / number / select

  async #open(cell) {
    if (this.#editing || cell.getAttribute('aria-busy') === 'true') {
      return;
    }

    const type = cell.dataset.ywliEditor;
    const column = cell.dataset.ywliColumn;
    const schema = this.#config.editors?.[column] ?? {};
    const valueEl = cell.querySelector('.ywli-value');
    if (!valueEl) {
      return;
    }
    const current = valueEl.dataset.ywliValue ?? '';

    this.#editing = true; // also blocks re-entry while dynamic options load

    let options = schema.options ?? [];
    if (type === 'select' && schema.dynamic) {
      cell.setAttribute('aria-busy', 'true');
      try {
        ({ options } = await fetchOptions({
          url: this.#config.optionsUrl,
          column,
          id: cell.dataset.ywliId,
          signal: this.#abort.signal,
        }));
      } catch (error) {
        this.#editing = false;
        if (error?.name !== 'AbortError') {
          showToast({
            type: 'error',
            message: error instanceof ApiError ? error.message : this.#config.strings.loadFailed,
          });
        }
        return;
      } finally {
        cell.removeAttribute('aria-busy');
      }
    }

    if (!cell.isConnected) {
      this.#editing = false; // grid reloaded while the options were loading
      return;
    }

    const original = cell.innerHTML;
    const editor = createFieldEditor({ type, schema, cell, value: current, options });
    cell.classList.add('ywli-editing');
    cell.replaceChildren(editor.element);
    editor.element.focus();
    editor.element.select?.();

    let finished = false;
    const finish = (action, { advance = 0 } = {}) => {
      if (finished) {
        return;
      }
      finished = true;
      this.#editing = false;
      cell.classList.remove('ywli-editing');

      const value = editor.getValue();
      if (action === 'commit' && value !== current) {
        void this.#saveField(cell, { column, type, value, original, options });
      } else {
        cell.innerHTML = original;
      }

      if (advance) {
        this.#focusNeighbour(cell, advance);
      } else {
        cell.querySelector('.ywli-value')?.focus({ preventScroll: true });
      }
    };

    editor.element.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        finish('cancel');
      } else if (event.key === 'Enter' && (!editor.multiline || event.ctrlKey || event.metaKey)) {
        event.preventDefault();
        finish('commit');
      } else if (event.key === 'Tab') {
        event.preventDefault();
        finish('commit', { advance: event.shiftKey ? -1 : 1 });
      }
    });
    editor.element.addEventListener('blur', () => finish(type === 'select' ? 'cancel' : 'commit'));
    if (type === 'select') {
      editor.element.addEventListener('change', () => finish('commit'));
    }
  }

  async #saveField(cell, { column, type, value, original, options }) {
    const { ywliId: id } = cell.dataset;
    const row = cell.closest('tr');

    // Show the new value immediately (dimmed); the server-rendered cell replaces it on success.
    const pending = document.createElement('span');
    pending.className = 'ywli-value ywli-value--pending';
    pending.tabIndex = 0;
    pending.textContent = type === 'select' ? (options.find((o) => o.value === value)?.label ?? value) : value;
    cell.replaceChildren(pending);
    cell.setAttribute('aria-busy', 'true');
    if (document.activeElement === document.body || cell.contains(document.activeElement)) {
      pending.focus({ preventScroll: true });
    }

    try {
      this.#applyResult(row, await this.#send(cell, column, value), { id, column });
    } catch (error) {
      if (error?.name === 'AbortError') {
        return;
      }
      cell.innerHTML = original;
      await this.#fail(row, error);
    } finally {
      cell.removeAttribute('aria-busy');
    }
  }

  #focusNeighbour(cell, direction) {
    const cells = [...this.#root.querySelectorAll('.ywli-editable')].filter((c) => c.closest('.ss-gridfield') === this.#root);
    const next = cells[cells.indexOf(cell) + direction];
    (next ?? cell).querySelector(FOCUSABLE)?.focus({ preventScroll: false });
  }

  // ------------------------------------------------------------------ transport / results

  /** Serialised per record: the etag is read when the request starts, after the previous one refreshed it. */
  #send(cell, column, value) {
    const { ywliId: id } = cell.dataset;

    return this.#serial(id, () => patchRecord({
      url: this.#config.patchUrl,
      securityID: this.#config.securityID,
      id: Number(id),
      etag: cell.dataset.ywliEtag,
      changes: { [column]: value },
      signal: this.#abort.signal,
    }));
  }

  #applyResult(row, payload, { id, column }) {
    this.#apply(row, payload);
    this.#emit('ywli:cell:saved', {
      id: Number(id), column, previous: payload.previous, changes: payload.changes, payload,
    });
    if (payload.undo) {
      this.#offerUndo(payload.undo, `${id}:${column}`);
    }
  }

  async #fail(row, error) {
    const { strings, conflictPauseMs } = this.#config;

    try {
      if (error instanceof ConflictError) {
        // Toast first, pause so it can be read, only then replace the row with the server state.
        showToast({ type: 'error', message: error.message || strings.conflict, duration: conflictPauseMs + 2000 });
        await wait(conflictPauseMs, this.#abort.signal);
        this.#apply(row ?? this.#rowFor(error.payload?.id), error.payload);
        this.#emit('ywli:cell:conflict', { payload: error.payload });
      } else if (error instanceof ApiError && error.status === 404) {
        showToast({ type: 'error', message: strings.notFound, duration: conflictPauseMs + 2000 });
        await wait(conflictPauseMs, this.#abort.signal);
        this.#reloadGrid();
      } else {
        showToast({ type: 'error', message: error instanceof ApiError ? error.message : strings.saveFailed });
      }
    } catch (waitError) {
      if (waitError?.name !== 'AbortError') {
        throw waitError;
      }
    }
  }

  /** Swap refreshed cell HTML (server-rendered, same trust level as a GridField reload) and refresh etags. */
  #apply(row, payload) {
    if (!row || !payload) {
      return;
    }

    const focused = document.activeElement;

    for (const [column, html] of Object.entries(payload.cells ?? {})) {
      const cell = row.querySelector(`:scope > [data-ywli-column="${CSS.escape(column)}"]`);
      if (!cell || cell.innerHTML === html) {
        continue;
      }
      const hadFocus = cell.contains(focused);
      cell.innerHTML = html;
      if (hadFocus) {
        cell.querySelector(FOCUSABLE)?.focus({ preventScroll: true });
      }
    }

    // The etag is per record; every editable cell in the row must carry the new one.
    if (payload.etag) {
      for (const cell of row.querySelectorAll(':scope > [data-ywli-etag]')) {
        cell.dataset.ywliEtag = payload.etag;
      }
    }
  }

  // ------------------------------------------------------------------ undo

  #offerUndo(undo, key) {
    this.#undoToasts.get(key)?.dismiss();
    this.#undoToasts.set(key, showToast({
      message: undo.message,
      duration: undo.ttl * 1000,
      action: {
        label: undo.label,
        onClick: () => {
          this.#undoToasts.delete(key);
          void this.#undo(undo);
        },
      },
    }));
  }

  /** Deliberately not tied to the grid's abort signal: undo must keep working after the grid was reloaded. */
  async #undo(undo) {
    try {
      const payload = await undoPatch({ url: undo.url, securityID: this.#config.securityID });
      const row = this.#rowFor(payload.id);
      if (row) {
        this.#apply(row, payload);
        this.#emit('ywli:cell:undone', { id: payload.id, changes: payload.changes, payload });
      } else {
        this.#reloadGrid();
      }
      showToast({ message: this.#config.strings.undone, duration: 2500 });
    } catch (error) {
      await this.#fail(this.#rowFor(error?.payload?.id), error);
    }
  }

  // ------------------------------------------------------------------ helpers

  /** The record's row in the live grid (this instance's root, or the current one after a reload). */
  #rowFor(id) {
    if (id == null) {
      return null;
    }
    const root = this.#root.isConnected
      ? this.#root
      : document.querySelector(`.ss-gridfield[data-name="${CSS.escape(this.#gridName)}"]`);

    return root?.querySelector(`.ywli-editable[data-ywli-id="${CSS.escape(String(id))}"]`)?.closest('tr') ?? null;
  }

  #reloadGrid() {
    const root = this.#root.isConnected
      ? this.#root
      : document.querySelector(`.ss-gridfield[data-name="${CSS.escape(this.#gridName)}"]`);
    if (!root) {
      return;
    }
    // GridField.js registers `reload` in the Entwine 'ss' namespace.
    window.jQuery?.(root).entwine?.('ss')?.reload?.();
  }

  #serial(key, task) {
    const run = (this.#queues.get(key) ?? Promise.resolve()).then(task);
    const tail = run.catch(() => {});
    this.#queues.set(key, tail);
    tail.then(() => {
      if (this.#queues.get(key) === tail) {
        this.#queues.delete(key);
      }
    });
    return run;
  }

  #emit(name, detail) {
    this.#root.dispatchEvent(new CustomEvent(name, { bubbles: true, detail }));
  }
}

/** Feature factory used by entry.js. Returns a disposer. */
export function mountInlineEdit(root, config) {
  const instance = new InlineEdit(root, config);
  return () => instance.destroy();
}
