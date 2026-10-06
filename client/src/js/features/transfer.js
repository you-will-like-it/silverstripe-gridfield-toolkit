import { ApiError, transferRecords } from '../core/api.js';
import { fill, gridName, ownedBy, reloadGrid, rowId } from '../core/grid.js';
import { getSelection } from '../core/selection.js';
import { showToast } from '../core/toast.js';

/** The drag in flight (drag data is unreadable during dragover, so the target consults this). */
let current = null;

export function mountTransferSource(root) {
  const name = gridName(root);
  const abort = new AbortController();
  const { signal } = abort;

  const rows = () => [...root.querySelectorAll('tr.ss-gridfield-item')].filter((row) => ownedBy(root, row) && rowId(row));
  for (const row of rows()) row.draggable = true;

  root.addEventListener('dragstart', (event) => {
    const row = event.target instanceof Element ? event.target.closest('tr.ss-gridfield-item') : null;
    if (!row || !ownedBy(root, row) || !rowId(row)) return;
    const id = String(rowId(row));
    const stash = getSelection(name);
    // Dragging a stashed row carries the whole stash; any other row travels alone.
    const ids = stash.has(id) ? [...stash] : [id];
    current = { source: name, ids };
    row.classList.add('ywli-transfer-dragging');
    if (event.dataTransfer) {
      event.dataTransfer.effectAllowed = 'copyMove';
      event.dataTransfer.setData('text/plain', ids.join(','));
    }
  }, { signal });

  root.addEventListener('dragend', () => {
    current = null;
    for (const row of root.querySelectorAll('.ywli-transfer-dragging')) row.classList.remove('ywli-transfer-dragging');
  }, { signal });

  return () => abort.abort();
}

export function mountTransferTarget(root, config) {
  const name = gridName(root);
  const strings = config.strings ?? {};
  const abort = new AbortController();
  const { signal } = abort;
  let menu = null;

  const accepts = () => current !== null && config.accept.includes(current.source) && current.source !== name;
  const closeMenu = () => {
    menu?.remove();
    menu = null;
  };

  async function send(drag, mode) {
    closeMenu();
    try {
      const result = await transferRecords({ url: config.url, securityID: config.securityID, source: drag.source, ids: drag.ids.map(Number), mode });
      const failed = result.failed.length;
      showToast({
        message: fill(failed ? strings.partial : strings.done, { count: result.processed, failed }),
        type: failed ? 'error' : 'success',
        duration: failed ? 8000 : 4000,
      });
      const failedIds = new Set(result.failed.map((f) => String(f.id)));
      const stash = getSelection(drag.source);
      for (const id of drag.ids) if (!failedIds.has(String(id))) stash.delete(String(id));
      root.dispatchEvent(new CustomEvent('ywli:transfer:done', { bubbles: true, detail: { mode, source: drag.source, ...result } }));
      if (result.processed > 0) {
        reloadGrid(null, name);
        reloadGrid(null, drag.source);
      }
    } catch (error) {
      showToast({ message: error instanceof ApiError && error.status ? error.message : strings.failed, type: 'error' });
    }
  }

  root.addEventListener('dragover', (event) => {
    if (!accepts()) return;
    event.preventDefault();
    if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
    root.classList.add('ywli-drop-over');
  }, { signal });

  root.addEventListener('dragleave', (event) => {
    if (!root.contains(event.relatedTarget)) root.classList.remove('ywli-drop-over');
  }, { signal });

  root.addEventListener('drop', (event) => {
    root.classList.remove('ywli-drop-over');
    if (!accepts()) return;
    event.preventDefault();
    const drag = current;
    current = null;

    if (config.modes.length === 1) {
      send(drag, config.modes[0]);
      return;
    }

    closeMenu();
    menu = document.createElement('div');
    menu.className = 'ywli-transfer-menu';
    menu.setAttribute('role', 'menu');
    for (const mode of config.modes) {
      const button = menu.appendChild(document.createElement('button'));
      button.type = 'button';
      button.className = 'btn btn-sm btn-secondary';
      button.setAttribute('role', 'menuitem');
      button.dataset.ywliMode = mode;
      button.textContent = strings[mode] ?? mode;
      button.addEventListener('click', () => send(drag, mode), { signal });
    }
    const cancel = menu.appendChild(document.createElement('button'));
    cancel.type = 'button';
    cancel.className = 'btn btn-sm btn-link';
    cancel.textContent = strings.cancel ?? 'Cancel';
    cancel.addEventListener('click', closeMenu, { signal });
    root.append(menu);
    menu.querySelector('button')?.focus();
  }, { signal });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && menu) closeMenu();
  }, { signal });

  return () => {
    abort.abort();
    closeMenu();
    root.classList.remove('ywli-drop-over');
  };
}
