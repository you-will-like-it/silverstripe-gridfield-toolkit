export class ApiError extends Error {
  constructor(message, { status = 0, code = 'http_error', payload = null } = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.payload = payload;
  }
}

/** 409: payload carries the server's current `id`, `etag` and `cells` for the row. */
export class ConflictError extends ApiError {
  constructor(message, init) {
    super(message, init);
    this.name = 'ConflictError';
  }
}

/**
 * Resolves with the success payload; rejects with ApiError/ConflictError,
 * AbortError (caller aborted) or TypeError (network).
 */
async function request(url, { method = 'GET', securityID, body, signal } = {}) {
  const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
  if (method !== 'GET') {
    headers['X-SecurityID'] = securityID;
  }
  if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
  }

  const response = await fetch(url, {
    method,
    credentials: 'same-origin',
    signal,
    headers,
    body: body === undefined ? undefined : JSON.stringify(body),
  });

  const payload = await response.json().catch(() => null);
  if (response.ok && payload?.ok) {
    return payload;
  }

  const init = { status: response.status, code: payload?.error ?? 'http_error', payload };
  const message = payload?.message ?? `Request failed (${response.status})`;
  throw response.status === 409 ? new ConflictError(message, init) : new ApiError(message, init);
}

export const patchRecord = ({ url, securityID, id, etag, changes, signal }) =>
  request(url, { method: 'POST', securityID, body: { id, etag, changes }, signal });

export const undoPatch = ({ url, securityID, signal }) =>
  request(url, { method: 'POST', securityID, signal });

export const fetchOptions = ({ url, column, id, signal }) =>
  request(`${url}/${encodeURIComponent(column)}?id=${encodeURIComponent(id)}`, { signal });

export const fetchAccordion = ({ url, id, signal }) => request(`${url}/${encodeURIComponent(id)}`, { signal });

export const fetchBoard = ({ url, signal }) => request(url, { signal });

export const moveCard = ({ url, securityID, id, to, from, signal }) =>
  request(url, { method: 'POST', securityID, body: { id, to, from }, signal });

export const runBulk = ({ url, securityID, ids }) => request(url, { method: 'POST', securityID, body: { ids } });

export const transferRecords = ({ url, securityID, source, ids, mode }) =>
  request(url, { method: 'POST', securityID, body: { source, ids, mode } });
