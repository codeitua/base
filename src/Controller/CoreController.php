<?php
namespace CodeIT\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\Console\Request as ConsoleRequest;
use Zend\Console\ColorInterface as Color;

class CoreController extends AbstractActionController {

	/**
	 * Method creates new user in the database.
	 * 
	 */
	public function createUserAction() {
		$request = $this->getRequest();

		if (!$request instanceof ConsoleRequest) {
			throw new \RuntimeException('You can only use this action from a console!');
		}

		$console = $this->getServiceLocator()->get('console');
		$userTable = new \Application\Model\UserTable();
		$form = new \CodeIT\Form\CreateUserForm();

		$data = [
			'email' => $request->getParam('email'),
			'password' => $request->getParam('password'),
			'level' => !empty($request->getParam('level')) ? $request->getParam('level') : 'admin',
		];

		$form->setData($data);
		if ($form->isValid()) {
			$data = $form->getData();
			$data['created'] = time();
			$data['updated'] = $data['created'];
			try {
				$userTable->insert($data);
			} catch (\Exception $ex) {
				$error = 'A server error has occured: '. $ex->getMessage() ."\n";
			}
		} else {
			$error = $form->getMessages();
		}

		if (isset($error)) {
			$console->write($error, Color::RED);
		} else {
			$console->write("Successful creation.\n", Color::GREEN);
		}
	}
}
