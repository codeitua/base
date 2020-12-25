<?php

namespace CodeIT\Form;

use Laminas\InputFilter\InputFilter;
use Laminas\InputFilter\Factory as InputFactory;

abstract class Form extends \Laminas\Form\Form
{
    
    protected $langs = [];
    
    /**
     * last update timestamp
     *
     * @var int
     */
    protected $updated;

    abstract protected function getInpFilter();

    /**
     * Set data to validate and/or populate elements
     *
     * Typically, also passes data on to the composed input filter.
     *
     * @param  array|\ArrayAccess|Traversable $data
     * @return Form|FormInterface
     * @throws Exception\InvalidArgumentException
     */
    public function setData($data)
    {
        parent::setData($data);
        $this->setInputFilter($this->getInpFilter());
        return $this;
    }
    
    protected function setLangs()
    {
        $langTable = new \Application\Model\LangTable();
        $list = $langTable->find([]);
        foreach ($list as $item) {
            $this->langs[] = $item['id'];
        }
    }
    
    /**
     * set value for updated field
     *
     * @param int $updated
     */
    public function setUpdated($updated)
    {
        $this->updated = $updated;
    }

    /**
     * returns updated value for form content
     *
     */
    public function getUpdated()
    {
        return $this->updated;
    }

    /**
     * Add an element or fieldset
     *
     * If $elementOrFieldset is an array or Traversable, passes the argument on
     * to the composed factory to create the object before attaching it.
     *
     * $flags could contain metadata such as the alias under which to register
     * the element or fieldset, order in which to prioritize it, etc.
     *
     * @param  array|Traversable|ElementInterface $elementOrFieldset
     * @param  array                              $flags
     * @return self
     */
    public function add($elementOrFieldset, array $flags = [])
    {
        if (is_array($elementOrFieldset) && !empty($elementOrFieldset['type'])) {
            if ($elementOrFieldset['type'] == 'csrf') {
                if (
                    empty($elementOrFieldset['options']) ||
                    empty($elementOrFieldset['options']['csrf_options']) ||
                    !isset($elementOrFieldset['options']['csrf_options']['timeout'])
                ) {
                    $elementOrFieldset['options']['csrf_options']['timeout'] = null;
                }
            }
        }

        return parent::add($elementOrFieldset, $flags);
    }
}
