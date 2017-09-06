<?php

namespace Masala;

use Nette\Application\Responses\JsonResponse,
    Nette\Application\Responses\TextResponse,
    Nette\Application\UI\Control,
    Nette\Application\IPresenter,
    Nette\Http\IRequest,
    Nette\Utils\DateTime,
    Nette\Localization\ITranslator,
    PHPExcel,
    PHPExcel_Writer_Excel2007,
    PHPExcel_IOFactory;


/** @author Lubomir Andrisek */
final class Masala extends Control implements IMasalaFactory {

    /** @var array */
    private $config;

    /** @var IBuilder */
    private $grid;

    /** @var IGridFactory */
    protected $gridFactory;

    /** @var array */
    private $header;

    /** @var IHelp */
    private $helpRepository;

    /** @var IImportFormFactory */
    private $importFormFactory;

    /** @var IProcessFormFactory */
    private $processFormFactory;

    /** @var IRequest */
    private $request;

    /** @var ITranslator */
    private $translatorModel;

    public function __construct(array $config, IGridFactory $gridFactory, IHelp $helpRepository, IImportFormFactory $importFormFactory, IProcessFormFactory $processFormFactory, IRequest $request, ITranslator $translatorModel) {
        parent::__construct(null, null);
        $this->config = $config;
        $this->gridFactory = $gridFactory;
        $this->helpRepository = $helpRepository;
        $this->importFormFactory = $importFormFactory;
        $this->processFormFactory = $processFormFactory;
        $this->request = $request;
        $this->translatorModel = $translatorModel;
    }

    /** @return IMasalaFactory */
    public function setGrid(IBuilder $grid) {
        $this->grid = $grid;
        return $this;
    }

    /** @return IBuilder */
    public function getGrid() {
        return $this->grid;
    }

    private function getHeader($row, $header) {
        foreach ($row as $key => $column) {
            mb_detect_encoding($column, 'UTF-8', true) == false ? $column = trim(iconv('windows-1250', 'utf-8', $column)) : $column = trim($column);
            if (isset($header->$column)) {
                foreach ($header->$column as $feed => $value) {
                    if (!isset($this->header[$feed]) and is_numeric($value)) {
                        $this->header[$feed] = [$value => $key];
                    } elseif (!isset($this->header[$feed]) and is_bool($feed)) {
                        $this->header[$feed] = $key;
                    } elseif ('break' == $value and ! isset($this->header[$feed])) {
                        $this->header[$feed] = $key;
                    } elseif ('break' == $value and isset($this->header[$feed])) {
                        
                    } elseif (is_array($header->$feed)) {
                        is_numeric($value) ? $this->header[$feed][$value] = $key : $this->header[$feedColumn][] = $key;
                    } elseif (is_numeric($value)) {
                        $this->header[$feed] = [0 => $key, $value => $header->$feed];
                    }
                }
            }
        }
        if (!empty($this->header)) {
            foreach (json_decode($this->grid->getImport()->getSetting()->validator) as $validator => $value) {
                if (!isset($this->header[$validator])) {
                    return $this->header = $this->translatorModel->translate('Header does not contains validator') . ' ' . $this->translatorModel->translate($validator) . '.';
                }
            }
        }
    }

    /** @return IMasalaFactory */
    public function create() {
        return $this;
    }

    /** @return void */
    public function attached($presenter) {
        parent::attached($presenter);
        if ($presenter instanceof IPresenter and null != $this->grid->getTable()) {
            $this->grid->attached($this);
        }
    }

    /** @return string */
    private function getDivider($file) {
        $dividers = [];
        foreach ([',', ';', '"'] as $divider) {
            $handle = fopen($file, 'r');
            $line = fgetcsv($handle, 10000, $divider);
            fclose($handle);
            $dividers[count($line)] = $divider;
        }
        ksort($dividers);
        $divider = array_reverse($dividers);
        return array_shift($divider);
    }

    /** @return array */
    private function getResponse() {
        $response = ['file' => $this->grid->getPost('file'),
            'data' => $this->grid->getPost('data'),
            'divider' => $this->grid->getPost('divider'),
            'header' => $this->grid->getPost('header'),
            'filters' => $this->grid->getPost('filters'),
            'offset' => $this->grid->getPost('offset'),
            'status' => $this->grid->getPost('status'),
            'stop'=>$this->grid->getPost('stop'),
        ];
        if(null == $response['data'] = $this->grid->getPost('data')) {
            $response['data'] = [];
        }
        return $response;
    }

    /** @return JsonResponse */
    public function handleDone() {
        $this->grid->log('done');
        $service = 'get' . ucfirst($this->grid->getPost('status'));
        return $this->presenter->sendResponse(new JsonResponse($this->grid->$service()->done($this->getResponse(), $this)));
    }

