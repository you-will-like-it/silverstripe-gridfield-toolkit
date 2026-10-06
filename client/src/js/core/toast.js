const REGION_ID = 'ywli-toast-region';

/** Singleton region on <body>, outside any GridField, so it survives grid reloads. */
function region() {
  let el = document.getElementById(REGION_ID);
  if (!el) {
    el = document.createElement('div');
    el.id = REGION_ID;
    el.className = 'ywli-toast-region';
    document.body.append(el);
  }
  return el;
}

/**
 * @param {{message: string, type?: 'info'|'error'|'success', duration?: number,
 *          action?: {label: string, onClick?: () => void}}} options
 *        duration 0 = persistent. Expiry is a CSS animation, so hover/focus pauses it (see toolkit.css).
 */
export function showToast({ message, type = 'info', duration = 4000, action = null }) {
  const el = document.createElement('div');
  el.className = `ywli-toast ywli-toast--${type}`;
  el.setAttribute('role', type === 'error' ? 'alert' : 'status');

  const dismiss = () => el.remove();

  const text = document.createElement('span');
  text.className = 'ywli-toast__message';
  text.textContent = message;
  el.append(text);

  if (action) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'ywli-toast__action';
    button.textContent = action.label;
    button.addEventListener('click', () => {
      dismiss();
      action.onClick?.();
    }, { once: true });
    el.append(button);
  }

  if (duration > 0) {
    const bar = document.createElement('span');
    bar.className = 'ywli-toast__bar';
    bar.style.setProperty('--ywli-toast-duration', `${duration}ms`);
    bar.addEventListener('animationend', dismiss, { once: true });
    el.append(bar);
  }

  region().append(el);

  return { dismiss, element: el };
}
