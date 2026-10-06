import { frameDocument, injectFrameCss, isDirty } from '../core/frame.js';
import { gridName, liveRoot, reloadGrid } from '../core/grid.js';

const DEFAULT_STRINGS = {
  close: 'Close',
  loading: 'Loading…',
  title: 'Record details',
  confirmLeave: 'This record has unsaved changes. Discard them?',
};

const CONTROL = 'a, button, input, select, textarea, label, summary, .ywli-toggle, .ywli-value, .ywli-input, .ywli-accordion__panel';
const OPEN_LINK = 'a.edit-link, a.view-link';
const ITEM_PATH = /\/item\/(\d+)(?:\/|$|\?)/;

/** One panel per grid name. It lives outside the GridField root (a sibling), so reloads leave it alone. */
const panels = new Map();

class Panel {
  #name;
  #config;
  #aside;
  #frame;
  #status;
  #itemBase = '';
  #opened = false;
  #touched = false;
  #reloadTimer = 0;
  #id = null;

  constructor(root, config) {
    this.#name = gridName(root);
    this.#config = { width: 50, ...config, strings: { ...DEFAULT_STRINGS, ...config.strings } };

    const { strings } = this.#config;
    this.#aside = document.createElement('aside');
    this.#aside.className = 'ywli-detail';
    this.#aside.hidden = true;
    this.#aside.setAttribute('aria-label', strings.title);
    this.#aside.style.setProperty('--ywli-detail-width', String(this.#config.width));

    const bar = this.#aside.appendChild(document.createElement('div'));
    bar.className = 'ywli-detail__bar';
    this.#status = bar.appendChild(document.createElement('span'));
    this.#status.className = 'ywli-detail__status';
    const close = bar.appendChild(document.createElement('button'));
    close.type = 'button';
    close.className = 'ywli-detail__close';
    close.textContent = strings.close;
    close.addEventListener('click', () => this.close());

    this.#frame = this.#aside.appendChild(document.createElement('iframe'));
    this.#frame.className = 'ywli-detail__frame';
    this.#frame.title = strings.title;
    this.#frame.addEventListener('load', this.#onFrameLoad);

    root.after(this.#aside);
    document.addEventListener('keydown', this.#onKeydown);
  }

  /** False once the page the panel was attached to is gone (CMS navigation without our unmount firing). */
  get attached() {
    return this.#aside.isConnected;
  }

  get isOpen() {
    return !this.#aside.hidden;
  }

  /** Re-attach after a reload cannot detach us, but the root element can change if the whole form is re-rendered. */
  adopt(root) {
    if (this.#aside.previousElementSibling !== root) {
      root.after(this.#aside);
    }
    this.#syncRoot();
  }

  open(url, id) {
    if (this.isOpen && String(this.#id) === String(id)) {
      return;
    }
    if (this.isOpen && !this.#confirmDiscard()) {
      return;
    }
    const itemIndex = new URL(url, location.href).pathname.search(/\/item\/\d+/);
    this.#itemBase = itemIndex >= 0 ? new URL(url, location.href).pathname.slice(0, itemIndex) : '';
    this.#id = id;
    this.#opened = false;
    this.#touched = false;
    this.#aside.hidden = false;
    this.#aside.setAttribute('aria-busy', 'true');
    this.#status.textContent = this.#config.strings.loading;
    this.#frame.src = url;
    this.#syncRoot();
  }

  close({ force = false } = {}) {
    if (!this.isOpen || (!force && !this.#confirmDiscard())) {
      return;
    }
    clearTimeout(this.#reloadTimer);
    this.#aside.hidden = true;
    this.#frame.src = 'about:blank';
    this.#id = null;
    this.#syncRoot();
    if (this.#touched) {
      reloadGrid(null, this.#name);
    }
    liveRoot(null, this.#name)?.focus?.();
  }

  destroy() {
    clearTimeout(this.#reloadTimer);
    document.removeEventListener('keydown', this.#onKeydown);
    this.#frame.removeEventListener('load', this.#onFrameLoad);
    this.#aside.remove();
    panels.delete(this.#name);
    const root = liveRoot(null, this.#name);
    root?.classList.remove('ywli-split-active');
  }

  #syncRoot() {
    const root = liveRoot(null, this.#name);
    if (!root) {
      return;
    }
    root.classList.toggle('ywli-split-active', this.isOpen);
    root.style.setProperty('--ywli-detail-width', String(this.#config.width));
    for (const row of root.querySelectorAll('.ywli-detail-active')) {
      row.classList.remove('ywli-detail-active');
    }
    if (this.isOpen && this.#id !== null) {
      for (const link of root.querySelectorAll(OPEN_LINK)) {
        if (ITEM_PATH.exec(link.getAttribute('href') ?? '')?.[1] === String(this.#id)) {
          link.closest('tr')?.classList.add('ywli-detail-active');
        }
      }
    }
  }

  #isDirty() {
    return isDirty(this.#frame);
  }

  #confirmDiscard() {
    return !this.#isDirty() || window.confirm(this.#config.strings.confirmLeave);
  }

  #scheduleReload() {
    this.#touched = false;
    clearTimeout(this.#reloadTimer);
    this.#reloadTimer = setTimeout(() => reloadGrid(null, this.#name), 300);
  }

  #onFrameLoad = () => {
    const doc = frameDocument(this.#frame);
    if (!doc || this.#frame.src === 'about:blank' || doc.location.href === 'about:blank') {
      return;
    }
    this.#aside.removeAttribute('aria-busy');
    this.#status.textContent = '';

    const { pathname } = doc.location;
    if (!pathname.startsWith(`${this.#itemBase}/item/`)) {
      // Left the record (delete redirects to the list, a breadcrumb was followed...): close and refresh the list.
      this.close({ force: true });
      reloadGrid(null, this.#name);
      return;
    }

    const match = ITEM_PATH.exec(pathname);
    if (match) {
      this.#id = match[1];
    }

    injectFrameCss(doc);
    doc.addEventListener('keydown', this.#onKeydown);

    if (this.#opened) {
      // A second load inside the panel is a form submit that re-rendered the page (save, publish...).
      this.#scheduleReload();
    }
    this.#opened = true;

    // Saves done via the CMS's AJAX layer do not reload the frame, so watch them too.
    const jq = this.#frame.contentWindow?.jQuery;
    jq?.(doc).on('ajaxComplete', (_event, xhr, settings) => {
      if (String(settings?.type).toUpperCase() === 'POST' && xhr.status < 400) {
        this.#scheduleReload();
      }
    });
    this.#syncRoot();
  };

  #onKeydown = (event) => {
    if (event.key === 'Escape' && this.isOpen && !event.defaultPrevented) {
      this.close();
    }
  };
}

class MasterDetail {
  #root;
  #abort = new AbortController();
  #panel;

  constructor(root, config) {
    this.#root = root;
    const name = gridName(root);
    let panel = panels.get(name);
    if (panel && !panel.attached) {
      panel.destroy();
      panel = undefined;
    }
    if (panel) {
      panel.adopt(root);
    } else {
      panel = new Panel(root, config);
      panels.set(name, panel);
    }
    this.#panel = panel;

    const { signal } = this.#abort;
    root.addEventListener('click', this.#onClick, { capture: true, signal });
    // Other views (Kanban) ask to open a record; cancelling the event means "handled here".
    root.addEventListener('ywli:record:open', this.#onOpenRequest, { signal });
  }

  destroy() {
    this.#abort.abort();
    if (!this.#root.isConnected) {
      this.#panel.destroy();
    }
  }

  #onOpenRequest = (event) => {
    const { id, url } = event.detail ?? {};
    if (url && !event.defaultPrevented) {
      event.preventDefault();
      this.#panel.open(url, id);
    }
  };

  #onClick = (event) => {
    if (event.button !== 0 || event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
      return;
    }
    const target = event.target instanceof Element ? event.target : null;
    const row = target?.closest('tr.ss-gridfield-item');
    if (!row || row.closest('.ss-gridfield') !== this.#root) {
      return;
    }

    const link = row.querySelector(OPEN_LINK);
    const control = target.closest(CONTROL);
    // Controls keep their behaviour; only the row itself and its own edit/view link open the panel.
    if (!link || (control && !control.matches(OPEN_LINK))) {
      return;
    }

    event.preventDefault();
    event.stopPropagation();
    this.#panel.open(link.href, ITEM_PATH.exec(link.href)?.[1] ?? null);
  };
}

export function mountMasterDetail(root, config) {
  const instance = new MasterDetail(root, config);
  return () => instance.destroy();
}
