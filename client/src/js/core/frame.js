/** Chrome that makes no sense when the CMS edit form is shown inside a panel or modal; the form itself stays untouched. */
export const FRAME_CSS = `
  .cms-menu, .cms-mobile-menu-toggle-wrapper, .cms-preview, .cms-container-skip-link, .breadcrumbs-wrapper { display: none !important; }
  .cms-container, .cms-content { margin-left: 0 !important; left: 0 !important; width: 100% !important; }
`;

/** Same-origin document of an iframe, or null. Never throws. */
export function frameDocument(frame) {
  try {
    return frame.contentDocument;
  } catch {
    return null;
  }
}

export const isDirty = (frame) => frameDocument(frame)?.querySelector('.cms-edit-form.changed') != null;

/** Injects FRAME_CSS into a same-origin frame document. */
export function injectFrameCss(doc) {
  const style = doc.createElement('style');
  style.textContent = FRAME_CSS;
  doc.head.append(style);
}
