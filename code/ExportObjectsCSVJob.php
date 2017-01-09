<?php
/**
 * This queued job is to export data into a CSV for downloading.
 *
 */
class ExportObjectsCSVJob extends AbstractQueuedJob implements QueuedJob {

    protected $file;
    protected $dataList;
    protected $fields;

    /**
     * Constructor
     */
    public function __construct() {
        $this->dataList = $this->getDataList();
    }

    /**
     * @return string
     */
    public function getTitle() {
        return 'Export objects to CSV';
    }

    /**
     * Return a signature for this queued job
     *
     * @return string
     */
    public function getSignature() {
        return md5(get_class($this) . serialize($this->dataList));
    }
    
    /**
     * Return "Queued" job type
     */
    public function getJobType() {
        return QueuedJob::QUEUED;
    }

    /**
     * Gathers data to be exported and returns data as ExportCSVObject
     * @return DataList
     */
    protected function getDataList() {
        return DataList::create('SiteTree');
    }

    /**
     * Returns fields to be exported
     * @return Array
     */
    protected function getFields($object) {
        return array_merge(array('ID' => 'ID'), $object->summaryFields());
    }

    /**
     * Returns file path for exporting data
     * @return string
     */
    protected function getFilePath() {
        return ASSETS_PATH . '/' . $this->getSignature() . '.csv';
    }

    /**
     * Returns md5_file of exported data
     * @return string
     */
    protected function getActualFileHash() {
        return md5_file($this->getFilePath());
    }

    /**
     * Return number of objects to process on each iteration.
     * this can be overwritten to work with more or less data per iteration.
     */
    public function getNumberToProcess() {
        return 100;
    }

    /**
     * Validate fields
     * @return Boolean
     */
    private function validateFields() {
        if (empty($this->fields)) {
            $this->addMessage("No fields defined", 'ERROR');
            return false;
        }
        return true;
    }

    /**
     * Validate file handler
     * @return Boolean
     */
    private function validateFile($reset = false) {
        if ($reset) {
            @fclose($this->file);
        }

        if (!$this->file = fopen($this->getFilePath(), 'a')){
            $this->addMessage("Cannot open file: " . $this->getFilePath(), 'ERROR');
            return false;
        }

        return true;
    }

    /**
     * Store fields to be exported.
     */
    private function prepareFields() {
        $firstRecord = $this->dataList->first();
        $this->fields = $this->getFields($firstRecord);
    }

    /**
     * Setup this queued job. This is only called the first time this job is executed
     * (ie when currentStep is 0)
     */
    public function setup(){
        parent::setup();

        // Start from beginning of records
        $this->currentStep = 0;
        $this->dataList = $this->getDataList();

        if (file_exists($this->getFilePath())){
            $this->addMessage("CSV already generated");
            return false;
        }

        if(empty($this->dataList)) {
            $this->addMessage("No Datalist data", 'ERROR');
            return;
        }

        $this->totalSteps = $this->dataList->count();
        $this->prepareFields();

        if (!$this->validateFields()) return;
        if (!$this->validateFile()) return;
        
                // Put header info
        if (!fputcsv($this->file, $this->fields)) {
            $this->addMessage("Unable to write data to: " . $this->getFilePath(), 'ERROR');
            return false;
        }

    }

    /**
     * process per-line data to be exported to CSV
     * return Array
     */
    public function getExportData($object) {
        $data = array();

        foreach ($this->fields as $key => $field) {
            $data[] = $object->getField($key) ?: '';
        }

        return $data;
    }

    /**
     * Run when an already setup job is being restarted.
     */
    public function prepareForRestart() {
        parent::prepareForRestart();

        $this->messages = array();
        $this->dataList = $this->getDataList();

        if(empty($this->dataList)) {
            $this->addMessage("No Datalist data", 'ERROR');
            return;
        }

        $this->prepareFields();

        if (!$this->validateFields()) return;
        if (!$this->validateFile(true)) return;
        
        // Put header info if restarted from beginning
        if ($this->currentStep <= 0 && !fputcsv($this->file, $this->fields)) {
            $this->addMessage("Unable to write data to: " . $this->getFilePath(), 'ERROR');
            return;
        }
    }

    private function exportDataTo() {
        if (!$object = $this->dataList->offsetGet($this->currentStep)){
            $this->addMessage("No offset found: ". $this->currentStep, 'ERROR');
            return false;
        }

        if (!$object->exists()) {
            $this->addMessage("No Object found", 'ERROR');
            return false;
        }

        // Get Export data
        if (!$exportData = $this->getExportData($object)) {
            $this->isComplete = true;
            return false;
        }
        $object = null;

        // Do export of data
        if (!fputcsv($this->file, $exportData))
        {
            $this->addMessage("Unable to write data to: " . $this->getFilePath(), 'ERROR');
            $this->isComplete = true;
            return false;
        }
        return true;
    }

    /**
     * Process export to CSV
     *
     */
    public function process() {
        ini_set('max_execution_time', -1);

        // If dataList / fields has not been populated as part of setup then exit.
        if(empty($this->dataList) || empty($this->fields) || $this->totalSteps <= 0) {
            $this->addMessage("No data", 'ERROR');
            $this->isComplete = true;
            return;
        }
        $processCount = 0;

        // Export X number of objects in dataList
        // the database every time
        if ($this->currentStep == 0 || $this->StoredFileHash === $this->getActualFileHash()) {
            while ($this->currentStep < $this->totalSteps) {
                if (!$this->exportDataTo()) return;

                $this->currentStep++;
                $processCount++;           

                // Break loop after X number of objects are exported
                if ($processCount >= $this->getNumberToProcess()) {
                    break;
                }
            }

            // If all records have been exported, finish the task.
            if ($this->currentStep >= $this->totalSteps) {
                $this->isComplete = true;
                return;
            }

            // Store current file hash
            $this->StoredFileHash = $this->getActualFileHash();
        } else {
            // Wait 30 seconds to see if data is replicated between multiple servers correctly.
            sleep(30);
            $this->prepareForRestart();
        }

    }
}