import { ApiError, fetchAccordion } from '../core/api.js';
import { gridName, ownedBy } from '../core/grid.js';

const DEFAULT_STRINGS = {
  expand: 'Show details',
  collapse: 'Hide details',
  loading: 'Loading…',
  loadFailed: 'Could not load the details.',
};

/** grid name -> ids that were open; survives reloads (rows are re-rendered, the module is not). */
const remembered = new Map();

class Accordion {
  #root;
  #config;
  #name;
  #abort = new AbortController();
  /** id -> AbortController of the in-flight load */
  #loads = new Map();

  constructor(root, config) {
    this.#root = root;
    this.#name = gridName(root);
    this.#config = { multiple: true, ...config, strings: { ...DEFAULT_STRINGS, ...config.strings } };

    const { signal } = this.#abort;
    // Capture: keep the row's own click (edit form navigation) from firing for chevron clicks.
    root.addEventListener('click', this.#onClick, { capture: true, signal });

    for (const id of remembered.get(this.#name) ?? []) {
      const toggle = this.#toggleFor(id);
      if (toggle) {
        this.#open(toggle, { restore: true });
      }
    }
  }

  destroy() {
    this.#abort.abort();
    for (const controller of this.#loads.values()) {
      controller.abort();
    }
    this.#loads.clear();
    if (!this.#root.isConnected) {
      remembered.delete(this.#name);
    }
  }

  #toggleFor(id) {
    return this.#root.querySelector(`.ywli-accordion__toggle[data-ywli-accordion-id="${CSS.escape(String(id))}"]`);
  }

  #onClick = (event) => {
    const toggle = event.target.closest?.('.ywli-accordion__toggle');
    if (!toggle || !ownedBy(this.#root, toggle)) {
      return;
    }
    event.preventDefault();
    event.stopPropagation();

    if (toggle.getAttribute('aria-expanded') === 'true') {
      this.#close(toggle);
    } else {
      if (!this.#config.multiple) {
        for (const other of this.#root.querySelectorAll('.ywli-accordion__toggle[aria-expanded="true"]')) {
          this.#close(other);
        }
      }
      this.#open(toggle);
    }
  };

  #setOpenIds(mutate) {
    const ids = remembered.get(this.#name) ?? new Set();
    mutate(ids);
    remembered.set(this.#name, ids);
  }

  #close(toggle) {
    const id = toggle.dataset.ywliAccordionId;
    this.#loads.get(id)?.abort();
    this.#loads.delete(id);
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-label', this.#config.strings.expand);
    toggle.closest('tr')?.nextElementSibling?.matches('.ywli-accordion__row') && toggle.closest('tr').nextElementSibling.remove();
    this.#setOpenIds((ids) => ids.delete(id));
  }

  async #open(toggle, { restore = false } = {}) {
    const id = toggle.dataset.ywliAccordionId;
    const row = toggle.closest('tr');
    if (!row) {
      return;
    }

    const detail = document.createElement('tr');
    detail.className = 'ywli-accordion__row';
    const cell = detail.appendChild(document.createElement('td'));
    cell.colSpan = row.cells.length;
    cell.className = 'ywli-accordion__panel';
    cell.setAttribute('role', 'region');
    cell.setAttribute('aria-busy', 'true');
    cell.textContent = this.#config.strings.loading;
    row.after(detail);

    toggle.setAttribute('aria-expanded', 'true');
    toggle.setAttribute('aria-label', this.#config.strings.collapse);
    this.#setOpenIds((ids) => ids.add(id));

    const controller = new AbortController();
    this.#loads.set(id, controller);
    try {
      const { html } = await fetchAccordion({ url: this.#config.url, id, signal: controller.signal });
      // Server-rendered by the developer's renderer (trusted markup, see GridFieldAccordion docs).
      cell.innerHTML = html;
      cell.removeAttribute('aria-busy');
      cell.dispatchEvent(new CustomEvent('ywli:accordion:loaded', { bubbles: true, detail: { id } }));
    } catch (error) {
      if (error?.name === 'AbortError') {
        return;
      }
      cell.removeAttribute('aria-busy');
      cell.textContent = error instanceof ApiError && error.status === 404
        ? error.message
        : this.#config.strings.loadFailed;
      if (restore && error instanceof ApiError && [403, 404].includes(error.status)) {
        this.#close(toggle);
      }
    } finally {
      if (this.#loads.get(id) === controller) {
        this.#loads.delete(id);
      }
    }
  }
}

export function mountAccordion(root, config) {
  const instance = new Accordion(root, config);
  return () => instance.destroy();
}
