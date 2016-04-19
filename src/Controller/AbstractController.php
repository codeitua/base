<?php
namespace CodeIT\Controller;

use Application\Lib\User;
use CodeIT\ACL\Authentication;
use CodeIT\Utils\Registry;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\EventManager\EventManagerInterface;
use Zend\View\Model\ViewModel;

abstract class AbstractController extends AbstractActionController {
	protected $userId;
	public $lang = 1;
	public $breadcrumbs;
	public $error = '';
	public $isAjax = false;

	/**
	 * return forbidden when action is not available for user or redirect to login if false
	 * 
	 * @var bool
	 */
	public $returnForbidden = false;

	protected $forceAuth;

	/**
	 * User class
	 *
	 * @var User
	 */
	protected $user;

	/**
	 * Construct default controller, create lang table
	 * 
	 * @param mixed $forceAuth
	 * @return AppController
	 */
	public function __construct($forceAuth = false) {
		$this->forceAuth = $forceAuth;
	}

	public function ready() {
		try {
			$user = Registry::get('User');
		}
		catch(\Exception $e) {
			$user = new User();
			$user->auth($this->forceAuth);
			Registry::set('User', $user);
		}

		$this->user = $user;
		$this->userId = $user->getId();
	}

	/**
	 * method sets breadcrumbs;
	 * add link to site home page by default
	 * 
	 * @param array $data
	 * @param bool $isAdmin;
	 */
	protected function setBreadcrumbs($data = array(), $isAdmin = false){	 
		$this->breadcrumbs = array(URL => SITE_NAME);
		$baseUrl = URL.($isAdmin ? 'admin/' : '');

		if($isAdmin)
			$this->breadcrumbs[$baseUrl] = _('Administration Panel');

		foreach($data as $url => $name)
			$this->breadcrumbs[$baseUrl.$url] = $name;

		$this->layout()->breadcrumbs = $this->breadcrumbs; 
	}

	/**
	 * Inject an EventManager instance
	 *
	 * @param  EventManagerInterface $eventManager
	 * @return void
	 */
	public function setEventManager(EventManagerInterface $eventManager) {
		$controller = $this;
		$eventManager->attach('dispatch', function ($e) use ($controller) {
			$matches = $e->getRouteMatch();
			$params = $matches->getParams();

			$controller->ready();

			$auth = new Authentication();
			if($response = $auth->preDispatch($params, $this, $this->isAjax, $this->returnForbidden)) {
				$e->stopPropagation(true);
				$jsonRenderer = new \Zend\View\Renderer\JsonRenderer();
				$this->getResponse()->setContent($jsonRenderer->render($response));
				return $this->getResponse();
			}
		});
		parent::setEventManager($eventManager);
	}

	public function basePath($path='') {
		return URL.$path;
	}

	public function getParam($param, $default=null) {
		return $this->getEvent()->getRouteMatch()->getParam($param, $default);
	}

	/**
	 * returnes error view template with current message
	 *
	 * @param str $mess
	 * @return \Zend\View\Model\ViewModel
	 */
	public function returnError($mess){
		$view = new \Zend\View\Model\ViewModel(array('err' => $mess));
		$view->setTemplate('error/index');
		return $view;
	}

	/**
	 * Action called if matched action is forbidden
	 *
	 * @return array
	 */
	public function forbiddenAction($message=false) {
		$response   = $this->getResponse();

		$event      = $this->getEvent();
		$routeMatch = $event->getRouteMatch();
		$routeMatch->setParam('action', 'forbidden');

		$response->setStatusCode(403);
		if(!$message) {
			$message = _('This action is forbidden for your role');
		}
		if ($this->isAjax)
			return $this->sendJSONError($message, 403);
		else {
			$view = new ViewModel(array(
		    'error' => _('This action is forbidden for your role'),
		  ));
		  $view->setTemplate('application/index/forbidden.phtml');
			return $view;
		}
	}

	/**
	 * Action called if matched action is 
	 *
	 * @return array
	 */
	public function siteClosedAction() {
		$response   = $this->getResponse();

		$event      = $this->getEvent();
		$routeMatch = $event->getRouteMatch();
		$routeMatch->setParam('action', 'siteClosed');

		$response->setStatusCode(403);
		$view = new ViewModel();
		$view->setTemplate('application/index/siteClosed.phtml');
		return $view;
	}

	protected function renderView($view) {
		$viewRender = $this->getServiceLocator()->get('ViewRenderer');
		return $viewRender->render($view);
	}

	/**
	 * render html template into layout with $layoutVariable name
	 * 
	 * @param string $layoutVariable
	 * @param string $viewTemplate
	 * @param array $viewData
	 */
	public function renderHtmlIntoLayout($layoutVariable, $viewTemplate, $viewData = array()) {
		$controls = new ViewModel($viewData);
		$controls->setTemplate($viewTemplate);
		$this->layout()->addChild($controls, $layoutVariable);
	}

	/**
	 * converts zend form messages array to list
	 * 
	 * @param array $errors
	 */
	protected function simplifyFormErrors($errors){
		foreach($errors as $key => $item){
			$errors[$key] = current($item);
		}
		return $errors;
	}

	/**
	 * return standard json responce (use it for all ajax actions) and try to disconnect client, then continue running
	 * 
	 * @param mixed $data - any data for js processor
	 * @param string $view - ViewModel object or string to be placed on frontend
	 * @param string $action - (values: none, redirect, alert, content, error)
	 * @param string $status - (values: succes, error)
	 * @param boolean $exit - echo data and die
	 */
	protected function sendJSONResponseDisconnect($data = [], $view = false, $action = 'content', $status = 'success') {
		session_write_close();
		ignore_user_abort(true);
		set_time_limit(0);
		ob_end_clean(); 
		ob_start();
		echo $this->renderView($this->sendJSONResponse($data, $view, $action, $status));
		$size = ob_get_length();
		header("Content-Length: $size");
		ob_end_flush(); 
		flush();
	}
	
}
