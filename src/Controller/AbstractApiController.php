<?php
namespace CodeIT\Controller;

use Application\Lib\User;
use CodeIT\Utils\Registry;
use Zend\EventManager\EventManagerInterface;
use Zend\Mvc\Controller\AbstractRestfulController;
use Zend\View\Model\ViewModel;
use Zend\View\Model\JsonModel;

abstract class AbstractApiController extends AbstractRestfulController {

	/**
	 * user device Id
	 * 
	 * @var string
	 */
	protected $deviceId = '';

	/**
	 * Currently logged in user
	 * 
	 * @var \Application\Lib\User
	 */
	protected $user;
	
    protected $acceptCriteria = [
//        'Application\View\Model\XMLModel' => [
//        	'application/xml',
//        ],
        'Zend\View\Model\JsonModel' => [
        	'application/json',
        	'text/html',
        ],
    ];

    public function ready() {
		$this->isAjax = true;
		$this->layout('mobile/layout');
		
		$origin = $this->request->getHeader('Origin');
		if ($origin) { // allow API usage from any hosts
			$this->getResponse()->getHeaders()->addHeaderLine(
				'Access-Control-Allow-Origin',
				$origin->getFieldValue()
			);
		}

		$requestHeaders = $this->request->getHeader('Access-Control-Request-Headers');
		if ($requestHeaders) { // allow any headers asked
			$this->getResponse()->getHeaders()->addHeaderLine(
				'Access-Control-Allow-Headers',
				$requestHeaders->getFieldValue()
			);
		}

		$this->getResponse()->getHeaders()->addHeaderLine( // allow any methods
			'Access-Control-Allow-Methods',
			'POST, GET, OPTIONS, PUT, DELETE, PATCH'
		);

		if (strtoupper($this->request->getMethod()) == 'OPTIONS') {
			return 'ok'; // we should return http 200 with empty body
		}
		
		try {
			$user = Registry::get('User');
		}
		catch(\Exception $e) {
			$user = new User();
			Registry::set('User', $user);
		}

		try {
			$user->auth(false);
		}
		catch (\Exception $e) {}

		$this->user = $user;
		$this->getLanguage();

		return false;
	}

	/**
	 * gets language from session or user settings
	 * 
	 */
	protected function getLanguage() {
		$lang = 1;
		try {
			$lang = Registry::get('lang');
		}
		catch(\Exception $e) {
			if (!empty($_SESSION['lang'])) {
				$lang = $_SESSION['lang'];
			}

			if (!empty($this->user->languageId)) {
				$lang = $this->user->languageId;
			}

			$this->setLanguage($lang);
		}

		return $lang;
	}

	/**
	 * write new language to session and to the database
	 * 
	 * @param int $languageId
	 */
	protected function setLanguage($languageId) {
		$_SESSION['lang'] = (int)$languageId;

		if ($this->user->id && (empty($this->user->languageId) || $this->user->languageId != $languageId)) {
			$this->user->set(['languageId' => $languageId], $this->user->id);
		}

		Registry::set('lang', $_SESSION['lang']);
	}

	protected function getRequestData() {
		$request = $this->getRequest();
		$contentType = $request->getHeader('Content-Type');
		if ($contentType && $contentType->getFieldValue() == 'application/json') {
			return json_decode($request->getContent(), true);
		}

		if ($request->isDelete()) {
			parse_str($request->getContent(), $requestValues);
			return $requestValues;
		}

		return array_merge_recursive($request->getPost()->toArray(), $request->getFiles()->toArray());
	}

	/**
	 * return json-encoded data responce
	 * 
	 * @param []|null $data - any data for js processor
	 * @return JsonModel 
	 * 
	 */
	public function returnData($data = null, $httpStatusCode=200) {
		$this->getResponse()->setStatusCode($httpStatusCode);
		$model = new JsonModel($data);
		//$model = $this->acceptableViewModelSelector($this->acceptCriteria);
		$model->setOption('prettyPrint', DEBUG);
		return $model;
	}

	/**
	 * send json error to client
	 * 
	 * @param string $text
	 * @param int $code
	 * @param int $httpStatusCode
	 * @return \Zend\View\Model\JsonModel
	 */
	public function returnError($text='', $code = false, $httpStatusCode=400) {
		return $this->returnData(['code' => $code, 'message' => $text], $httpStatusCode);
	}

	/**
	 * Send HTTP 401 "Authentication required" status code
	 */
	public function returnAuthenticationRequired() {
		return $this->returnData(['message' => 'Authentication required', 'code' => 0], 401);
	}

	/**
	 * Send HTTP 403 "Forbidden" status code
	 */
	public function forbiddenAction() {
		return $this->returnData(['message' => 'Action is forbidden for your role', 'code' => 0], 403);
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
			$params['method'] = $this->getRequest()->getMethod();

			$response = $controller->ready();

			$auth = new \Application\Lib\Authentication();
			if (!$response) {
				$response = $auth->preDispatch($params, $this, $this->isAjax, true);
			}

			if( $response !== false) {
				$e->stopPropagation(true);
				$jsonRenderer = new \Zend\View\Renderer\JsonRenderer();
				$this->getResponse()->setContent($jsonRenderer->render($response));
				return $this->getResponse();
			}
		});
		parent::setEventManager($eventManager);
	}

}
