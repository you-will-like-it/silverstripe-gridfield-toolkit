import { gridName } from '../core/grid.js';

const CLASS = 'ywli-fullscreen';
const HTML_CLASS = 'ywli-has-fullscreen';

const DEFAULT_STRINGS = { enter: 'Fullscreen', exit: 'Exit fullscreen' };

function syncDocument() {
  document.documentElement.classList.toggle(HTML_CLASS, document.querySelector(`.${CLASS}`) !== null);
}

class Fullscreen {
  #root;
  #strings;
  #button;
  #ownsButton = false;
  #abort = new AbortController();

  constructor(root, config) {
    this.#root = root;
    this.#strings = { ...DEFAULT_STRINGS, ...config.strings };

    this.#button = root.querySelector('[data-ywli-fullscreen-toggle]');
    if (!this.#button) {
      // The config has no button row for the PHP fragment: fall back to a floating button.
      this.#button = document.createElement('button');
      this.#button.type = 'button';
      this.#button.className = 'btn btn-secondary ywli-fullscreen-toggle ywli-fullscreen-toggle--floating';
      this.#button.dataset.ywliFullscreenToggle = '';
      root.prepend(this.#button);
      this.#ownsButton = true;
    }

    const { signal } = this.#abort;
    this.#button.addEventListener('click', () => this.toggle(), { signal });
    document.addEventListener('keydown', this.#onKeydown, { signal });
    this.#render();
  }

  get active() {
    return this.#root.classList.contains(CLASS);
  }

  toggle(force = !this.active) {
    this.#root.classList.toggle(CLASS, force);
    this.#render();
    this.#root.dispatchEvent(new CustomEvent('ywli:fullscreen', { bubbles: true, detail: { active: force } }));
  }

  destroy() {
    this.#abort.abort();
    if (this.#ownsButton) {
      this.#button.remove();
    }
    // A reload replaces our marker but keeps the root (and its fullscreen state). Only clean up if the grid is gone.
    if (!this.#root.isConnected) {
      this.#root.classList.remove(CLASS);
      syncDocument();
    }
  }

  #render() {
    const { active } = this;
    this.#button.setAttribute('aria-pressed', String(active));
    this.#button.textContent = active ? this.#strings.exit : this.#strings.enter;
    if (active) {
      this.#root.setAttribute('role', 'region');
      if (!this.#root.hasAttribute('aria-label')) {
        this.#root.setAttribute('aria-label', gridName(this.#root));
      }
    }
    syncDocument();
  }

  #onKeydown = (event) => {
    // Only the topmost layer reacts: an open editor or a dialog handles its own Escape first.
    if (event.key === 'Escape' && this.active && !event.defaultPrevented) {
      this.toggle(false);
      this.#button.focus();
    }
  };
}

export function mountFullscreen(root, config) {
  const instance = new Fullscreen(root, config);
  return () => instance.destroy();
}
