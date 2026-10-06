# YouWillLikeIT GridField Toolkit

Opt-in UX components for the Silverstripe 6 GridField, implemented as native GridField components (no DataExtensions).

Status: **Group 1 (Inline Edit Suite)** **Group 2 (layout & views)** **Group 3 (selection & cross-grid)** and **Group 4 (column, translation & relation helpers)** are implemented.

## Install

```
composer require youwilllikeit/silverstripe-gridfield-toolkit
vendor/bin/sake dev/build flush=1
```

`client/dist` is committed. Rebuild with `npm ci && npm run build` after changing `client/src`.

## Usage

```php
use YouWillLikeIT\GridFieldToolkit\Config\GridFieldConfig_ToolkitBase;
use YouWillLikeIT\GridFieldToolkit\Component\InlineEdit\Editor\{TextEditor, NumericEditor, ToggleEditor, SelectEditor, DependentSelectEditor};

$config = GridFieldConfig_ToolkitBase::create(50)
    ->withInlineEdit(
        new TextEditor('Title', required: true),
        new ToggleEditor('IsActive'),
        new NumericEditor('Qty', min: 0, max: 99),
        new SelectEditor('Status', ['draft' => 'Draft', 'live' => 'Live']),
        new SelectEditor('Country', ['AT' => 'Austria', 'DE' => 'Germany']),
        new DependentSelectEditor('City', 'Country', fn (string $country) => City::optionsFor($country)),
    )
    ->withUndo(5);
```

`GridFieldConfig_ToolkitBase::create()` without `with*()` calls behaves exactly like `GridFieldConfig_RecordEditor`.

### How it works

- `withInlineEdit()` replaces the config's `GridFieldDataColumns` in place with `GridFieldInlineEdit` (a subclass), carrying over display fields, casting and formatting. Two column providers on one column would render the cell twice.
- Text / number / select cells: double-click, or focus + Enter / F2. Enter or blur saves, Esc cancels, Tab saves and moves to the next editable cell. A single click only focuses, so the row's edit-form navigation is suppressed in those cells. Toggle clicks are claimed; clicks on cell padding still open the row.
- Saves are field-level JSON patches with optimistic concurrency (etag). On conflict the client shows a toast, waits (`setConflictPause()`, default 2.5 s) and then swaps in the server's current row.
- Dependent selects are server-authoritative: changing the parent clears a child value that no longer fits (same transaction, same response). Give the child column an empty value.
- Undo is a session-scoped, single-use, per-user memento. The countdown in the UI is cosmetic; expiry is enforced server-side (ttl + 10 s grace). Undo is refused with 409 if the row changed since.

### Endpoints (relative to the GridField link, CSRF via `X-SecurityID`)

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `ywli/inline/patch` | `{id, etag, changes: {Column: value}}` |
| GET | `ywli/inline/options/$Column?id=` | options for dynamic / dependent selects |
| POST | `ywli/undo/$Token` | revert one patch |

### Extension points

- DataObject extension hooks: `onAfterInlineEdit($changes, $previous, $member)`, `onAfterInlineEditUndo($previous, $member)`.
- Browser events on the GridField root: `ywli:cell:saved`, `ywli:cell:conflict`, `ywli:cell:undone`.
- Custom editors implement `InlineEditorInterface` (plus `OptionsEditorInterface` / `DependentEditorInterface`).
- Styling: CSS custom properties prefixed `--ywli-`.

### Known limits

- Editors work on plain DB fields and has-one `FooID` columns, not relation paths. `TextEditor` refuses HTML fields.
- The etag check is best-effort (no row lock between check and write).
- Float columns can round-trip imprecisely and cause a spurious conflict; use `Decimal`.
- Unversioned deletes and bulk operations are not undoable (not part of this component).

## Group 2: layout & views

```php
$config = GridFieldConfig_ToolkitBase::create(50)
    ->withFullscreen()
    ->withAccordion(fn (Order $o) => ['Customer' => $o->Customer()->Title, 'Notes' => $o->Notes])
    ->withMasterDetail(55)
    ->withKanban('Status', titleField: 'Title', cardFields: ['Customer.Title', 'Total']);
```

| Feature | Notes |
| --- | --- |
| `withFullscreen()` | CSS overlay, Esc exits, state survives grid reloads. |
| `withAccordion($renderer, $multiple = true)` | Chevron column; detail loaded lazily from `GET ywli/accordion/{id}` (needs `canView`). Renderer returns trusted HTML string, `label => value` array (escaped) or `ModelData`. |
| `withMasterDetail($widthPercent)` | Row click opens the stock `item/{id}` edit form in a side-panel iframe; list refreshes after saves. Ctrl/Cmd/Shift-click, buttons, links and inline-edit cells keep their normal behaviour. Needs `GridFieldDetailForm`. |
| `withKanban($groupField, $titleField, $cardFields, $columns, $limit)` | Board/List toggle (remembered per grid). Lanes from an array, a `Closure(GridField)` or the Enum values of `$groupField`. Drag & drop or Alt + Left/Right; `POST ywli/kanban/move {id,to,from}` (CSRF, `canEdit`, 409 if the card moved meanwhile). |

