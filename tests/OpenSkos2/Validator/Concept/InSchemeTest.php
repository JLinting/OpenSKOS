<?php
/**
 * Created by PhpStorm.
 * User: jsmit
 * Date: 27/08/2015
 * Time: 11:02
 */

namespace OpenSkos2\Validator\Concept;

use OpenSkos2\Concept;
use OpenSkos2\Namespaces\Skos;
use OpenSkos2\Rdf\Uri;
use PHPUnit\Framework\TestCase;

class InSchemeTest extends TestCase
{


    public function testValidate()
    {

        $conceptManagerMock = $this->getMockBuilder('\OpenSkos2\ConceptManager')
            ->disableOriginalConstructor()
            ->getMock();

        $validator = new InScheme();
        $validator->setResourceManager($conceptManagerMock);

        $concept = new Concept('http://example.com#1');

        //no scheme
        $this->assertFalse($validator->validate($concept));

        $concept->addProperty(SKOS::INSCHEME, new Uri('http://example.com#scheme1'));

        $res = $validator->validate($concept);
        //1 scheme
        $this->assertTrue($validator->validate($concept));

        $concept->addProperty(SKOS::INSCHEME, new Uri('http://example.com#scheme2'));

        //2 schemes
        $this->assertTrue($validator->validate($concept));
    }
}
