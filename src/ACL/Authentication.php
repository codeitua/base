<?php

declare(strict_types=1);

namespace CodeIT\ACL;

use Application\Lib\User;
use CodeIT\Utils\Registry;
use Laminas\Mvc\Controller\AbstractController;
use User\Controller\Plugin\UserAuthentication as AuthPlugin;
/**
* Authentication Event Handler Class
*
* This Event Handles Authentication
*/
class Authentication
{
    /**
     * @var \Application\Lib\Acl|object|null
     */
    protected $cachedAcl = null;
    protected $_userAuth = null;
    protected $_aclClass = null;
    /**
     * preDispatch Event Handler
     *
     * @param array $params
     * @param AbstractController $controller
     * @param bool $returnForbidden
     * @returns string|bool false if no response given, run further,
     * true if no response, abort execution
     * string in case we need to push it to client
     * @throws \Exception
     */
    public function preDispatch($params, AbstractController $controller, $ajax = false, $returnForbidden = false)
    {
        /**
         * @var User
         */
        $user = Registry::get('User');
        $acl = $this->getAclClass();
        $role = $user->getRole();
        if (!$acl->call('hasResource', [$params['controller']])) {
            throw new \Exception('Resource ' . $params['controller'] . ' not defined');
        }
        if (!$acl->call('isAllowed', [$role, $params['controller'], isset($params['action']) ? $params['action'] : $params['method']])) {
            if (in_array($role, ['guest']) && !$returnForbidden) {
                if (is_a($controller, \CodeIT\Controller\AbstractApiController::class) || method_exists($controller, 'returnAuthenticationRequired')) {
                    return $controller->returnAuthenticationRequired();
                }
                $controller->getResponse()->getHeaders()->addHeaderLine('Location', URL . 'auth?r=' . urlencode($_SERVER['REQUEST_URI']));
                $controller->getResponse()->setStatusCode(302);
                return true;
            }
            $controller->forbiddenAction();
        }
        return false;
    }
    /**
     * Sets Authentication Plugin
     *
     * @param \User\Controller\Plugin\UserAuthentication $userAuthenticationPlugin
     * @return Authentication
     */
    public function setUserAuthenticationPlugin(AuthPlugin $userAuthenticationPlugin)
    {
        $this->_userAuth = $userAuthenticationPlugin;
        return $this;
    }
    /**
     * Gets Authentication Plugin
     *
     * @return \User\Controller\Plugin\UserAuthentication
     */
    public function getUserAuthenticationPlugin()
    {
        if ($this->_userAuth === null) {
            $this->_userAuth = new AuthPlugin();
        }
        return $this->_userAuth;
    }
    /**
     * Sets ACL Class
     *
     * @param object $aclClass
     * @return Authentication
     */
    public function setAclClass($aclClass)
    {
        $this->_aclClass = $aclClass;
        $this->cachedAcl = null;
        return $this;
    }
    /**
     * Gets ACL Class
     *
     * @return \Application\Lib\Acl|object
     */
    public function getAclClass()
    {
        if ($this->_aclClass !== null) {
            return $this->_aclClass;
        }
        if ($this->cachedAcl === null) {
            $this->cachedAcl = new \Application\Lib\Acl();
        }
        return $this->cachedAcl;
    }
}
