import { fill, gridName, reloadGrid, updateGridState } from '../core/grid.js';

/** grid name -> menu was open; a reload rebuilds the DOM, so reopen it to allow several toggles in a row. */
const openMenus = new Set();

class ColumnManager {
  #root;
  #config;
  #name;
  #abort = new AbortController();
  #button;
  #ownsButton = false;
  #menu = null;

  constructor(root, config) {
    this.#root = root;
    this.#name = gridName(root);
    this.#config = { stateKey: 'YWLIColumns', columns: [], ...config, strings: { columns: 'Columns', reset: 'Show all', ...config.strings } };

    this.#button = root.querySelector('[data-ywli-columns-toggle]');
    if (!this.#button) {
      this.#button = document.createElement('button');
      this.#button.type = 'button';
      this.#button.className = 'btn btn-secondary ywli-columns-toggle ywli-columns-toggle--floating';
      this.#button.dataset.ywliColumnsToggle = '';
      this.#button.textContent = this.#config.strings.columns;
      root.prepend(this.#button);
      this.#ownsButton = true;
    }

    const { signal } = this.#abort;
    this.#button.addEventListener('click', () => (this.#menu ? this.#close() : this.#open()), { signal });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && this.#menu) {
        this.#close();
        this.#button.focus();
      }
    }, { signal });
    document.addEventListener('click', (event) => {
      if (this.#menu && !this.#menu.contains(event.target) && !this.#button.contains(event.target)) this.#close();
    }, { signal });

    if (openMenus.has(this.#name)) this.#open();
  }

  destroy() {
    this.#abort.abort();
    this.#menu?.remove();
    if (this.#ownsButton) this.#button.remove();
    if (!this.#root.isConnected) openMenus.delete(this.#name);
  }

  #open() {
    const { columns, strings } = this.#config;
    const menu = document.createElement('div');
    menu.className = 'ywli-columns-menu';
    menu.setAttribute('role', 'group');
    menu.setAttribute('aria-label', strings.columns);

    for (const column of columns) {
      const label = menu.appendChild(document.createElement('label'));
      label.className = 'ywli-columns-menu__item';
      const input = label.appendChild(document.createElement('input'));
      input.type = 'checkbox';
      input.dataset.ywliColumn = column.name;
      input.checked = !column.hidden;
      input.disabled = Boolean(column.locked);
      label.append(document.createTextNode(` ${column.label || column.name}`));
      input.addEventListener('change', () => this.#apply(), { signal: this.#abort.signal });
    }

    const reset = menu.appendChild(document.createElement('button'));
    reset.type = 'button';
    reset.className = 'btn btn-sm btn-link ywli-columns-menu__reset';
    reset.textContent = strings.reset;
    reset.addEventListener('click', () => {
      for (const input of menu.querySelectorAll('input:not(:disabled)')) input.checked = true;
      this.#apply();
    }, { signal: this.#abort.signal });

    const rect = this.#button.getBoundingClientRect();
    menu.style.insetBlockStart = `${rect.bottom + 4}px`;
    menu.style.insetInlineEnd = `${Math.max(8, window.innerWidth - rect.right)}px`;

    this.#root.append(menu);
    this.#menu = menu;
    this.#button.setAttribute('aria-expanded', 'true');
    openMenus.add(this.#name);
  }

  #close() {
    this.#menu?.remove();
    this.#menu = null;
    this.#button.setAttribute('aria-expanded', 'false');
    openMenus.delete(this.#name);
  }

  #apply() {
    const hidden = [...this.#menu.querySelectorAll('input:not(:checked)')].map((input) => input.dataset.ywliColumn);
    const changed = updateGridState(this.#root, (state) => {
      state[this.#config.stateKey] = { ...state[this.#config.stateKey], Hidden: hidden.join(',') };
    });
    if (changed) {
      this.#root.dispatchEvent(new CustomEvent('ywli:columns:change', { bubbles: true, detail: { hidden } }));
      reloadGrid(this.#root, this.#name);
    }
  }
}

export function mountColumnManager(root, config) {
  const instance = new ColumnManager(root, config);
  return () => instance.destroy();
}
