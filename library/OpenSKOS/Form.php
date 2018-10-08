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
class OpenSKOS_Form extends Zend_Form
{
    /**
     * Factory method for extended zend elements. (OpenSKOS_Form_Element_*)
     *
     * @param array $elementData
     * @param string $elementClass
     * @param array $cssClasses
     * @param string $groupName
     * @param string $wrapperId
     * @return Editor_Forms_Concept
     */
    protected function buildMultiElements(
        array $elementData,
        $elementClass,
        $cssClasses = [],
        $groupName = null,
        $wrapperId = null
    ) {
        foreach ($elementData as $elementName => $elementOptions) {
            if (is_array($elementOptions)) {
                if (!isset($elementOptions['label'])) {
                    throw new \Exception('Item "label" is required in element config.', 500 );
                }
                $elementLabel = $elementOptions['label'];
            } else {
                $elementLabel = $elementOptions;
            }
            
            $element = new $elementClass($elementName, $elementLabel);
            $element->setCssClasses(array_merge($cssClasses, array($elementName)));
            
            if (is_array($elementOptions) && isset($elementOptions['readonly'])) {
                $element->setReadonly($elementOptions['readonly']);
            }
            
            $this->addElement($element);
        }
        if (null !== $wrapperId) {
            if ($this->getDisplayGroup($wrapperId) !== null) {
                $this->getDisplayGroup($wrapperId)->addElement($element);
            } else {
                $this->addDisplayGroup(
                    array_keys($elementData),
                    $wrapperId,
                    [
                        'legend' => $groupName,
                        'disableDefaultDecorators' => true,
                        'decorators' => ['Fieldset', 'FormElements', ['HtmlTag', ['tag' => 'div', 'id' => $wrapperId]]]
                    ]
                );
            }
        }
        return $this;
    }
    
    /**
     * Get dependency injection container
     * @return \DI\Container
     */
    protected function getDI()
    {
        return Zend_Controller_Front::getInstance()->getDispatcher()->getContainer();
    }
}
