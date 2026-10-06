import { fill } from '../core/grid.js';

let counter = 0;

const headerFor = (root, name) => {
  const normalizedName = name.replace(/\./g, '-');
  const sortableName = `SetOrder${normalizedName}`;
  const classes = [
    `col-${name}`,
    `col-${name.replace(/[^\w]/g, '-')}`,
    `col-${sortableName}`,
    `col-action_${sortableName}`,
  ];
  return [...root.querySelectorAll('thead th')].find((th) => classes.some((c) => th.classList.contains(c))) ?? null;
};

/** "?" next to column headers. Text only (textContent); hover/focus shows, click pins, Esc closes. */
export function mountHeaderHelp(root, config) {
  const abort = new AbortController();
  const { signal } = abort;
  const label = config.strings?.label ?? 'Help for column {column}';
  let tip = null;
  let pinned = null;

  const hide = () => {
    tip?.remove();
    tip = null;
    pinned = null;
  };
  const show = (button, text) => {
    hide();
    tip = document.createElement('div');
    tip.className = 'ywli-help__tip';
    tip.id = `ywli-help-${++counter}`;
    tip.setAttribute('role', 'tooltip');
    tip.textContent = text;
    const rect = button.getBoundingClientRect();
    tip.style.insetBlockStart = `${rect.bottom + 6}px`;
    tip.style.insetInlineStart = `${Math.max(8, Math.min(rect.left, window.innerWidth - 280))}px`;
    document.body.append(tip);
    button.setAttribute('aria-describedby', tip.id);
  };

  const buttons = [];
  for (const [name, text] of Object.entries(config.help ?? {})) {
    const th = headerFor(root, name);
    if (!th || th.querySelector('.ywli-help')) continue;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'ywli-help ywli-help__button';
    button.textContent = '?';
    button.setAttribute('aria-label', fill(label, { column: th.textContent.trim() || name }));
    button.addEventListener('mouseenter', () => !pinned && show(button, text), { signal });
    button.addEventListener('mouseleave', () => !pinned && hide(), { signal });
    button.addEventListener('focus', () => !pinned && show(button, text), { signal });
    button.addEventListener('blur', () => { if (pinned === button) pinned = null; hide(); }, { signal });
    button.addEventListener('click', (event) => {
      // Never let the click reach the sortable header button or the row.
      event.preventDefault();
      event.stopPropagation();
      if (pinned === button) {
        hide();
      } else {
        show(button, text);
        pinned = button;
      }
    }, { signal });
    th.append(button);
    buttons.push(button);
  }

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && tip) hide();
  }, { signal });

  return () => {
    abort.abort();
    hide();
    for (const button of buttons) button.remove();
  };
}
