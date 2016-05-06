<?php
namespace CodeIT\Validator;

use CodeIT\Utils\Strings;

class CsrfValidator extends \Zend\Validator\AbstractValidator {

	const NOT_SAME = 'notSame';

	protected $messageTemplates = [
		self::NOT_SAME => 'The form submitted did not originate from the expected site',
	];

	public function isValid($value) {
		$token = self::getCsrfToken();

		if ($value !== $token) {
			$this->error(self::NOT_SAME);
			return false;
		}

		return true;
	}

	/**
	 * Returns array of validation failure messages
	 *
	 * @return array
	 */
	public function getMessages() {
		$result = parent::getMessages();
		if (!empty($result)) {
			$result = [
				self::NOT_SAME => _('The form submitted did not originate from the expected site'),
			];
		}
		return $result;
	}

	static function getCsrfToken() {
		if(isset($_SESSION['custom_csrf']) && $_SESSION['custom_csrf']) {
			$value = $_SESSION['custom_csrf'];
		}
		else {
			$value = Strings::generatePassword(32);
			$_SESSION['custom_csrf'] = $value;
		}

		return $value;
	}
}
