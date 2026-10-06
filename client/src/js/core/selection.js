/** Stash selections per grid name. Module state, so it survives GridField reloads (which rebuild the DOM). */
const selections = new Map();

export function getSelection(name) {
  let set = selections.get(name);
  if (!set) {
    set = new Set();
    selections.set(name, set);
  }
  return set;
}

export const clearSelection = (name) => selections.delete(name);
