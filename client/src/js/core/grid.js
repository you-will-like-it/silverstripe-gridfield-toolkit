/** Helpers shared by the layout features. A GridField root persists across reloads; its children do not. */

export const gridName = (root) => root.dataset.name ?? '';

/** The connected root for a grid, even if the element we captured at mount time has been replaced. */
export function liveRoot(root, name = gridName(root)) {
  if (root?.isConnected) {
    return root;
  }
  return name ? document.querySelector(`.ss-gridfield[data-name="${CSS.escape(name)}"]`) : null;
}

/** Re-renders the GridField through the admin's own Entwine `reload`. */
export function reloadGrid(root, name = gridName(root)) {
  // GridField.js registers `reload` in the Entwine 'ss' namespace.
  const live = liveRoot(root, name);
  if (live) {
    window.jQuery?.(live).entwine?.('ss')?.reload?.();
  }
}

/** localStorage that never throws (private mode, blocked storage). */
export const store = {
  get(key) {
    try {
      return window.localStorage.getItem(`ywli:${key}`);
    } catch {
      return null;
    }
  },
  set(key, value) {
    try {
      window.localStorage.setItem(`ywli:${key}`, value);
    } catch {
      /* best effort */
    }
  },
};

/** True for events from a nested GridField, which mounts its own feature instances. */
export const ownedBy = (root, node) => node?.closest?.('.ss-gridfield') === root;

/** Rewrites the GridState JSON a GridField posts with every request (`input.gridstate`). Returns false if absent. */
export function updateGridState(root, mutate) {
  const input = root?.querySelector('input.gridstate');
  if (!input) {
    return false;
  }
  let state;
  try {
    state = JSON.parse(input.value || '{}') ?? {};
  } catch {
    state = {};
  }
  mutate(state);
  input.value = JSON.stringify(state);
  return true;
}

export function readGridState(root) {
  try {
    return JSON.parse(root?.querySelector('input.gridstate')?.value || '{}') ?? {};
  } catch {
    return {};
  }
}

export const rowId = (row) =>
  row.dataset.id
  ?? /\/item\/(\d+)/.exec(row.querySelector('a.edit-link, a.view-link')?.getAttribute('href') ?? '')?.[1]
  ?? null;

export const fill = (template, values) => String(template).replace(/\{(\w+)\}/g, (_, key) => String(values[key] ?? ''));

/** Elements that keep their own click behaviour inside a row. */
export const ROW_CONTROL = 'a, button, input, select, textarea, label, summary, .ywli-toggle, .ywli-value, .ywli-input, .ywli-accordion__panel';
