<?php

namespace YouWillLikeIT\GridFieldToolkit\Component\ZIPExport;

use LogicException;
use Mpdf\Mpdf;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\GridField\AbstractGridFieldComponent;
use SilverStripe\Forms\GridField\GridField_ActionProvider;
use SilverStripe\Forms\GridField\GridField_FormAction;
use SilverStripe\Forms\GridField\GridField_HTMLProvider;
use SilverStripe\Forms\GridField\GridField_URLHandler;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldDataColumns;

/**
 * Adds an "Export list" button to the bottom of a {@link GridField}.
 * Exports the GridField data as a ZIP file containing individual PDFs and uploaded files.
 */
class GridFieldExportZIPButton extends AbstractGridFieldComponent implements GridField_HTMLProvider, GridField_ActionProvider, GridField_URLHandler
{
    /**
     * @var array Map of a property name on the exported objects.
     */
    protected $exportColumns;

    /**
     * Fragment to write the button to
     */
    protected $targetFragment;

    /**
     * @var string The title to display in the PDF header
     */
    protected $title;
    
    /**
     * @var string The class name of the exporter to use
     */
    protected $exporterClass;

    /**
     * @var string The created date to display in the PDF footer
     */
    protected $created;

    /**
     * @param string $targetFragment The HTML fragment to write the button into
     * @param array $exportColumns The columns to include in the export
     */
    public function __construct($targetFragment = "after", $exportColumns = null, $title = 'PDF Export', $created = '', $exporterClass = null)
    {
        $this->targetFragment = $targetFragment;
        $this->exportColumns = $exportColumns;
        $this->title = $title;
        $this->created = $created;
        $this->exporterClass = $exporterClass;
    }

    /**
     * Place the export button in a <p> tag below the field
     *
     * @param GridField $gridField
     *
     * @return array
     */
    public function getHTMLFragments($gridField)
    {
        $button = new GridField_FormAction(
            $gridField,
            'exportZIP',
            'ZIP',
            'exportZIP',
            null
        );
        $button->setIcon('down-circled');
        $button->addExtraClass('btn btn-secondary no-ajax action_export');
        $button->setForm($gridField->getForm());
        return [
            $this->targetFragment => $button->Field(),
        ];
    }

    /**
     * export is an action button
     *
     * @param GridField $gridField
     *
     * @return array
     */
    public function getActions($gridField)
    {
        return ['exportZIP'];
    }

    public function handleAction(GridField $gridField, $actionName, $arguments, $data)
    {
        if ($actionName === 'exportzip') {
            return $this->handleExport($gridField);
        }
        return null;
    }

    /**
     * it is also a URL
     *
     * @param GridField $gridField
     *
     * @return array
     */
    public function getURLHandlers($gridField)
    {
        return [
            'exportZIP' => 'handleExport',
        ];
    }

    /**
     * Handle the export, for both the action button and the URL
     *
     * @param GridField $gridField
     * @param HTTPRequest $request
     *
     * @return HTTPResponse
     */
    public function handleExport($gridField, $request = null)
    {
        if (!$this->exporterClass) {
            throw new LogicException('No ZIP exporter configured.');
        }

        $now = date('d-m-Y-H-i');
        $fileName = "export-$now.zip";

        $exporter = Injector::inst()->create($this->exporterClass);

        $fileData = $exporter->export($gridField, $this->title, $this->created);

        if ($fileData === '') {
            return null;
        }

        return HTTPRequest::send_file(
            $fileData,
            $fileName,
            'application/zip'
        );
    }

    // /**
    //  * Return the columns to export
    //  *
    //  * @param GridField $gridField
    //  *
    //  * @return array
    //  */
    // protected function getExportColumnsForGridField(GridField $gridField)
    // {
    //     if ($this->exportColumns) {
    //         return $this->exportColumns;
    //     }

    //     $dataCols = $gridField->getConfig()->getComponentByType(GridFieldDataColumns::class);
    //     if ($dataCols) {
    //         return $dataCols->getDisplayFields($gridField);
    //     }

    //     $modelClass = $gridField->getModelClass();
    //     $singleton = singleton($modelClass);
    //     if (!$singleton->hasMethod('summaryFields')) {
    //         throw new LogicException(
    //             'Cannot dynamically determine columns. Add a GridFieldDataColumns component to your GridField'
    //             . " or implement a summaryFields() method on $modelClass"
    //         );
    //     }
    //     return $singleton->summaryFields();
    // }

    // /**
    //  * Generate export fields for PDF.
    //  *
    //  * @param GridField $gridField
    //  *
    //  * @return string
    //  */
    // public function generateExportPDFPart($gridField)
    // {
    //     $this->getExportColumnsForGridField($gridField);
    //     $items = $gridField->getManipulatedList()->limit(null);
    //     $created = '';
    //     $firstItem = $items->first();
    //     if ($firstItem && $firstItem->Parent()->exists()) {
    //         $created = $firstItem->Parent()->dbObject('Created')->Format('dd.MM.yyyy. HH:mm');
    //     }

    //     $controller = Controller::curr();

    //     // generate pdf with mpdf
    //     $mpdf = new Mpdf([
    //         'mode' => 'utf-8',
    //         'format' => [210, 297], // A4 size in mm
    //         'margin_top' => 45,
    //         'margin_bottom' => 25,
    //         'margin_left' => 10,
    //         'margin_right' => 10
    //     ]);

    //     $mpdf->SetHTMLHeader($this->pdfHeader());
    //     $mpdf->WriteHTML(' ');
    //     $mpdf->SetHTMLFooter($this->pdfFooter());

    //     $html = $controller->customise([
    //         'Fields' => $items
    //     ])->renderWith('PDF/Content');
        
        
    //     $mpdf->WriteHTML($html);
        
    //     return $mpdf->Output('', 'S');
    // }

    // public function generateExportZIPFileData($gridField)
    // {
    //     $zip = new \ZipArchive();
    //     $now = date("d-m-Y-H-i");
    //     $fileName = "export-$now.zip";
    //     $tmpFile = tempnam(sys_get_temp_dir(), 'zip');
    //     $zip->open($tmpFile, \ZipArchive::CREATE);

    //     $pdfData = $this->generateExportPDFPart($gridField);
    //     $zip->addFromString("export.pdf", $pdfData);

    //     // add uploaded files to the ZIP
    //     $items = $gridField->getManipulatedList()->limit(null);
    //     foreach ($items as $item) {
    //         if ($item->ClassName === 'SilverStripe\\UserForms\\Model\\Submission\\SubmittedFileField') {
    //             $file = $item->UploadedFile();
    //             if ($file && $file->exists()) {
    //                 $fileData = $file->getString();
    //                 if ($fileData !== '') {
    //                     $zip->addFromString('uploads/'. $file->Name, $fileData);
    //                 }
    //             }
    //         }
    //     }


    //     $zip->close();
    //     return file_get_contents($tmpFile);
    // }

    /**
     * @return array
     */
    public function getExportColumns()
    {
        return $this->exportColumns;
    }

    /**
     * @param array $cols
     *
     * @return $this
     */
    public function setExportColumns($cols)
    {
        $this->exportColumns = $cols;
        return $this;
    }

    // private function pdfHeader()
    // {
    //     $controller = Controller::curr();

    //     return $html = $controller->customise([
    //         'Title' => $this->title ?? 'PDF Export'
    //     ])->renderWith('PDF/Header');
    // }

    // private function pdfFooter()
    // {
    //     $controller = Controller::curr();
    //     return $controller->customise(['Created' => $this->created ?? ''])->renderWith('PDF/Footer');
    // }
}
