<?php

namespace CodeIT\ACL;

use Application\Lib\User;
use CodeIT\Utils\Registry;
use User\Controller\Plugin\UserAuthentication as AuthPlugin;
use Laminas\EventManager\StaticEventManager;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\Mvc\MvcEvent;

/**
 * Authentication Event Handler Class
 *
 * This Event Handles Authentication
 */
class Authentication
{

    /**
     * @var \Laminas\Cache\Pattern\ObjectCache
     */
    protected $cachedAcl = null;

    /**
     * preDispatch Event Handler
     *
     * @param array $params
     * @param AbstractController $controller
     * @param bool $returnForbidden
     * @returns string|bool false if no respose given, run further,
     *          true if no response, abort execution
     *          string in case we need to push it to client
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
        if (!$acl->call('hasResource', array($params['controller']))) {
            throw new \Exception('Resource ' . $params['controller'] . ' not defined');
        }

        $action = isset($params['action']) ? $params['action'] : $params['method'];
        if (!$acl->call('isAllowed', array($role, $params['controller'], $action))) {
            if (in_array($role, ['guest']) && !$returnForbidden) {
                if (is_a($controller, 'Code\Lib\ApiController')) {
                    return $controller->returnAuthenticationRequired();
                } else {
                    $controller->getResponse()->getHeaders()->addHeaderLine(
                        'Location',
                        URL . 'auth?r=' . urlencode($_SERVER['REQUEST_URI'])
                    );
                    $controller->getResponse()->setStatusCode(302);
                    return true; // no content, abort execution, redirect
                }
            } else {
                $controller->forbiddenAction();
            }
        }

        return false; // no re
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
     * @param \User\Acl\Acl $aclClass
     * @return Authentication
     */
    public function setAclClass(AclClass $aclClass)
    {
        $this->_aclClass = $aclClass;

        return $this;
    }

    /**
     * Gets ACL Class
     *
     * @return \Application\Lib\Acl
     */
    public function getAclClass()
    {
        if ($this->cachedAcl === null) {
            $cachedAcl = \Laminas\Cache\PatternFactory::factory('object', array(
                'object'   => new \Application\Lib\Acl(),
                'storage' => 'redis',
                'object_key' => '.objectCache.\CodeIT\ACL',
                'cache_by_default' => false,

                // the output don't need to be catched and cached
                'cache_output' => false,
            ));

            $this->cachedAcl = $cachedAcl;
        }

        return $this->cachedAcl;
    }
}