    /** @return JsonResponse */
    public function handleExport() {
        $folder = $this->grid->getExport()->getFile();
        !file_exists($folder) ? mkdir($folder, 0755, true) : null;
        $header = '';
        foreach($this->grid->prepare()->getOffset(1) as $column => $value) {
            if($value instanceof DateTime || false == $this->grid->getAnnotation($column, ['unrender', 'hidden'])) {
                $header .= $this->grid->translate($column, $this->grid->getTable() . '.' .  $column) . ';';
            }
        }
        $file = $this->grid->getId('export') . '.csv';
        file_put_contents($folder . '/' . $file, $header);
        $response = new JsonResponse($this->grid->getExport()->prepare([
                'file' => $file,
                'filters' => $this->grid->getPost('filters'),
                'offset' => 0,
                'sort' => $this->grid->getPost('sort'),
                'status' => 'export',
                'stop' => $this->grid->getSum()], $this));
        return $this->presenter->sendResponse($response);
    }

    /** @return JsonResponse */
    public function handleExcel() {
        $excel = new PHPExcel();
        $folder = $this->grid->getExport()->getFile();
        !file_exists($folder) ? mkdir($folder, 0755, true) : null;
        $title = 'export';
        $properties = $excel->getProperties();
        $properties->setTitle($title);
        $properties->setSubject($title);
        $properties->setDescription($title);
        $excel->setActiveSheetIndex(0);
        $sheet = $excel->getActiveSheet();
        $sheet->setTitle(substr($title, 0, 31));
        $letter = 'a';
        foreach($this->grid->prepare()->getOffset(1) as $column => $value) {
            if($value instanceof DateTime || false == $this->grid->getAnnotation($column, ['unrender', 'hidden'])) {
                $sheet->setCellValue($letter . '1', ucfirst($this->translatorModel->translate($column)));
                $sheet->getColumnDimension($letter)->setAutoSize(true);
                $sheet->getStyle($letter . '1')->getAlignment()->setHorizontal(\PHPExcel_Style_Alignment::HORIZONTAL_CENTER);
                $letter++;
            }
        }
        $file = $this->grid->getId('excel') . '.xls';
        $objWriter = new PHPExcel_Writer_Excel2007($excel);
        $objWriter->save($folder . '/' .$file);
        $response = new JsonResponse($this->grid->getExport()->prepare([
            'file' => $file,
            'filters' => $this->grid->getPost('filters'),
            'offset' => 0,
            'sort' => $this->grid->getPost('sort'),
            'status' => 'excel',
            'stop' => $this->grid->getSum()], $this));
        return $this->presenter->sendResponse($response);
    }

    /** @return JsonResponse */
    public function handleImport() {
        $path = $this->grid->getImport()->getFile();
        $setting = $this->grid->getImport()->getSetting();
        $header = json_decode($setting->mapper);
        $divider = $this->getDivider($path);
        $handle = fopen($path, 'r');
        while (false !== ($row = fgets($handle, 10000))) {
            $before = $row;
            $row = $this->sanitize($row, $divider);
            if (empty($this->header)) {
                $offset = strlen($before);
                $this->getHeader($row, $header);
            } elseif (!empty($this->header)) {
                break;
            }
        }
        $response = new JsonResponse($this->grid->getImport()->prepare(['divider'=>$divider,
                                    'header'=>$this->header,
                                    'file'=> $this->grid->getPost('file'),
                                    'link'=> $this->link('run'),
                                    'offset'=> $offset,
                                    'status'=>'import',
                                    'stop' => filesize($path)], $this));
        return $this->presenter->sendResponse($response);
    }

    /** @return TextResponse */
    public function handleSave($file) {
        $this->getGrid()->getImport()->save($id = $this->grid->getId($file), $this->grid->getPost('file'));
        $response = new TextResponse($id);
        return $this->presenter->sendResponse($response);
    }

    /** @return JsonResponse */
    public function handlePrepare() {
        $this->grid->log('prepare');
        $data = ['offset' => 0, 'stop' => $this->grid->prepare()->getSum(), 'status' => 'service'];
        $response = new JsonResponse($this->grid->getService()->prepare($data, $this));
        return $this->presenter->sendResponse($response);
    }

