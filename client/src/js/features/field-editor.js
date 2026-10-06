/**
 * Builds the transient input for text / number / select cells.
 *
 * @param {{type: 'text'|'number'|'select', schema: object, cell: HTMLElement, value: string,
 *          options?: Array<{value: string, label: string}>}} spec
 * @returns {{element: HTMLElement, getValue: () => string, multiline: boolean}}
 */
export function createFieldEditor({ type, schema, cell, value, options = [] }) {
  const { ywliMaxlength: maxLength, ywliStep: step } = cell.dataset;
  let element;

  if (type === 'select') {
    element = document.createElement('select');
    for (const { value: optionValue, label } of options) {
      const option = new Option(label, optionValue);
      option.selected = optionValue === value;
      element.append(option);
    }
  } else if (type === 'number') {
    element = document.createElement('input');
    element.type = 'number';
    element.step = step ?? 'any';
    if (schema.min != null) element.min = String(schema.min);
    if (schema.max != null) element.max = String(schema.max);
    element.required = !schema.allowNull;
    element.value = value;
  } else if (schema.multiline) {
    element = document.createElement('textarea');
    element.rows = 3;
    if (maxLength) element.maxLength = Number(maxLength);
    element.value = value;
  } else {
    element = document.createElement('input');
    element.type = 'text';
    if (maxLength) element.maxLength = Number(maxLength);
    element.value = value;
  }

  element.classList.add('ywli-input');
  element.setAttribute('aria-label', cell.dataset.ywliColumn ?? '');

  return {
    element,
    multiline: type === 'text' && Boolean(schema.multiline),
    getValue: () => element.value,
  };
}
