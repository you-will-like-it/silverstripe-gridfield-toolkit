import '../css/toolkit.css';
import { mountAccordion } from './features/accordion.js';
import { mountColumnManager } from './features/column-manager.js';
import { mountFullscreen } from './features/fullscreen.js';
import { mountHeaderHelp } from './features/header-help.js';
import { mountInlineEdit } from './features/inline-edit.js';
import { mountKanban } from './features/kanban.js';
import { mountLinkedFilter } from './features/linked-filter.js';
import { mountNestedRelation } from './features/nested-relation.js';
import { mountMasterDetail } from './features/master-detail.js';
import { mountMasterSelect } from './features/master-select.js';
import { mountStash } from './features/stash.js';
import { mountTransferSource, mountTransferTarget } from './features/transfer.js';

/** data-ywli-feature -> (gridFieldRoot, config) => disposer */
const FEATURES = {
  'inline-edit': mountInlineEdit,
  fullscreen: mountFullscreen,
  accordion: mountAccordion,
  'master-detail': mountMasterDetail,
  kanban: mountKanban,
  stash: mountStash,
  'column-manager': mountColumnManager,
  'header-help': mountHeaderHelp,
  'nested-relation': mountNestedRelation,
  'master-select': mountMasterSelect,
  'linked-filter': mountLinkedFilter,
  'transfer-source': mountTransferSource,
  'transfer-target': mountTransferTarget,
};

const disposers = new WeakMap();

function mount(marker) {
  const root = marker.closest('.ss-gridfield');
  const factory = FEATURES[marker.dataset.ywliFeature];
  if (!root || !factory || disposers.has(marker)) {
    return;
  }

  let config;
  try {
    config = JSON.parse(marker.dataset.ywliConfig ?? '{}');
  } catch (error) {
    console.error('[ywli] Invalid feature config', error);
    return;
  }

  disposers.set(marker, factory(root, config));
}

function unmount(marker) {
  disposers.get(marker)?.();
  disposers.delete(marker);
}

// Entwine is only the lifecycle shim. Each feature renders a hidden `.ywli-marker` inside the GridField's
// <fieldset> via GridField_HTMLProvider, so it is created/destroyed with every GridField reload. Matching on
// the marker (own namespace) means we never touch or `_super` GridField.js's own `.ss-gridfield` rules.
const { jQuery } = window;
if (jQuery?.entwine) {
  jQuery.entwine('ywli', ($) => {
    $('.ywli-marker').entwine({
      onmatch() {
        mount(this[0]);
      },
      onunmatch() {
        unmount(this[0]);
      },
    });
  });
} else {
  console.warn('[ywli] jQuery/Entwine not found; GridField Toolkit features are inactive.');
}
