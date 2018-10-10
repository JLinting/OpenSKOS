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

use OpenSkos2\SetManager;
use OpenSkos2\SetCollection;
use OpenSkos2\Namespaces\OpenSkos;
use OpenSkos2\Namespaces\DcTerms;
use OpenSkos2\Rdf\Literal;
use OpenSkos2\ConceptScheme;
use OpenSkos2\Exception\OpenSkosException;

class Editor_Models_SetsCache
{
    const CONCEPT_CACHE_KEY = 'CONCEPT_CACHE_KEY';
    
    /**
     * @var string 
     */
    protected $institutionCode;
    
    /**
     * @var SetManager
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
     * @param SetManager $manager
     * @param Zend_Cache_Core $cache
     */
    public function __construct(SetManager $manager, Zend_Cache_Core $cache)
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
     * @return SetCollection
     */
    public function fetchAll()
    {

        $schemes = $this->cache->load(self::CONCEPT_CACHE_KEY . $this->requireInstitutionCode());
        if ($schemes === false) {
            /*
            $schemes = $this->sortSchemes(
                $this->manager->fetch(
                    [OpenSkos::TENANT => new Literal($this->requireInstitutionCode())],
                    null,
                    null,
                    true
                )
            );
            */
            $schemes = $this->manager->fetch(
                [OpenSkos::TENANT => new Literal($this->requireInstitutionCode())],
                null,
                null,
                true
            );

            $this->cache->save($schemes, self::CONCEPT_CACHE_KEY . $this->requireInstitutionCode());
        }

        return $schemes;
    }
    
    /**
     * Fetches uri -> scheme map.
     * @return ConceptScheme[]
     */
    public function fetchUrisMap()
    {
        $schemes = $this->fetchAll();
        $result = [];
        foreach ($schemes as $scheme) {
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
            if (empty($inCollections) || in_array($scheme->getSet(), $inCollections)) {
                $result[$scheme->getUri()] = $scheme->getCaption();
            }
        }
        return $result;
    }
    
    /**
     * Fetches array with concept schemes meta data.
     * @param array $schemesUris
     * @return array
     */
    public function fetchConceptSchemesMeta($schemesUris)
    {
        $schemes = $this->fetchAll();

        foreach ($schemesUris as $uri) {
            $scheme = $schemes->findByUri($uri);
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
}