Kanban limits: shows the list after all manipulators except pagination, capped at `$limit`; header filter/sort *state* is not applied to the board. Values outside the lanes appear in a locked "Other" lane.

Events: `ywli:fullscreen`, `ywli:accordion:loaded`, `ywli:card:moved`, and a cancelable `ywli:record:open` (Master/Detail handles it, otherwise the card navigates to the edit page).

## Group 3: selection & cross-grid

```php
// Stash bulk actions: the selection survives paging, sorting and reloads.
$orders = GridFieldConfig_ToolkitBase::create(25)
    ->withStashBulk(
        new DeleteBulkAction(),
        new SetFieldBulkAction('archive', 'Archive', 'Status', 'archived'),
        new CallbackBulkAction('mail', 'Send reminder', fn (Order $o) => $o->sendReminder()),
    )
    ->withMasterSelect('OrderItems')            // master: row click filters the slave
    ->withTransferSource()                      // rows can be dragged to another grid
    ->withViewStatePersister();                 // remember sort / filter / page

$items = GridFieldConfig_ToolkitBase::create(25)
    ->withLinkedFilter('OrderID', emptyUntilSelected: true, masterLabel: 'Orders')
    ->withTransferTarget(['Orders'], function (DataObject $item, GridField $target, string $mode): void {
        $item->OrderID = /* the order this grid belongs to */;
    });
```

| Feature | Notes |
| --- | --- |
| `withStashBulk(...$actions)` / `withStashBulkLimit($max, ...$actions)` | Checkbox column (shift-click ranges, select-all-on-page) and a bar with one button per `BulkActionInterface`. `POST ywli/stash/run/{action}` re-checks every ID against the grid's list and the action's per-record permission. Partial success is reported per record; there is no all-or-nothing transaction. Selection is by ID (no "select all matching the filter"). Default cap 500. |
| `withMasterSelect(...$slaves)` + `withLinkedFilter($field, $emptyUntilSelected, $masterLabel)` | The master ID is stored in the slave's own GridState (`YWLILinkedFilter.MasterID`), so it survives paging and sorting. Clicking the selected row again clears it. Row click on the master no longer opens the edit form (the Edit link still does). Slave "Add new" is not pre-filled with the master. |
| `withTransferSource()` + `withTransferTarget($sources, $apply, $modes)` | Drag rows (or the whole stash, when a stashed row is dragged) onto the target grid; with both modes enabled a small Move/Copy menu appears. The closure only mutates the record; the toolkit writes it (move: `canEdit`; copy: `canCreate`, record is an unsaved `duplicate(false)`). One transaction per record. Source grid must be in the same form. Mouse/drag only for now. |
| `withViewStatePersister($keys = null)` | Session-scoped (member + grid name + model class). Explicit state in the request (URL `gridState`, posted GridState) always wins; restored only on a render without state. Runs first in the component list. |

Events: `ywli:stash:done`, `ywli:master:select`, `ywli:transfer:done`.

## Group 4: columns, translations, relations

```php
$config = GridFieldConfig_ToolkitBase::create(25)
    ->withColumnManager(locked: ['Title'], defaultHidden: ['CreatedAt'])
    ->withHeaderHelp(['Status' => _t('Order.STATUS_HELP', 'Where the order is in its life cycle')])
    ->withTranslationStatus()                 // Fluent, when installed; or pass a TranslationStatusProviderInterface
    ->withNestedRelation('Items', 'Items')    // "Items (3)" button -> modal with the record's Items tab
    ->withViewStatePersister();               // also remembers the chosen columns
```

| Feature | Notes |
| --- | --- |
| `withColumnManager($locked, $defaultHidden)` | Button in the button row. Hidden columns are not rendered at all; the choice is stored in the grid's GridState (`YWLIColumns.Hidden`). Show/hide only, no reordering. Place it after the components that add columns. |
| `withHeaderHelp($map)` | "?" next to the header; hover, focus or click shows plain text; Esc closes. Pass translated strings. |
| `withTranslationStatus($provider)` | Column with one badge per locale (published solid / draft dashed / missing struck through, plus screen-reader text). `FluentStatusProvider` is written against Fluent's record methods (`isPublishedInLocale`, `isDraftedInLocale`, `existsInLocale`) and is **not tested against a real Fluent install**; the component itself is tested with a fake provider. One lookup per row and locale. |
| `withNestedRelation($relation, $label, $tab)` | Opens the stock `item/{id}` edit form in a modal, switched to tab `Root_{relation}`. Requires `GridFieldDetailForm`. The grid is refreshed after changes so counts stay right. |

Events: `ywli:columns:change`.

## Development

```
npm ci
npm test            # jsdom tests for the client runtime and the built bundle
npm run build       # Vite IIFE bundle -> client/dist
vendor/bin/phpunit  # SapphireTest suites (needs a Silverstripe 6 project with a test database)
```
