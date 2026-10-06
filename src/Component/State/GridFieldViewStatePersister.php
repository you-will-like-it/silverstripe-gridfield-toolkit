<?php

declare(strict_types=1);

namespace YouWillLikeIT\GridFieldToolkit\Component\State;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\NullHTTPRequest;
use SilverStripe\Forms\GridField\AbstractGridFieldComponent;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_DataManipulator;
use SilverStripe\Model\List\SS_List;
use SilverStripe\Security\Security;

/**
 * Remembers sort, filter and page of a grid in the session and restores them the next time the grid is rendered
 * without explicit state (a fresh page load, coming back from an edit form...). Explicit state from the request (URL
 * `gridState` parameter or a posted GridState) always wins, so "Reset" and normal navigation keep working.
 *
 * Must run before the components that read the state, so the config adds it first (see withViewStatePersister()).
 * Scope: per member + grid name + model class. Nothing is stored for guests.
 */
class GridFieldViewStatePersister extends AbstractGridFieldComponent implements GridField_DataManipulator
{
    public const SESSION_KEY = 'ywli.viewstate';

    /** @param list<string> $keys GridState sections to remember (short names, as used in `$gridField->State->Name`) */
    public function __construct(
        private readonly array $keys = [
            'GridFieldSortableHeader',
            'GridFieldFilterHeader',
            'GridFieldPaginator',
            'YWLIColumns',
        ],
    ) {
    }

    /** @return list<string> */
    public function getKeys(): array
    {
        return $this->keys;
    }

    #[\Override]
    public function getManipulatedData(GridField $gridField, SS_List $dataList)
    {
        $member = Security::getCurrentUser();
        if ($member === null) {
            return $dataList;
        }

        $request = $this->requestFor($gridField);
        $session = $request->getSession();
        $bucket = sprintf('%d.%s.%s', $member->ID, $gridField->getName(), md5($gridField->getModelClass()));
        $path = self::SESSION_KEY . '.' . $bucket;
        $state = $gridField->getState();

        if (!$this->hasExplicitState($gridField, $request)) {
            $saved = $session->get($path);
            if (is_array($saved)) {
                foreach ($this->keys as $key) {
                    if (isset($saved[$key]) && is_array($saved[$key])) {
                        $state->{$key} = $saved[$key];
                    }
                }
            }
        }

        $snapshot = [];
        foreach ($this->keys as $key) {
            $value = $state->{$key};
            $array = is_object($value) && method_exists($value, 'toArray') ? $value->toArray() : (is_array($value) ? $value : []);
            if ($array !== []) {
                $snapshot[$key] = $array;
            }
        }
        $session->set($path, $snapshot);

        return $dataList;
    }

    private function requestFor(GridField $gridField): HTTPRequest
    {
        $request = $gridField->getRequest();
        if ($request instanceof NullHTTPRequest && Controller::curr() !== null) {
            $request = Controller::curr()->getRequest();
        }

        return $request;
    }

    /** True when the request itself carries this grid's state. */
    private function hasExplicitState(GridField $gridField, HTTPRequest $request): bool
    {
        $posted = $request->requestVar($gridField->getName());
        if (is_array($posted) && isset($posted['GridState'])) {
            return true;
        }
        foreach (array_keys($request->requestVars()) as $key) {
            if (stripos((string) $key, 'gridstate') === 0) {
                return true;
            }
        }

        return false;
    }
}
