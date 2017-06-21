<?php
namespace CodeIT\View\Helper;

use Zend\Router\RouteMatch;
use Zend\Http\Request;
use Zend\View\Helper\AbstractHelper;

class AppViewHelper extends AbstractHelper
{
    /**
     * RouteMatch object
     *
     * @var RouteMatch
     */
    protected $route;

    /**
     * HTTP Request objext
     *
     * @var Request
     */
    protected $request;

    public function __construct(RouteMatch $route = null, Request $request = null) {
        $this->route = $route;
        $this->request = $request;
    }

    public function prepareControllerAction() {
        if (!$this->route) {
            return;
        }

        $controller = $this->route->getParam('controller', 'index');
        $action = $this->route->getParam('action', 'index');
        $lang = $this->route->getParam('lang', 'en');
        $namespace = $this->route->getParam('__NAMESPACE__', '');
        preg_match('#(\w+)$#', $controller, $mC);
        preg_match('#(\w+)$#', $action, $mA);
        $this->view->controller = $mC[1];
        $this->view->action = empty($mA[1]) ? '' : $mA[1];
        $this->view->namespace = $namespace;
        $this->view->current_lang = $lang;
        $this->view->id = $this->route->getParam('id', null);
    }

    /**
     * Return the parameter container responsible for query parameters or a single query parameter
     *
     * @param string|null           $name            Parameter name to retrieve, or null to get the whole container.
     * @param mixed|null            $default         Default value to use when the parameter is missing.
     * @return \Zend\Stdlib\ParametersInterface|mixed
     */
    public function getQuery($name = null, $default = null)
    {
        if ($this->request) {
            return $this->request->getQuery($name, $default);
        }

        return new \ArrayObject([]);
    }
}
