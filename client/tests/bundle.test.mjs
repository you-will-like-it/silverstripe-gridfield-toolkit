import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const bundle = new URL('../dist/js/toolkit.js', import.meta.url);

test('dist bundle is a classic script that registers an Entwine marker rule and mounts on onmatch', { skip: !existsSync(bundle) }, async () => {
  const dom = new JSDOM('<!doctype html><body></body>', { runScripts: 'outside-only', pretendToBeVisual: true });
  const { window } = dom;
  let rule;
  window.jQuery = {
    entwine(namespace, factory) {
      assert.equal(namespace, 'ywli');
      factory((selector) => ({ entwine(definition) { rule = { selector, definition }; } }));
    },
  };
  window.CSS = { escape: String };
  const calls = [];
  window.fetch = async (url, init) => {
    calls.push({ url, init });
    return { ok: true, status: 200, json: async () => ({ ok: true, etag: 'e1', cells: {} }) };
  };

  window.eval(readFileSync(bundle, 'utf8')); // must not need module syntax / imports

  assert.equal(rule.selector, '.ywli-marker');

  const config = { patchUrl: '/p', securityID: 's', editors: {}, strings: {} };
  window.document.body.innerHTML = `<fieldset class="ss-gridfield"><span class="ywli-marker" hidden data-ywli-feature="inline-edit"></span>
    <table><tr><td data-ywli-column="A" data-ywli-editor="toggle" data-ywli-id="1" data-ywli-etag="e0" class="ywli-editable">
    <button data-ywli-toggle aria-checked="false"></button></td></tr></table></fieldset>`;
  const marker = window.document.querySelector('.ywli-marker');
  marker.dataset.ywliConfig = JSON.stringify(config);

  rule.definition.onmatch.call({ 0: marker });
  window.document.querySelector('button').click();
  await new Promise((r) => setTimeout(r, 10));
  assert.equal(calls.length, 1);
  assert.equal(calls[0].url, '/p');

  rule.definition.onunmatch.call({ 0: marker });
  window.document.querySelector('button').click();
  await new Promise((r) => setTimeout(r, 10));
  assert.equal(calls.length, 1, 'unmount removed the listeners');
});
