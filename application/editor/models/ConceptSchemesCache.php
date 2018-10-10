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
 *
 * @category   OpenSKOS
 * @package    OpenSKOS
 * @copyright  Copyright (c) 2015 Picturae (http://www.picturae.com)
 * @author     Picturae
 * @license    http://www.gnu.org/licenses/gpl-3.0.txt GPLv3
 */

use OpenSkos2\ConceptSchemeManager;
use OpenSkos2\ConceptSchemeCollection;
use OpenSkos2\Namespaces\OpenSkos;
use OpenSkos2\Namespaces\DcTerms;
use OpenSkos2\Rdf\Literal;
use OpenSkos2\ConceptScheme;
use OpenSkos2\Exception\OpenSkosException;

class Editor_Models_ConceptSchemesCache
{
    const CONCEPT_SCHEMES_CACHE_KEY = 'CONCEPT_SCHEMES_CACHE_KEY';
    
    /**
     * @var string 
     */
    protected $institutionCode;
    
    /**
     * @var ConceptSchemeManager 
     */
    protected $manager;
    
    /**
     * @var Zend_Cache_Core 
     */
    protected $cache;
    
    /**
     * Get tenant for which the cache is done.
     * @return string
     */
    public function getInstitutionCode()
    {
        return $this->institutionCode;
    }
    
    /**
     * Get tenant for which the cache is done.
     * @return string
     */
    public function requireInstitutionCode()
    {
        if (empty($this->institutionCode)) {
            throw new OpenSkosException('Institution code is required for editor cache.');
        }
        //Have to strip some characters from the cache
        $tenantCode = preg_replace('#[^a-zA-Z0-9_]#', '_', $this->institutionCode);

        return $tenantCode;
    }

    /**
     * Sets tenant for which the cache is.
     * @param string $institutionCode
     */
    public function setInstitutionCode($institutionCode)
    {
        $this->institutionCode = $institutionCode;
    }
    
    /**
     * @param string $tenantCode
     * @param ConceptSchemeManager $manager
     * @param Zend_Cache_Core $cache
     */
    public function __construct(ConceptSchemeManager $manager, Zend_Cache_Core $cache)
    {
        $this->manager = $manager;
        $this->cache = $cache;
    }
    
    /**
     * Clears the concept schemes cache.
     */
    public function clearCache()
    {
        $this->cache->clean();
    }
    
    /**
     * Fetches all schemes.
     * @return ConceptSchemeCollection
     */
    public function fetchAll()
    {
        $schemes = $this->cache->load(self::CONCEPT_SCHEMES_CACHE_KEY . $this->requireInstitutionCode());
        if ($schemes === false) {
            $schemes = $this->sortSchemes(
                $this->manager->fetch(
                    [],
                    null,
                    null,
                    true
                )
            );
            
            $this->cache->save($schemes, self::CONCEPT_SCHEMES_CACHE_KEY . $this->requireInstitutionCode());
        }
        
        return $schemes;
    }
    
    /**
     * Fetches uri -> scheme map.
     * @return ConceptScheme[]
     */
    public function fetchUrisMap()
    {
        $shemes = $this->fetchAll();
        $result = [];
        foreach ($shemes as $scheme) {
            $result[$scheme->getUri()] = $scheme;
        }
        return $result;
    }
    
    /**
     * Fetches uri -> caption map.
     * @return ConceptScheme[]
     */
    public function fetchUrisCaptionsMap($inCollections = [])
    {
        $allSchemes = $this->fetchAll();
        $result = [];

        foreach ($allSchemes as $scheme) {
            $set = $scheme->getSet();
            if (empty($inCollections) || in_array($set[0]->getUri(), $inCollections)) {
                $result[$scheme->getUri()] = $scheme->getTitle();
            }
        }
        return $result;
    }
    
    /**
     * Fetches array with concept schemes meta data.
     * @param array $shemesUris
     * @return array
     */
    public function fetchConceptSchemesMeta($shemesUris)
    {
        $shemes = $this->fetchAll();
        $result = [];
        foreach ($shemesUris as $uri) {
            $scheme = $shemes->findByUri($uri);
            if ($scheme) {
                $schemeMeta = $scheme->toFlatArray([
                    'uri',
                    'caption',
                    DcTerms::TITLE
                ]);
                $schemeMeta['iconPath'] = $scheme->getIconPath();
                $result[] = $schemeMeta;
            }
        }
        return $result;
    }
    
    /**
     * Sorts the schemes by collection first and alphabetically second. Collection sort order is given in the ini.
     * @param ConceptSchemeCollection $schemes
     */
    protected function sortSchemes($schemes)
    {
        $sortedSchemes = new ConceptSchemeCollection();
        
        // The preferred order from the ini
        $orderedCollections = [];
        
        $editorConfig = Zend_Controller_Front::getInstance()->getParam('bootstrap')->getOption('editor');
        if (!empty($editorConfig['schemeOrder']['collections'])) {
            foreach ($editorConfig['schemeOrder']['collections'] as $collectionUri) {
                $orderedCollections[$collectionUri] = [];
            }
        }
        
        foreach ($schemes as $scheme) {
            /* @var $scheme ConceptScheme */
            $collectionUri = $scheme->getProperty(OpenSkos::SET)[0]->getUri();
            
            // Add missing collections to the ordered list
            if (!array_key_exists($collectionUri, $orderedCollections)) {
                $orderedCollections[$collectionUri] = [];
            }
            
            // Group the schemes by their collection
            $orderedCollections[$collectionUri][$scheme->getUri()] = $scheme->getCaption();
        }
        
        // Order by name each collection's schemes
        foreach ($orderedCollections as $collectionUri => &$collectionSchemes) {
            natcasesort($collectionSchemes);
            
            foreach ($collectionSchemes as $schemeUri => $schemeCaption) {
                $sortedSchemes->append($schemes->findByUri($schemeUri));
            }
        }
        
        return $sortedSchemes;
    }
}
