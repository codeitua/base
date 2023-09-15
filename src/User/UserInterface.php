<?php

namespace CodeIT\User;

interface UserInterface {
	
	/**
	 * user authorization
	 * $forceAuth shows if this application is strictly for authorized users
	 * 
	 * @param bool $forceAuth
	 * @throws \Exception
	 * @return bool
	 */
	public function auth($forceAuth = false);
	
	/**
	 * returns current user role
	 * 
	 * @return string
	 */
	public function getRole();
	
	/**
	 * verify user account activity
	 * if user is blocked - throw exception and don't log in user
	 * 
	 */
	public function isActive($user = null);
	
	/**
	 * method returns if some resorce is allowed to current user, using Acl
	 * 
	 * @param string $resource
	 * @param string $action
	 * @param string $role
	 * 
	 * @return bool
	 */
	public function isAllowed($resource = null, $action = null, $role = null);
	
	/** returns acl object from object storage or create new
	 * 
	 */
	public function getAcl();
	
	/**
	 * function makes all nesessary things on login
	 * 
	 * @param int $userId
	 */
	public function login($userId);
	
	/**
	 * function makes all nesessary things on logout
	 * 
	 * @param int $id
	 */
	public function logout($id = 0);
	
}