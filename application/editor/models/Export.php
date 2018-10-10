<?php

/**
 * OpenSKOS
 *
 * LICENSE
 *
 * This source file is subject to the GPLv3 license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.gnu.org/licenses/gpl-3.0.txt
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@zend.com so we can send you a copy immediately.
 *
 * @category   OpenSKOS
 * @package    OpenSKOS
 * @copyright  Copyright (c) 2012 Pictura Database Publishing. (http://www.pictura-dp.nl)
 * @author     Alexandar Mitsev
 * @license    http://www.gnu.org/licenses/gpl-3.0.txt GPLv3
 */

use OpenSkos2\Export\Message;
use OpenSkos2\Export\Serialiser\FormatFactory;
use OpenSkos2\Namespaces\OpenSkos;
use OpenSkos2\Namespaces\DcTerms;
use OpenSkos2\Namespaces\Dc;
use OpenSkos2\Rdf\Resource;

class Editor_Models_Export
{
    /**
     * Constant for the maximum number of concepts available for export.
     * @var int
     */
    const MAX_RECORDS_FOR_INSTANT_EXPORT = 1000;

    /**
     * Hours before export gets old and should be removed.
     *
     * @var int The lifetime in hours
     */
    const EXPORT_FILE_LIFETIME = 48; //In hours

    /**
     * Holds all the settings used for export.
     *
     * @var array
     */
    public $settings = array();

    /**
     * Sets setting.
     *
     * @param string $setting
     * @param mixed $value
     * @return Editor_Models_Export
     */
    public function set($setting, $value)
    {
        $this->settings[$setting] = $value;
        return $this;
    }

    /**
     * Sets all settings.
     *
     * @param array $settings
     * @return Editor_Models_Export
     */
    public function setSettings($settings)
    {
        $this->settings = $settings;
        return $this;
    }

    /**
     * Get a setting. Throws error if not found.
     *
     * @param string $setting
     * @return mixed
     * @throws Zend_Exception
     */
    public function get($setting)
    {
        if ($this->has($setting)) {
            return $this->settings[$setting];
        } else {
            throw new Zend_Exception('Setting "' . $setting . '" in Editor_Models_Export must be specified.');
        }
    }

    /**
     * Is a setting specified.
     *
     * @param string $setting
     * @return bool
     */
    public function has($setting)
    {
        return isset($this->settings[$setting]);
    }

    /**
     * Get all settings.
     *
     * @return array
     */
    public function getSettings()
    {
        return $this->settings;
    }

    /**
     * Exports to file with the specified settings.
     *
     * @param array $editorOptions, optional
     * @return string The relative (up to main export dir) path to the exported file
     */
    public function exportToString()
    {
        $command = $this->getDi()->make('OpenSkos2\Export\Command');
        return $command->handle($this->createExportMessage());
    }
    
    /**
     * Exports to file with the specified settings.
     *
     * @param array $editorOptions, optional
     * @return string The relative (up to main export dir) path to the exported file
     */
    public function exportToFile()
    {
        $mainDirPath = $this->getExportFilesDirPath();

        if (!is_dir($mainDirPath)) {
            throw new Zend_Exception(
                'Directory "' . $mainDirPath . '" must exist and must '
                . 'have read and write rights for the export to work.'
            );
        }

        // Clean old exports.
        $this->cleanUpOldExports($mainDirPath);

        // Creates the new export directory
        $exportDirName = uniqid();
        $dirPath = rtrim($mainDirPath, '/') . '/' . $exportDirName;
        mkdir($dirPath);

        $fileDetails = $this->getExportFileDetails();
        $filePath = $dirPath . '/' . $fileDetails['fileName'];
        
        $message = $this->createExportMessage();
        $message->setOutputFilePath($filePath);
        
        $command = $this->getDi()->make('OpenSkos2\Export\Command');
        $command->handle($message);

        return $exportDirName . '/' . $fileDetails['fileName'];
    }

    /**
     * Creates an openskos background job to export with the given settings to a file.
     *
     * @return int The job id
     */
    public function exportWithBackgroundJob()
    {

        $diContainer =  \Zend_Controller_Front::getInstance()->getDispatcher()->getContainer();
        $tenantManager = $diContainer->get('OpenSkos2\InstitutionManager');

        $user = OpenSKOS_Db_Table_Users::requireById($this->get('userId'));

        $tenant = \OpenSkos2\InstitutionManager::getLoggedInTenant();
        $tenantUri = $tenant->getUri();

        $tenantSets = $tenantManager->fetchSetsForTenantUri($tenantUri);
        $sets = \OpenSkos2\Bridge\EasyRdf::graphToResourceCollection($tenantSets);

        if (!$sets->count()) {
            throw new Zend_Exception('Current tenant does not have any collections. At least one is required.', 404);
        }

        // We use the first collection of the tenant for the export job,
        // because the collection is important for the jobs,
        // but the export is not related to any specific collection.
        $firstTenantSet = $sets[0];
        $set_uri = $firstTenantSet->getUri();

        $model = new OpenSKOS_Db_Table_Jobs();
        $job = $model->fetchNew()->setFromArray(array(
                    'set_uri' => $set_uri,
                    'user' => $user->id,
                    'task' => OpenSKOS_Db_Table_Row_Job::JOB_TASK_EXPORT,
                    'parameters' => serialize(
                        $this->getSettings()
                    ),
                    'created' => new Zend_Db_Expr('NOW()')
                ))->save();

        return $job;
    }
    
