<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Config;

use SilverStripe\Forms\GridField\GridFieldComponent;
use Closure;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use SilverStripe\Model\List\ArrayList;
use YouWillLikeIT\GridFieldToolkit\Component\Board\GridFieldKanbanView;
use YouWillLikeIT\GridFieldToolkit\Component\Bulk\GridFieldStashBulk;
use YouWillLikeIT\GridFieldToolkit\Component\Columns\GridFieldColumnManager;
use YouWillLikeIT\GridFieldToolkit\Component\Columns\GridFieldHeaderHelp;
use YouWillLikeIT\GridFieldToolkit\Component\Relation\GridFieldNestedRelation;
use YouWillLikeIT\GridFieldToolkit\Component\Translation\FluentStatusProvider;
use YouWillLikeIT\GridFieldToolkit\Component\Translation\GridFieldTranslationStatus;
use YouWillLikeIT\GridFieldToolkit\Contract\TranslationStatusProviderInterface;
use YouWillLikeIT\GridFieldToolkit\Component\Link\GridFieldLinkedFilter;
use YouWillLikeIT\GridFieldToolkit\Component\Link\GridFieldMasterSelect;
use YouWillLikeIT\GridFieldToolkit\Component\State\GridFieldViewStatePersister;
use YouWillLikeIT\GridFieldToolkit\Component\Transfer\GridFieldTransferSource;
use YouWillLikeIT\GridFieldToolkit\Component\Transfer\GridFieldTransferTarget;
use YouWillLikeIT\GridFieldToolkit\Contract\BulkActionInterface;
use YouWillLikeIT\GridFieldToolkit\Component\InlineEdit\GridFieldInlineEdit;
use YouWillLikeIT\GridFieldToolkit\Component\InlineEdit\GridFieldUndoManager;
use YouWillLikeIT\GridFieldToolkit\Component\Layout\GridFieldAccordion;
use YouWillLikeIT\GridFieldToolkit\Component\Layout\GridFieldFullscreenToggle;
use YouWillLikeIT\GridFieldToolkit\Component\Layout\GridFieldMasterDetail;
use YouWillLikeIT\GridFieldToolkit\Contract\InlineEditorInterface;

/**
 * RecordEditor config with fluent, strictly opt-in toolkit features. Nothing is added unless a with*() method is
 * called, so a plain `GridFieldConfig_ToolkitBase::create()` behaves exactly like GridFieldConfig_RecordEditor.
 *
 *   GridFieldConfig_ToolkitBase::create(50)
 *       ->withInlineEdit(new TextEditor('Title'), new ToggleEditor('IsActive'))
 *       ->withUndo(5);
 */
class GridFieldConfig_ToolkitBase extends GridFieldConfig_RecordEditor
{
    /** Generic opt-in entry point; every with*() below is sugar over this. */
    public function with(GridFieldComponent $component, ?string $insertBefore = null): static
    {
        $this->addComponent($component, $insertBefore);

        return $this;
    }

    /**
     * Enables inline editing for the given columns. Repeated calls add editors to the same component.
     *
     * The stock GridFieldDataColumns is replaced in place (same position) by a GridFieldInlineEdit carrying over its
     * display fields, casting and formatting; two providers on one column would render the cell twice.
     */
    public function withInlineEdit(InlineEditorInterface ...$editors): static
    {
        $inline = $this->getComponentByType(GridFieldInlineEdit::class);

        if (!$inline instanceof GridFieldInlineEdit) {
            $columns = $this->getComponentByType(GridFieldDataColumns::class);

            if ($columns instanceof GridFieldDataColumns) {
                $inline = GridFieldInlineEdit::fromDataColumns($columns);
                $swapped = new ArrayList();
                foreach ($this->getComponents() as $component) {
                    $swapped->push($component === $columns ? $inline : $component);
                }
                $this->components = $swapped;
            } else {
                $inline = GridFieldInlineEdit::create();
                $this->addComponent($inline);
            }
        }

        $inline->addEditor(...$editors);

        return $this;
    }

    /** Undo toast for inline edits. Requires withInlineEdit(); a no-op for the UI otherwise. */
    public function withUndo(int $seconds = 5): static
    {
        $this->removeComponentsByType(GridFieldUndoManager::class);

        return $this->with(GridFieldUndoManager::create($seconds));
    }

    /** Fullscreen button (top-right button row) and Esc to exit. */
    public function withFullscreen(): static
    {
        return $this->replaceUnique(GridFieldFullscreenToggle::class, GridFieldFullscreenToggle::create());
    }

    /**
     * Expandable detail row, loaded lazily. The renderer returns trusted HTML, a label => value array (escaped) or a
     * ModelData.
     *
     * @param Closure(\SilverStripe\ORM\DataObject): (string|array<string, mixed>|\SilverStripe\Model\ModelData) $renderer
     */
    public function withAccordion(Closure $renderer, bool $multiple = true): static
    {
        return $this->replaceUnique(GridFieldAccordion::class, new GridFieldAccordion($renderer, $multiple));
    }

