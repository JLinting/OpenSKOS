<?php

/*
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

require_once 'AbstractController.php';

class Api_FindConceptsController extends AbstractController
{

    protected $isFindConcept;

    public function init()
    {
        parent::init();
        $this->apiResourceClass = 'OpenSkos2\Api\Concept';
        $this->viewpath = "concept/";
        $this->isFindFoncept = true;
    }

    /**
     * @apiVersion 1.0.0
     * @apiDescription Find a SKOS Concept
     * The following requests are possible
     *
     * <a href='/api/find-concepts?q=doood' target='_blank'>/api/find-concepts?q=doood</a>
     *
     * <a href='/api/find-concepts?q=do*' target='_blank'>/api/find-concepts?q=do*</a>
     *
     * <a href='/api/find-concepts?q=prefLabel:dood' target='_blank'>/api/find-concepts?q=prefLabel:dood</a>
     *
     * <a href='/api/find-concepts?q=do* status:approved' target='_blank'>/api/find-concepts?q=do* status:approved</a>
     *
     * <a href='/api/find-concepts?q=prefLabel:do*&rows=0' target='_blank'>/api/find-concepts?q=prefLabel:do*&rows=0</a>
     *
     * <a href='/api/find-concepts?q=prefLabel@nl:doo' target='_blank'>/api/find-concepts?q=prefLabel@nl:doo</a>
     *
     * <a href='/api/find-concepts?q=prefLabel@nl:do*' target='_blank'>/api/find-concepts?q=prefLabel@nl:do*</a>
     *
     * <a href='/api/find-concepts?q=do*&tenant=beng&collection=gtaa' target='_blank'>/api/find-concepts?q=do*&tenant=beng&collection=gtaa</a>
     *
     * <a href='/api/find-concepts?q=do*&scheme=http://data.cultureelerfgoed.nl/semnet/objecten' target='_blank'>/api/find-concepts?q=do*&scheme=http://data.cultureelerfgoed.nl/semnet/objecten</a>
     *
     * Skos-XL labels can be fetched instead of simple labels for each of the valid requests by specifying the xl and tenant parameters
     *
     * <a href='/api/find-concepts?q=do*&xl=1&tenant=pic' target='_blank'>/api/find-concepts?q=do*&xl=1&tenant=pic</a>
     *
     * @api {get} /api/find-concepts Find a concept
     * @apiName FindConcepts
     * @apiGroup FindConcept
     * @apiParam {String} q search term
     * @apiParam {String} rows Number of rows to return
     * @apiParam {String} fl List of fields to return
     * @apiParam {String} tenant Name of the tenant to query. Default is all tenants
     * @apiParam {String} collection OpenSKOS set to query. Default is all sets
     * @apiParam {String} scheme id of the SKOS concept scheme to query. Default is all schemes
     * @apiSuccess (200) {String} XML
     * @apiSuccessExample {String} Success-Response
     *   HTTP/1.1 200 Ok
     *   &lt;?xml version="1.0"?>
     *      &lt;rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
     *          xmlns:skos="http://www.w3.org/2004/02/skos/core#"
     *          xmlns:dc="http://purl.org/dc/elements/1.1/"
     *          xmlns:dcterms="http://purl.org/dc/terms/"
     *          xmlns:openskos="http://openskos.org/xmlns#"
     *          xmlns:owl="http://www.w3.org/2002/07/owl#"
     *          xmlns:rdfs="http://www.w3.org/2000/01/rdf-schema#"
     *          openskos:numFound="15"
     *          openskos:start="0">
     *   &lt;rdf:Description xmlns:dc="http://purl.org/dc/terms/"
     *      rdf:about="http://data.cultureelerfgoed.nl/semnet/efc584d7-9880-43fb-9a0b-76f3036aa315">
     *      &lt;rdf:type rdf:resource="http://www.w3.org/2004/02/skos/core#Concept"/>
     *         &lt;skos:prefLabel xml:lang="nl">doodshemden&lt;/skos:prefLabel>
     *         &lt;skos:altLabel xml:lang="nl">doodshemd&lt;/skos:altLabel>
     *         &lt;openskos:tenant>rce&lt;/openskos:tenant>
     *         &lt;skos:notation>1183132&lt;/skos:notation>
     *         &lt;skos:inScheme rdf:resource="http://data.cultureelerfgoed.nl/semnet/erfgoedthesaurus"/>
     *         &lt;skos:inScheme rdf:resource="http://data.cultureelerfgoed.nl/semnet/objecten"/>
     *         &lt;openskos:uuid>945bb5a9-0277-9df4-d206-a129bc144da4&lt;/openskos:uuid>
     *         &lt;skos:related rdf:resource="http://data.cultureelerfgoed.nl/semnet/77f6ff1b-b603-4a76-a264-10b3f25eb7df"/>
     *         &lt;dc:modified rdf:datatype="http://www.w3.org/2001/XMLSchema#dateTime">2015-07-03T09:30:05+00:00&lt;/dc:modified>
     *         &lt;skos:definition xml:lang="nl">Albevormig hemd waarin een dode wordt gekleed.&lt;/skos:definition>
     *         &lt;skos:broader rdf:resource="http://data.cultureelerfgoed.nl/semnet/7deba87b-1ac5-450f-bff7-78865d3b4742"/>
     *         &lt;dc:dateSubmitted rdf:datatype="http://www.w3.org/2001/XMLSchema#dateTime">2015-07-03T09:27:56+00:00&lt;/dc:dateSubmitted>
     *         &lt;openskos:dateDeleted rdf:datatype="http://www.w3.org/2001/XMLSchema#dateTime">2015-10-09T09:33:06+00:00&lt;/openskos:dateDeleted>
     *         &lt;openskos:status>deleted&lt;/openskos:status>
     *         &lt;openskos:collection rdf:resource="http://openskos.org/api/collections/rce:EGT"/>
     *     &lt;/rdf:Description>
     *   &lt;/rdf:RDF>
     *
     */
    public function indexAction()
    {
        if (null === ($q = $this->getRequest()->getParam('q'))) {
            $this->getResponse()
                ->setHeader('X-Error-Msg', 'Missing required parameter `q`');
            throw new Zend_Controller_Exception(
                'Missing required parameter `q`',
                \OpenSkos2\Http\StatusCodes::BAD_REQUEST
            );
        }
        $this->getHelper('layout')->disableLayout();
        $this->_helper->viewRenderer->setNoRender(true);
        $concept = $this->getDI()->make('OpenSkos2\Api\Concept');
        $context = $this->_helper->contextSwitch()->getCurrentContext();
        if (!isset($context)) {
            $context='rdf';
        }
        $request = $this->getPsrRequest();
        $response = $concept->findConcepts($request, $context);
        $this->emitResponse($response);
    }

    /**
     * @apiVersion 1.0.0
     * @apiDescription Return a specific concept
     * The following requests are valid
     *
     * <a href='/api/concept/1b345c95-7256-4bb2-86f6-7c9949bd37ac.rdf' target='_blank'>/api/concept/1b345c95-7256-4bb2-86f6-7c9949bd37ac.rdf (rdf format)</a>
     *
     * <a href='/api/concept/1b345c95-7256-4bb2-86f6-7c9949bd37ac.html' target='_blank'>/api/concept/1b345c95-7256-4bb2-86f6-7c9949bd37ac.html (html format)</a>
     *
     * <a href='/api/concept/1b345c95-7256-4bb2-86f6-7c9949bd37ac.json' target='_blank'>/api/concept/1b345c95-7256-4bb2-86f6-7c9949bd37ac.json (json format)</a>
     *
     * <a href='/api/concept/82c2614c-3859-ed11-4e55-e993c06fd9fe.jsonp&callback=test' target='_blank'>/api/concept/82c2614c-3859-ed11-4e55-e993c06fd9fe.jsonp&callback=test (jsonp format)</a>
     *
     * <a href='/api/concept/?id=http://example.com/1' target='_blank'>/api/concept/?id=http://example.com/1 (rdf format)</a>
     *
     * Skos-XL labels can be fetched instead of simple labels for each of the valid requests by specifying the xl parameter
     *
     * <a href='/api/concept/?id=http://example.com/1&xl=1' target='_blank'>/api/concept/?id=http://example.com/1&xl=1</a>
     *
     * <a href='/api/concept/1b345c95-7256-4bb2-86f6-7c9949bd37ac.json?xl=1' target='_blank'>/api/concept/1b345c95-7256-4bb2-86f6-7c9949bd37ac.json?xl=1</a>
     *
     * @api {get} /api/concept/{id}.rdf Get concept detail
     * @apiName GetConcept
     * @apiGroup FindConcept
     * @apiParam {String} fl List of fields to return
     * @apiSuccess (200) {String} XML
     * @apiSuccessExample {String} Success-Response
     *   HTTP/1.1 200 Ok
     *   &lt;?xml version="1.0" encoding="utf-8" ?>
     *   &lt;rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"
     *           xmlns:skos="http://www.w3.org/2004/02/skos/core#"
     *           xmlns:dc="http://purl.org/dc/terms/"
     *           xmlns:dcterms="http://purl.org/dc/elements/1.1/"
     *           xmlns:openskos="http://openskos.org/xmlns#">
     *
     *   &lt;rdf:Description rdf:about="http://data.beeldengeluid.nl/gtaa/218059">
     *       &lt;rdf:type rdf:resource="http://www.w3.org/2004/02/skos/core#Concept"/>
     *       &lt;skos:historyNote xml:lang="nl">Recordnummer: 11665
     *   Datum invoer: 13-12-1998
     *   Gebruiker invoer: SEBASTIAAN
     *   Datum gewijzigd: 12-10-2004
     *   Gebruiker gewijzigd: Beng&lt;/skos:historyNote>
     *       &lt;skos:historyNote xml:lang="nl">Goedgekeurd door: Alma Wolthuis&lt;/skos:historyNote>
     *       &lt;skos:historyNote xml:lang="nl">Gewijzigd door: Alma Wolthuis&lt;/skos:historyNote>
     *       &lt;skos:broader rdf:resource="http://data.beeldengeluid.nl/gtaa/217190"/>
     *       &lt;skos:related rdf:resource="http://data.beeldengeluid.nl/gtaa/215665"/>
     *       &lt;skos:related rdf:resource="http://data.beeldengeluid.nl/gtaa/216387"/>
     *       &lt;skos:related rdf:resource="http://data.beeldengeluid.nl/gtaa/217572"/>
     *       &lt;dcterms:creator rdf:resource="http://openskos.org/users/9f598c22-1fd4-4113-9447-7c71d0c7146f"/>
     *       &lt;skos:broadMatch rdf:resource="http://data.beeldengeluid.nl/gtaa/24842"/>
     *       &lt;openskos:collection rdf:resource="http://openskos.org/api/collections/beg:gtaa"/>
     *       &lt;openskos:status>approved&lt;/openskos:status>
     *       &lt;skos:prefLabel xml:lang="nl">doodstraf&lt;/skos:prefLabel>
     *       &lt;skos:altLabel xml:lang="nl">kruisigingen&lt;/skos:altLabel>
     *       &lt;openskos:tenant>beg&lt;/openskos:tenant>
     *       &lt;dc:contributor>RVD, SFW, NFM, GWA, TVA&lt;/dc:contributor>
     *       &lt;skos:notation>218059&lt;/skos:notation>
     *       &lt;skos:inScheme rdf:resource="http://data.beeldengeluid.nl/gtaa/OnderwerpenBenG"/>
     *       &lt;dcterms:modified rdf:datatype="http://www.w3.org/2001/XMLSchema#dateTime">2009-11-30T17:30:51+00:00&lt;/dcterms:modified>
     *       &lt;dcterms:dateSubmitted rdf:datatype="http://www.w3.org/2001/XMLSchema#dateTime">2009-11-30T15:03:48+00:00&lt;/dcterms:dateSubmitted>
     *       &lt;dcterms:dateAccepted rdf:datatype="http://www.w3.org/2001/XMLSchema#dateTime">2009-11-30T15:03:48+00:00&lt;/ddcterms:dateAccepted>
     *       &lt;openskos:uuid>03ae64e0-94ba-55d8-c01a-6f4259e95177&lt;/openskos:uuid>
     *     &lt;/rdf:Description>
     *   &lt;/rdf:RDF>
     *
     */
    public function getAction()
    {
        $this->_helper->viewRenderer->setNoRender(true);
        $id = $this->getId();
        /* @var $apiConcept OpenSkos2\Api\Concept */
        $apiConcept = $this->getDI()->make('OpenSkos2\Api\Concept');
        $context = $this->_helper->contextSwitch()->getCurrentContext();
        $request = $this->getPsrRequest();

        // Exception for html use ZF 1 easier with linking in the view
        if ('html' === $context) {
            /* @var $concept \OpenSkos2\Concept */
            $concept = $apiConcept->getResource($id);
            $tenantCode = $concept->getTenant()->getValue();
            $manager = $this->getConceptManager();
            $tenant = $manager->fetchByUuid($tenantCode, \OpenSkos2\Institution::TYPE, 'openskos:code');
            $useXlLabels = $apiConcept->useXlLabels(
                $tenant, $request
            );
            if ($useXlLabels === true) {
                $concept->loadFullXlLabels($manager->getLabelManager());
            }

            $this->view->useXlLabels = $useXlLabels;
            $this->view->concept = $concept;
            return $this->renderScript('concept/get.phtml');
        }
        $response = $apiConcept->getResourceResponse($request, $id, $context);
        $this->emitResponse($response);
    }

    public function postAction()
    {
        if ($this->isFindConcept) {
            $this->_501('POST');
        } else {
            parent::postAction();
        }
    }

    public function putAction()
    {
        if ($this->isFindConcept) {
            $this->_501('POST');
        } else {
            parent::putAction();
        }
    }

    public function deleteAction()
    {
        if ($this->isFindConcept) {
            $this->_501('POST');
        } else {
            parent::deleteAction();
        }
    }

}
