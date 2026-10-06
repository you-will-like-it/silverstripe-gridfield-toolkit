import { readGridState } from '../core/grid.js';

/** Slave grid: shows a hint while no master row is selected (the server returns an empty list in that case). */
export function mountLinkedFilter(root, config) {
  const selected = Number(readGridState(root)[config.stateKey ?? 'YWLILinkedFilter']?.MasterID ?? 0) > 0;
  root.classList.toggle('ywli-linked--idle', !selected && Boolean(config.emptyUntilSelected));

  let hint = null;
  if (!selected && config.emptyUntilSelected && config.strings?.hint) {
    hint = document.createElement('p');
    hint.className = 'ywli-linked__hint';
    hint.textContent = config.strings.hint;
    const table = root.querySelector('table.grid-field__table');
    if (table) table.after(hint);
    else root.append(hint);
  }

  return () => {
    hint?.remove();
    if (!root.isConnected) root.classList.remove('ywli-linked--idle');
  };
}
