<?php
namespace CodeIT\View\Helper;

use Zend\Form\ElementInterface;
use Zend\View\Helper\AbstractHelper;
use Zend\Form\View\Helper\FormElementErrors;

class WrappedElement extends AbstractHelper {

	public function __invoke(ElementInterface $element, $class = 'element') {
		$view = $this->getView();
		$type = $element->getAttribute('type');
		if (empty($type)) {
			$type = 'text';
		}
		$name = $element->getName();
		$id = $element->getAttribute('id');
		$ngIf = $element->getAttribute('data-ng-if');
		
		$name = (!empty($id)) ? $id : $name;

		$element->setAttribute('id', $name);

		if ($element instanceof \Zend\Form\Element\Captcha) {
			$type = 'captcha';
		} elseif ($element instanceof \Application\Lib\Form\HTML) {
			$helper = new \Application\View\Helper\FormHTML();
			$input = $helper($element);
		} elseif ($element instanceof \Zend\Form\Element\Button && $type == 'submit') {
			$input = $view->formButton($element, $element->getAttribute('label'));
		} else {
			$input = 'form' . ucfirst($type);
			$input = $view->$input($element);
		}
		$visible = !in_array($type, array('hidden'));

		$elementErrorsHelper = $this->getElementErrorsHelper();
		$errors = $elementErrorsHelper->render($element);
		if (!empty($errors)) {
			//$errors = "<div class='error'>$errors [{$element->getName()}]</div>";
			$errors = "<div class='error'>$errors</div>";
		}

		//$element->setAttribute('id', $element->getName());
		//translate labels
		$currLabel = $element->getLabel();
		if ($currLabel)
			$element->setLabel($currLabel);

		$label = '';
		try {
			$label = $view->formLabel($element);
		} catch (\Exception $e) {

		}
		switch ($type) {
			
			case 'captcha':
				$helper = new \Application\Lib\Form\View\Helper\Captcha\InOutputImage();
				$input = $helper->render($element);
				$elementHTML = "<div>$input $label</div>";
				break;
			case 'checkbox':
			case 'radio':
				$elementHTML = "<div>$input $label</div>";
				break;
			case 'button':
				$elementHTML = "<div class='el'>$input</div>";
				break;
			//case 'html': $elementHTML = "<div>$input $label</div>"; break;
			default: $elementHTML = (empty($label) ? '' : "<div class='label'>$label:</div>") . "<div class='el'>$input</div>";
		}

		return "<div class='$type $name " . $view->escapeHTML($class) . " "  . ($errors ? "highlited" : "") . "'".(!empty($ngIf)?' ng-if="'.$ngIf.'"':'').">
				$elementHTML
				$errors
			</div>";
	}

	protected function getElementErrorsHelper() {
		if (isset($this->elementErrorsHelper) && $this->elementErrorsHelper) {
			return $this->elementErrorsHelper;
		}

		if (method_exists($this->view, 'plugin')) {
			$this->elementErrorsHelper = $this->view->plugin('form_element_errors');
		}

		if (!$this->elementErrorsHelper instanceof FormElementErrors) {
			$this->elementErrorsHelper = new FormElementErrors();
		}

		return $this->elementErrorsHelper;
	}

}
