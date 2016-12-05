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
        return md5(get_class($this) . serialize($this->jobData) . serialize($this->dataList));
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

    protected function getFilePath() {
        return ASSETS_PATH . '/' . $this->getSignature() . '.csv';
    }

    /**
     * Return number of objects to process on each iteration.
     * this can be overwritten to work with more or less data per iteration.
     */
    public function getNumberToProcess() {
        return 100;
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
        $this->fields = $this->getFields($this->dataList->first());


        if (empty($this->fields)) {
            $this->addMessage("No fields defined", 'ERROR');
            return;
        }
        
        if (!$this->file = fopen($this->getFilePath(), 'a')){
            $this->addMessage("Cannot open file: " . $this->getFilePath(), 'ERROR');
            return;
        }
        
        // Put header info
        if (!fputcsv($this->file, $this->fields)) {
            $this->addMessage("Unable to write data to: " . $this->getFilePath(), 'ERROR');
            return;
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

        $this->dataList = $this->getDataList();

        if(empty($this->dataList)) {
            $this->addMessage("No Datalist data", 'ERROR');
            return;
        }

        $this->fields = $this->getFields($this->dataList->first());


        if (empty($this->fields)) {
            $this->addMessage("No fields defined", 'ERROR');
            return;
        }
        
        if (!$this->file = fopen($this->getFilePath(), 'a')){
            $this->addMessage("Cannot open file: " . $this->getFilePath(), 'ERROR');
            return;
        }
        
        // Put header info
        if (!fputcsv($this->file, $this->fields)) {
            $this->addMessage("Unable to write data to: " . $this->getFilePath(), 'ERROR');
            return;
        }
    }

    /**
     * Process export to CSV
     *
     */
    public function process() {
        ini_set('max_execution_time', -1);

        if (!empty($this->messages)) {
            $this->isComplete = true;
            return;
        }

        // If dataList / fields has not been populated as part of setup then exit.
        if(empty($this->dataList) || empty($this->fields) || $this->totalSteps <= 0) {
            $this->addMessage("No data", 'ERROR');
            $this->isComplete = true;
            return;
        }

        $processCount = 0;

        // Export X number of objects in dataList
        // the database every time
        while ($this->currentStep < $this->totalSteps) {

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
                return;
            }

            // Do export of data
            if (!fputcsv($this->file, $exportData))
            {
                $this->addMessage("Unable to write data to: " . $this->getFilePath(), 'ERROR');
                $this->isComplete = true;
                return;
            }

            $this->currentStep++;
            $processCount++;           

            if ($processCount >= $this->getNumberToProcess()) {
                break;
            }
        }

        if ($this->currentStep >= $this->totalSteps) {
            $this->isComplete = true;
            return;
        }
    }
}