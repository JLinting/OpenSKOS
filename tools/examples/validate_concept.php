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

namespace examples;

require dirname(__FILE__) . '/../autoload.inc.php';

use OpenSkos2\Namespaces\Skos;

$opts = array(
    'env|e=s' => 'The environment to use (defaults to "production")',
    'endpoint=s' => 'Solr endpoint to fetch data from',
    'tenant=s' => 'Institution to migrate',
);

try {
    $OPTS = new \Zend_Console_Getopt($opts);
} catch (\Zend_Console_Getopt_Exception $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    echo str_replace('[ options ]', '[ options ] action', $OPTS->getUsageMessage());
    exit(1);
}

require dirname(__FILE__) . '/../bootstrap.inc.php';

/* @var $diContainer DI\Container */
$diContainer = \Zend_Controller_Front::getInstance()->getDispatcher()->getContainer();

/* @var $resourceManager \OpenSkos2\Rdf\ResourceManager */
$resourceManager = $diContainer->get('OpenSkos2\Rdf\ResourceManager');

$logger = new \Monolog\Logger("Logger");
$logger->pushHandler(new \Monolog\Handler\ErrorLogHandler());

$concept = new \OpenSkos2\Concept('http://example.com/1');

$prefLabel = new \OpenSkos2\Rdf\Literal('Test');
$concept->addProperty(Skos::PREFLABEL, $prefLabel);
$scheme = new \OpenSkos2\Rdf\Uri('http://example.com/1');
$concept->addProperty(Skos::INSCHEME, $scheme);

$tenant = new \OpenSkos2\Institution('rce');
$validator = new \OpenSkos2\Validator\Concept\UniquePreflabelInScheme();
$validator->setResourceManager($resourceManager);
$isValid = $validator->validate($concept);
var_dump($isValid);