    /** Row click opens the standard edit form in a side panel instead of navigating away. */
    public function withMasterDetail(int $widthPercent = 50): static
    {
        return $this->replaceUnique(GridFieldMasterDetail::class, new GridFieldMasterDetail($widthPercent));
    }

    /**
     * Board view with drag & drop between lanes of $groupField.
     *
     * @param list<string> $cardFields
     * @param array<string, string>|Closure(\SilverStripe\Forms\GridField\GridField): array<string, string>|null $columns
     */
    public function withKanban(
        string $groupField,
        string $titleField = 'Title',
        array $cardFields = [],
        array|Closure|null $columns = null,
        int $limit = 500,
    ): static {
        return $this->replaceUnique(
            GridFieldKanbanView::class,
            new GridFieldKanbanView($groupField, $titleField, $cardFields, $columns, $limit)
        );
    }

    /** @param class-string $class */
    private function replaceUnique(string $class, GridFieldComponent $component): static
    {
        $this->removeComponentsByType($class);

        return $this->with($component);
    }

    /** Checkbox column + bulk bar; the selection survives paging, sorting and reloads. */
    public function withStashBulk(BulkActionInterface ...$actions): static
    {
        return $this->withStashBulkLimit(500, ...$actions);
    }

    public function withStashBulkLimit(int $maxSelection, BulkActionInterface ...$actions): static
    {
        return $this->replaceUnique(GridFieldStashBulk::class, new GridFieldStashBulk($maxSelection, ...$actions));
    }

    /** Master side of linked grids: row click filters the named slave grids. */
    public function withMasterSelect(string ...$slaves): static
    {
        return $this->replaceUnique(GridFieldMasterSelect::class, new GridFieldMasterSelect(...$slaves));
    }

    /** Slave side of linked grids: only rows whose $filterField equals the master row's ID. */
    public function withLinkedFilter(string $filterField, bool $emptyUntilSelected = true, string $masterLabel = ''): static
    {
        return $this->replaceUnique(GridFieldLinkedFilter::class, new GridFieldLinkedFilter($filterField, $emptyUntilSelected, $masterLabel));
    }

    /** Rows of this grid can be dragged onto a GridFieldTransferTarget grid. */
    public function withTransferSource(): static
    {
        return $this->replaceUnique(GridFieldTransferSource::class, new GridFieldTransferSource());
    }

    /**
     * Drop zone for records dragged from the named source grids.
     *
     * @param list<string> $sources
     * @param Closure(\SilverStripe\ORM\DataObject, \SilverStripe\Forms\GridField\GridField, string): void $apply
     * @param list<string> $modes
     */
    public function withTransferTarget(array $sources, Closure $apply, array $modes = GridFieldTransferTarget::MODES): static
    {
        return $this->replaceUnique(GridFieldTransferTarget::class, new GridFieldTransferTarget($sources, $apply, $modes));
    }

    /** Remember sort, filter and page in the session (placed first so it runs before the components that read them). */
    public function withViewStatePersister(?array $keys = null): static
    {
        $this->removeComponentsByType(GridFieldViewStatePersister::class);
        $persister = $keys === null ? new GridFieldViewStatePersister() : new GridFieldViewStatePersister($keys);
        $first = $this->getComponents()->first();

        return $this->with($persister, $first ? $first::class : null);
    }

    /**
     * Show/hide columns from a button in the button row.
     *
     * @param list<string> $locked Columns that cannot be hidden
     * @param list<string> $defaultHidden Hidden until the user chooses otherwise
     */
    public function withColumnManager(array $locked = [], array $defaultHidden = []): static
    {
        return $this->replaceUnique(GridFieldColumnManager::class, new GridFieldColumnManager($locked, $defaultHidden));
    }

    /** "?" help bubbles on column headers. @param array<string, string> $help column => text */
    public function withHeaderHelp(array $help): static
    {
        return $this->replaceUnique(GridFieldHeaderHelp::class, new GridFieldHeaderHelp($help));
    }

    /** Per-locale translation badges. Defaults to Fluent when it is installed; otherwise pass a provider. */
    public function withTranslationStatus(?TranslationStatusProviderInterface $provider = null): static
    {
        $provider ??= new FluentStatusProvider();

        return $this->replaceUnique(GridFieldTranslationStatus::class, new GridFieldTranslationStatus($provider));
    }

    /** Modal with the record's relation tab (via the stock edit form). */
    public function withNestedRelation(string $relation, ?string $label = null, ?string $tab = null): static
    {
        return $this->replaceUnique(GridFieldNestedRelation::class, new GridFieldNestedRelation($relation, $label, $tab));
    }
}