    /**
     * Gets the path to the dir where the export files should be placed.
     *
     * @return string
     */
    public function getExportFilesDirPath()
    {
        $editorOptions = OpenSKOS_Application_BootstrapAccess::getOption('editor');

        if (isset($editorOptions['export']['filesPath'])) {
            $mainDirPath = $editorOptions['export']['filesPath'];
        } else {
            $mainDirPath = APPLICATION_PATH . '/../public/data/export';
        }

        $mainDirPath = rtrim($mainDirPath, '/') . '/';

        return $mainDirPath;
    }

    /**
     * Remove any old export files.
     *
     * @param string $mainDir
     */
    protected function cleanUpOldExports($mainDir)
    {
        $mainDir = rtrim($mainDir, '/') . '/'; // Ensure that the dir path ends with /

        $exportDirectories = scandir($mainDir);

        foreach ($exportDirectories as $currentDir) {
            if ($currentDir != '.' && $currentDir != '..') {
                $exportFiles = scandir($mainDir . $currentDir);

                foreach ($exportFiles as $currentFile) {
                    if ($currentFile != '.' && $currentFile != '..') {
                        $fileModified = filemtime($mainDir . $currentDir . '/' . $currentFile);
                        $fileOldTimeLimit = strtotime('- ' . self::EXPORT_FILE_LIFETIME . ' hours');
                        if ($fileModified < $fileOldTimeLimit) {
                            unlink($mainDir . $currentDir . '/' . $currentFile);
                        }
                    }
                }

                // If the directory remains empty - remove it
                $exportFiles = scandir($mainDir . $currentDir);
                if (count($exportFiles) <= 2) { // Ignore "." and ".."
                    rmdir($mainDir . $currentDir);
                }
            }
        }
    }
    
    /**
     * Determines if the export will take a lot of time.
     * This happens in the fallowing cases.
     * 1. The number of concepts is higher than the MAX_RECORDS_FOR_INSTANT_EXPORT constant.
     *
     * @return bool
     */
    public function isTimeConsumingExport()
    {
        // Export is slow if depth is more than 1
        if ($this->get('maxDepth') > 1) {
            return true;
        }

        // Export is slow if export type is search and the search results are more than MAX_RECORDS_FOR_INSTANT_EXPORT
        if ($this->get('type') == 'search') {
            $searchOptions = $this->get('searchOptions');
            $searchOptions['start'] = 0;
            $searchOptions['rows'] = 0;

            $this->getDi()->make('OpenSkos2\Search\Autocomplete')
                ->search($searchOptions, $count);

            return $count > Editor_Models_Export::MAX_RECORDS_FOR_INSTANT_EXPORT;
        }

        return false;
    }
    
    /**
     * Gets an array of concept fields that can be exported.
     *
     * @return array
     */
    public static function getExportableConceptFields()
    {
        $result = array();
        $result[] = 'uri';
        
        $result[] = OpenSkos::UUID;
        $result[] = OpenSkos::STATUS;
        $result[] = OpenSkos::TOBECHECKED;
        
        foreach (Resource::$classes as $fieldsInClass) {
            $result = array_merge($result, $fieldsInClass);
        }
        
        $result[] = DcTerms::CREATED;
        $result[] = DcTerms::CREATOR;
        $result[] = Dc::CREATOR;
        $result[] = DcTerms::DATEACCEPTED;
        $result[] = OpenSkos::ACCEPTEDBY;
        $result[] = DcTerms::MODIFIED;
        $result[] = OpenSkos::MODIFIEDBY;
        
        $result[] = DcTerms::CONTRIBUTOR;
        $result[] = Dc::CONTRIBUTOR;
        
        return $result;
    }

    /**
     * Gets the file details for export depending on the export format.
     *
     * @return array
     */
    public function getExportFileDetails()
    {
        switch ($this->get('format')) {
            case FormatFactory::FORMAT_XML:
                return array('fileName' => $this->get('outputFileName') . '.xml', 'mimeType' => 'text/xml');
            case FormatFactory::FORMAT_CSV:
                return array('fileName' => $this->get('outputFileName') . '.csv', 'mimeType' => 'text/csv');
            case FormatFactory::FORMAT_RTF:
                return array('fileName' => $this->get('outputFileName') . '.rtf', 'mimeType' => 'application/rtf');
            default:
                throw new \RuntimeException('No file info for format "' . $this->get('format') . '"');
        }
    }
    
    /**
     * @return Message
     */
    protected function createExportMessage()
    {

        $diContainer =  \Zend_Controller_Front::getInstance()->getDispatcher()->getContainer();
        $tenantManager = $diContainer->get('OpenSkos2\InstitutionManager');

        $fieldsToExport = $this->get('fieldsToExport');
        if (empty($fieldsToExport)) {
            $fieldsToExport = $this->getExportableConceptFields();
        }
        
        $user = OpenSKOS_Db_Table_Users::requireById($this->get('userId'));
        $tenant = $tenantManager->fetchTenantFromCode($user->tenant);

        $message = new Message(
            $tenant,
            $this->get('format'),
            $fieldsToExport,
            $this->get('maxDepth')
        );
        
        if ($this->get('type') == 'search') {
            $message->setSearchOptions($this->get('searchOptions'));
        } else {
            $message->setUris($this->get('uris'));
        }
        
        return $message;
    }
    
    /**
     * return \DI\Container
     */
    protected function getDi()
    {
        return Zend_Controller_Front::getInstance()->getDispatcher()->getContainer();
    }
}
