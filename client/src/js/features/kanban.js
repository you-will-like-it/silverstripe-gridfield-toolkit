import { ConflictError, fetchBoard, moveCard } from '../core/api.js';
import { gridName, store } from '../core/grid.js';
import { showToast } from '../core/toast.js';

const DEFAULT_STRINGS = {
  board: 'Board',
  list: 'List',
  loading: 'Loading…',
  loadFailed: 'Could not load the board.',
  moveFailed: 'Could not move the card.',
  conflict: 'This card was changed by someone else. Board refreshed.',
  truncated: 'Only the first cards are shown.',
  empty: 'No cards',
  moved: 'Moved to',
  help: 'Drag cards between lanes, or focus a card and press Alt + Left / Right.',
};

const el = (tag, className, text) => {
  const node = document.createElement(tag);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
};

class Kanban {
  #root;
  #config;
  #name;
  #abort = new AbortController();
  #load = null;
  #board;
  #live;
  #button;
  #ownsButton = false;
  #state = { columns: [], cards: [], truncated: false };
  #dragId = null;
  #pending = new Set();

  constructor(root, config) {
    this.#root = root;
    this.#name = gridName(root);
    this.#config = { ...config, strings: { ...DEFAULT_STRINGS, ...config.strings } };

    this.#button = root.querySelector('[data-ywli-kanban-toggle]');
    if (!this.#button) {
      this.#button = el('button', 'btn btn-secondary ywli-kanban-toggle ywli-kanban-toggle--floating');
      this.#button.type = 'button';
      this.#button.dataset.ywliKanbanToggle = '';
      root.prepend(this.#button);
      this.#ownsButton = true;
    }

    this.#board = el('div', 'ywli-kanban');
    this.#board.hidden = true;
    this.#board.setAttribute('aria-label', this.#config.strings.board);
    this.#live = el('div', 'ywli-sr-only');
    this.#live.setAttribute('aria-live', 'polite');
    root.append(this.#board, this.#live);

    const { signal } = this.#abort;
    this.#button.addEventListener('click', () => this.#setActive(!this.active), { signal });
    this.#board.addEventListener('dragstart', this.#onDragStart, { signal });
    this.#board.addEventListener('dragend', this.#onDragEnd, { signal });
    this.#board.addEventListener('dragover', this.#onDragOver, { signal });
    this.#board.addEventListener('dragleave', this.#onDragLeave, { signal });
    this.#board.addEventListener('drop', this.#onDrop, { signal });
    this.#board.addEventListener('click', this.#onClick, { signal });
    this.#board.addEventListener('keydown', this.#onKeydown, { signal });

    this.#setActive(store.get(`view:${this.#name}`) === 'board', { persist: false });
  }

  get active() {
    return this.#root.classList.contains('ywli-kanban-active');
  }

  destroy() {
    this.#abort.abort();
    this.#load?.abort();
    if (this.#ownsButton) this.#button.remove();
    if (!this.#root.isConnected) this.#root.classList.remove('ywli-kanban-active');
  }

  #setActive(active, { persist = true } = {}) {
    this.#root.classList.toggle('ywli-kanban-active', active);
    this.#board.hidden = !active;
    this.#button.setAttribute('aria-pressed', String(active));
    this.#button.textContent = active ? this.#config.strings.list : this.#config.strings.board;
    if (persist) store.set(`view:${this.#name}`, active ? 'board' : 'list');
    if (active) this.refresh();
  }

  async refresh() {
    this.#load?.abort();
    const controller = new AbortController();
    this.#load = controller;
    this.#board.setAttribute('aria-busy', 'true');
    if (!this.#state.columns.length) this.#board.textContent = this.#config.strings.loading;
    try {
      const { columns, cards, truncated } = await fetchBoard({ url: this.#config.dataUrl, signal: controller.signal });
      this.#state = { columns, cards, truncated };
      this.#render();
    } catch (error) {
      if (error?.name === 'AbortError') return;
      this.#board.textContent = this.#config.strings.loadFailed;
    } finally {
      if (this.#load === controller) this.#board.removeAttribute('aria-busy');
    }
  }

  // ------------------------------------------------------------------ rendering

  #render() {
    const focusedId = this.#board.contains(document.activeElement)
      ? document.activeElement.closest?.('.ywli-kanban__card')?.dataset.id
      : null;
    const { strings } = this.#config;
    const frag = document.createDocumentFragment();
    frag.append(el('p', 'ywli-kanban__help', strings.help));
    if (this.#state.truncated) frag.append(el('p', 'ywli-kanban__notice', strings.truncated));

    const lanes = el('div', 'ywli-kanban__lanes');
    for (const column of this.#state.columns) {
      const cards = this.#state.cards.filter((card) => card.group === column.value);
      const lane = el('section', 'ywli-kanban__lane');
      lane.dataset.value = column.value;
      lane.dataset.locked = String(Boolean(column.locked));
      const heading = el('h3', 'ywli-kanban__heading');
      heading.append(el('span', 'ywli-kanban__label', column.label), el('span', 'ywli-kanban__count', String(cards.length)));
      const list = el('ul', 'ywli-kanban__cards');
      list.setAttribute('aria-label', column.label);
      for (const card of cards) list.append(this.#card(card));
      if (!cards.length) list.append(el('li', 'ywli-kanban__empty', strings.empty));
      lane.append(heading, list);
      lanes.append(lane);
    }
    frag.append(lanes);
    this.#board.replaceChildren(frag);

    if (focusedId) this.#cardNode(focusedId)?.focus();
  }

  #card(card) {
    const node = el('li', 'ywli-kanban__card');
    node.dataset.id = String(card.id);
    node.tabIndex = 0;
    node.draggable = card.canEdit && !this.#pending.has(card.id);
    if (this.#pending.has(card.id)) node.setAttribute('aria-busy', 'true');
    node.append(el('div', 'ywli-kanban__title', card.title || `#${card.id}`));
    for (const { label, value } of card.fields) {
      if (value === '') continue;
      const line = el('div', 'ywli-kanban__field');
      line.append(el('span', 'ywli-kanban__field-label', `${label}: `), document.createTextNode(value));
      node.append(line);
    }
    return node;
  }

  #cardNode(id) {
    return this.#board.querySelector(`.ywli-kanban__card[data-id="${CSS.escape(String(id))}"]`);
  }

  #findCard(id) {
    return this.#state.cards.find((card) => String(card.id) === String(id));
  }

  // ------------------------------------------------------------------ moving

  async #move(card, to) {
    const from = card.group;
    const column = this.#state.columns.find((c) => c.value === to);
    if (!card.canEdit || !column || column.locked || from === to || this.#pending.has(card.id)) return;

    const { strings } = this.#config;
    card.group = to; // optimistic
    this.#pending.add(card.id);
    this.#render();
    this.#announce(`${card.title} · ${strings.moved} ${column.label}`);
    this.#cardNode(card.id)?.focus();

    try {
      await moveCard({ url: this.#config.moveUrl, securityID: this.#config.securityID, id: card.id, to, from });
      this.#root.dispatchEvent(new CustomEvent('ywli:card:moved', { bubbles: true, detail: { id: card.id, from, to } }));
    } catch (error) {
      if (error instanceof ConflictError) {
        showToast({ message: strings.conflict, type: 'error' });
        this.#pending.delete(card.id);
        await this.refresh();
        return;
      }
      card.group = from; // roll back
      showToast({ message: error?.message && error.status ? error.message : strings.moveFailed, type: 'error' });
    }
    this.#pending.delete(card.id);
    this.#render();
  }

  #announce(message) {
    this.#live.textContent = '';
    // A changed text node is what makes screen readers speak; clear first so identical messages repeat.
    queueMicrotask(() => { this.#live.textContent = message; });
  }

  // ------------------------------------------------------------------ drag & drop

  #laneFor(event) {
    return event.target instanceof Element ? event.target.closest('.ywli-kanban__lane') : null;
  }

  #onDragStart = (event) => {
    const node = event.target instanceof Element ? event.target.closest('.ywli-kanban__card') : null;
    if (!node || node.draggable !== true) return;
    this.#dragId = node.dataset.id;
    node.classList.add('ywli-kanban__card--dragging');
    if (event.dataTransfer) {
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', this.#dragId); // Firefox needs data to start a drag
    }
  };

  #onDragEnd = () => {
    this.#dragId = null;
    for (const node of this.#board.querySelectorAll('.ywli-kanban__card--dragging, .ywli-kanban__lane--over')) {
      node.classList.remove('ywli-kanban__card--dragging', 'ywli-kanban__lane--over');
    }
  };

  #onDragOver = (event) => {
    const lane = this.#laneFor(event);
    if (!lane || this.#dragId === null || lane.dataset.locked === 'true') return;
    event.preventDefault(); // allows the drop
    if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
    lane.classList.add('ywli-kanban__lane--over');
  };

  #onDragLeave = (event) => {
    const lane = this.#laneFor(event);
    if (lane && !lane.contains(event.relatedTarget)) lane.classList.remove('ywli-kanban__lane--over');
  };

  #onDrop = (event) => {
    const lane = this.#laneFor(event);
    const card = this.#dragId === null ? null : this.#findCard(this.#dragId);
    this.#onDragEnd();
    if (!lane || !card || lane.dataset.locked === 'true') return;
    event.preventDefault();
    this.#move(card, lane.dataset.value);
  };

  // ------------------------------------------------------------------ open + keyboard

  #open(card) {
    const open = new CustomEvent('ywli:record:open', {
      bubbles: true,
      cancelable: true,
      detail: { id: card.id, url: card.link },
    });
    this.#cardNode(card.id)?.dispatchEvent(open);
    if (!open.defaultPrevented && card.link) window.location.href = card.link;
  }

  #onClick = (event) => {
    const node = event.target instanceof Element ? event.target.closest('.ywli-kanban__card') : null;
    const card = node && this.#findCard(node.dataset.id);
    if (card) this.#open(card);
  };

  #onKeydown = (event) => {
    const node = event.target instanceof Element ? event.target.closest('.ywli-kanban__card') : null;
    const card = node && this.#findCard(node.dataset.id);
    if (!card) return;

    const lanes = [...this.#board.querySelectorAll('.ywli-kanban__lane')];
    const laneIndex = lanes.indexOf(node.closest('.ywli-kanban__lane'));
    const cardsOf = (lane) => [...lane.querySelectorAll('.ywli-kanban__card')];

    if (event.key === 'Enter') {
      event.preventDefault();
      this.#open(card);
    } else if (event.altKey && (event.key === 'ArrowLeft' || event.key === 'ArrowRight')) {
      event.preventDefault();
      const step = event.key === 'ArrowRight' ? 1 : -1;
      // Skip locked lanes so the shortcut never lands on a lane that refuses the card.
      for (let i = laneIndex + step; i >= 0 && i < lanes.length; i += step) {
        if (lanes[i].dataset.locked !== 'true') {
          this.#move(card, lanes[i].dataset.value);
          break;
        }
      }
    } else if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
      event.preventDefault();
      const siblings = cardsOf(lanes[laneIndex]);
      siblings[siblings.indexOf(node) + (event.key === 'ArrowDown' ? 1 : -1)]?.focus();
    } else if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
      event.preventDefault();
      const index = cardsOf(lanes[laneIndex]).indexOf(node);
      const target = cardsOf(lanes[laneIndex + (event.key === 'ArrowRight' ? 1 : -1)] ?? lanes[laneIndex]);
      target[Math.min(index, target.length - 1)]?.focus();
    }
  };
}

export function mountKanban(root, config) {
  const instance = new Kanban(root, config);
  return () => instance.destroy();
}
