import { frameDocument, injectFrameCss, isDirty } from '../core/frame.js';
import { gridName, reloadGrid } from '../core/grid.js';

const DEFAULT_STRINGS = { close: 'Close', loading: 'Loading…', confirmLeave: 'This record has unsaved changes. Discard them?' };

/** Modal with the record's own edit form, switched to the tab that holds the relation grid. */
class NestedRelation {
  #root;
  #config;
  #name;
  #abort = new AbortController();
  #dialog = null;
  #frame = null;
  #touched = false;
  #loads = 0;

  constructor(root, config) {
    this.#root = root;
    this.#name = gridName(root);
    this.#config = { tab: '', ...config, strings: { ...DEFAULT_STRINGS, ...config.strings } };
    // Capture: keep the button click from reaching the row (edit form navigation).
    root.addEventListener('click', this.#onClick, { capture: true, signal: this.#abort.signal });
  }

  destroy() {
    this.#abort.abort();
    if (this.#dialog) this.#teardown();
  }

  #onClick = (event) => {
    const button = event.target instanceof Element ? event.target.closest('.ywli-nested__open') : null;
    if (!button || button.closest('.ss-gridfield') !== this.#root) return;
    event.preventDefault();
    event.stopPropagation();
    this.#open(button.dataset.ywliNestedUrl, button.dataset.ywliNestedTitle ?? '');
  };

  #open(url, title) {
    if (!url || this.#dialog) return;
    const { strings } = this.#config;
    const dialog = document.createElement('dialog');
    dialog.className = 'ywli-modal';
    dialog.setAttribute('aria-label', title || strings.loading);

    const bar = dialog.appendChild(document.createElement('div'));
    bar.className = 'ywli-modal__bar';
    bar.appendChild(document.createElement('strong')).textContent = title;
    const close = bar.appendChild(document.createElement('button'));
    close.type = 'button';
    close.className = 'ywli-modal__close';
    close.textContent = strings.close;
    close.addEventListener('click', () => this.#close());

    const frame = dialog.appendChild(document.createElement('iframe'));
    frame.className = 'ywli-modal__frame';
    frame.title = title || strings.loading;
    frame.addEventListener('load', () => this.#onLoad(frame));

    // Native <dialog> gives focus trapping and Esc; ask first when the form is dirty.
    dialog.addEventListener('cancel', (event) => {
      event.preventDefault();
      this.#close();
    });
    document.body.append(dialog);
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', '');

    this.#dialog = dialog;
    this.#frame = frame;
    this.#touched = false;
    this.#loads = 0;
    frame.src = url;
    document.addEventListener('keydown', this.#onKeydown, { signal: this.#abort.signal });
  }

  #onKeydown = (event) => {
    // Fallback for engines without showModal(); with it the dialog's own cancel event handles Esc.
    if (event.key === 'Escape' && this.#dialog && typeof this.#dialog.showModal !== 'function') this.#close();
  };

  #onLoad(frame) {
    const doc = frameDocument(frame);
    if (!doc || doc.location.href === 'about:blank') return;
    this.#loads += 1;
    if (this.#loads > 1) this.#touched = true; // a second load is a form submit

    injectFrameCss(doc);

    const { tab } = this.#config;
    if (tab) {
      const link = [...doc.querySelectorAll('a[href]')].find((a) => a.getAttribute('href')?.endsWith(`#${tab}`));
      link?.click();
    }
    frame.contentWindow?.jQuery?.(doc).on('ajaxComplete', (_e, xhr, settings) => {
      if (String(settings?.type).toUpperCase() !== 'GET' && xhr.status < 400) this.#touched = true;
    });
  }

  #close() {
    if (!this.#dialog) return;
    if (this.#frame && isDirty(this.#frame) && !window.confirm(this.#config.strings.confirmLeave)) return;
    const touched = this.#touched;
    this.#teardown();
    // Counts in the buttons may have changed.
    if (touched) reloadGrid(null, this.#name);
  }

  #teardown() {
    document.removeEventListener('keydown', this.#onKeydown);
    if (typeof this.#dialog.close === 'function' && this.#dialog.open) this.#dialog.close();
    this.#dialog.remove();
    this.#dialog = null;
    this.#frame = null;
  }
}

export function mountNestedRelation(root, config) {
  const instance = new NestedRelation(root, config);
  return () => instance.destroy();
}
