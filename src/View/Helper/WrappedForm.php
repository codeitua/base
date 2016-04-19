<?php
namespace CodeIT\View\Helper;

use Zend\View\Helper\AbstractHelper;

class WrappedForm extends AbstractHelper {

	protected $view;

	public function __invoke(\Zend\Form\Form $form, $err = null) {
		$this->view = $this->getView();

		$iterator = $form->getIterator();
		$form->prepare();

		$html = $this->view->form()->openTag($form);
		$html .= '<div class="zendForm">';
		$html .= $this->parseIterator($iterator);
		$html .= '</div>';
		$html .= $this->view->form()->closeTag($form);

		return $html;
	}

	private function parseIterator($iterator) {
		$html = "";
		foreach($iterator as $element) {
			if ($element instanceof \Zend\Form\Fieldset) {
				$legend = $element->getLabel();
				$html .= '<fieldset id="' . $element->getName() . '">';
				if (!empty($legend)) {
					$html .= '<legend>' . $legend . '</legend>';
				}
				$html .= $this->parseIterator($element->getIterator());
				$html .= '</fieldset>';
			} else {
				$html .= $this->view->wrappedElement($element);
			}
		}
		return $html;
	}

}