    /** @return JsonResponse */
    public function handleRun() {
        $response = $this->getResponse();
        if ('import' == $response['status']) {
            $path = $this->grid->getImport()->getFile();
            $handle = fopen($path, 'r');
            for($i=0;$i<$this->config['importSpeed'];$i++) {
                fseek($handle, $response['offset']);
                $offset = fgets($handle);
                if($response['stop'] == $response['offset'] = ftell($handle)) {
                    $i = $this->config['importSpeed'];
                }
                $response['row'] = [];
                $offset = $this->sanitize($offset, $response['divider']);
                foreach ($response['header'] as $headerId => $header) {
                    if (is_array($header)) {
                        foreach ($header as $valueId => $value) {
                            $response['row'][$headerId][$valueId] = $offset[$value];
                        }
                    } else {
                        $response['row'][$headerId] = $offset[$header];
                    }
                }
                $service = $this->grid->getImport();
                $response = $service->run($response, $this);
            }
        /** export */
        } elseif(in_array($response['status'], ['export', 'excel'])) {
            $service = $this->grid->getExport();
            $path = $service->getFile() . '/' . $response['file'];
            $response['limit'] = $this->config['exportSpeed'];
            $response['row'] = $this->grid->prepare()->getOffsets();
            $response = $service->run($response, $this);
            if('export' == $response['status']) {
                $handle = fopen('nette.safe://' . $path, 'a');
            } else {
                $excel = PHPExcel_IOFactory::load($path);
                $excel->setActiveSheetIndex(0);
                $last = $excel->getActiveSheet()->getHighestRow();
            }
            foreach($response['row'] as $rowId => $cells) {
                foreach($cells as $cellId => $cell) {
                    if($cell instanceof DateTime) {
                        $response['row'][$rowId][$cellId] = $cell->__toString();
                    } else if(false == $this->grid->getAnnotation($cellId, ['unrender', 'hidden']) && isset($cell['Attributes'])) {
                        $response['row'][$rowId][$cellId] = $cell['Attributes']['value'];
                    } else if(false == $this->grid->getAnnotation($cellId, ['unrender', 'hidden'])) {
                        $response['row'][$rowId][$cellId] = $cell;
                    } else {
                        unset($response['row'][$rowId][$cellId]);
                    }
                }
                if('export' == $response['status']) {
                    fputs($handle, PHP_EOL . implode(';', $response['row'][$rowId]));
                } else {
                    $last++;
                    $letter = 'a';
                    foreach ($response['row'][$rowId] as $cell) {
                        $excel->getActiveSheet()->SetCellValue($letter++ . $last, $cell);
                    }
                }
            }
            if('export' == $response['status']) {
                fclose($handle);
            } else {
                $writer = new PHPExcel_Writer_Excel2007($excel);
                $writer->save($path);
            }
            $response['offset'] = $response['offset'] + $this->config['exportSpeed'];
        /** process */
        } else {
            $service = $this->grid->getService();
            $response['offset'] = $response['offset'] + 1;
            if(!empty($response['row'] = $this->grid->prepare()->getOffset($response['offset']))) {
                $response = $service->run($response, $this);
            }
        }
        $setting = $service->getSetting();
        $callbacks = is_object($setting) ? json_decode($setting->callback) : [];
        foreach ($callbacks as $callbackId => $callback) {
            $sanitize = preg_replace('/print|echo|exec|call|eval|mysql/', '', $callback);
            eval('function call($response["row"]) {' . $sanitize . '}');
            $response['row'] = call($response['row']);
        }
        return $this->presenter->sendResponse(new JsonResponse($response));
    }

    public function render() {
        $this->template->assets = $this->config['assets'];
        $this->template->npm = $this->config['npm'];
        $this->template->locale = preg_replace('/(\_.*)/', '', $this->translatorModel->getLocale());
        $this->template->dialogs = ['edit', 'help', 'import', 'message', 'process'];
        $this->template->grid = $this->grid;
        $this->template->help = $this->helpRepository->getHelp($this->presenter->getName(), $this->presenter->getAction(), $this->request->getUrl()->getQuery());
        $columns = $this->grid->getColumns();
        $this->template->order = reset($columns);
        $this->template->setFile(__DIR__ . '/templates/@layout.latte');
        $this->template->setTranslator($this->translatorModel);
        $this->template->settings = json_decode($this->presenter->getUser()->getIdentity()->__get('settings'));
        $this->template->render();
    }

    private function sanitize($row, $divider) {
        return explode($divider, preg_replace('/\<\?php|\"/', '', $row));
    }

    /** @return IGridFactory */
    protected function createComponentGrid() {
        return $this->gridFactory->create()
            ->setGrid($this->grid);
    }

    /** @return IImportFormFactory */
    protected function createComponentImportForm() {
        return $this->importFormFactory->create()
            ->setService($this->grid->getImport());
    }

    /** @return IProcessFormFactory */
    protected function createComponentProcessForm() {
        return $this->processFormFactory->create()
            ->setService($this->grid->getService());
    }

}

interface IMasalaFactory {

    /** @return Masala */
    function create();

}
