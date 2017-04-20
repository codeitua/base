<?php

namespace CodeIT\Controller\Plugin;

use Zend\Mvc\Controller\Plugin\AbstractPlugin;
use Zend\View\Model\ViewModel;

/**
 * Class Background
 * @package Application\Lib\Controller\Plugin
 *
 * Add as controller plugin into module config:
 * 'controller_plugins' => [
 *     'invokables' => [
 *         'background' => 'CodeIT\Controller\Plugin\Background',
 *     ],
 * ],
 *
 * Usage examples:
 * 1.
 * $this->background()->sendJSONResponse([], false, 'content', 'success', function() use ($var) {
 *     // Do something
 *     // $this is the link to the controller instance
 * });
 *
 * 2.
 * $this->background()->sendJSONResponse([], false, 'content', 'success', function() {
 *     // Do something
 *     // $this is the link to the controller instance
 * });
 *
 * 3.
 * $this->background()->sendJSONResponse([], false, 'content', 'success', function($param1, $param2) {
 *     // Do something
 *     // $this is the link to the controller instance
 * }, [$var1, $var2]);
 *
 * 4.
 * $this->background()->sendJSONResponse([], false, 'content', 'success', [$model, 'method'], [$var]);
 *
 * 5.
 * $this->background()->sendJSONResponse([], false, 'content', 'success', [$model, 'method']);
 *
 */
class Background extends AbstractPlugin
{

	protected function before() {
		session_write_close();
		ignore_user_abort(true);
		set_time_limit(0);
	}

	/**
	 * @param callable $callback
	 * @param array $params
	 */
	protected function after(callable $callback, array $params = []) {
		flush();
		if (function_exists('fastcgi_finish_request')) {
			fastcgi_finish_request();
		}

		call_user_func_array($callback, $params);
		exit;
	}

	/**
	 * @param string $redirectUrl
	 * @param callable $callback
	 * @param array $params
	 */
	public function sendRedirect($redirectUrl, callable $callback, array $params = []) {
		$this->before();

		header("Location: $redirectUrl", true);
		header('Connection: close', true);
		header('Content-Encoding: none\r\n');
		header('Content-Length: 0', true);
		ob_flush();

		$this->after($callback, $params);
	}

	/**
	 * @param ViewModel $view
	 * @param callable $callback
	 * @param array $params
	 */
	public function send(ViewModel $view, callable $callback, array $params = []) {
		$this->before();

		ob_end_clean();
		ob_start();
		$renderer = $this->getController()->getServiceLocator()->get('Zend\View\Renderer\RendererInterface');
		$this->getController()->layout()->setVariable('content', $renderer->render($view));
		echo $renderer->render($this->getController()->layout());
		$size = ob_get_length();
		header("Content-Length: $size");
		ob_end_flush();

		$this->after($callback, $params);
	}

	/**
	 * Returns standard json response (use it for all ajax actions) and try to disconnect client, then continue running
	 *
	 * @param mixed $data - any data for js processor
	 * @param ViewModel|false $view - ViewModel object or string to be placed on frontend
	 * @param string $action - (values: none, redirect, alert, content, error)
	 * @param string $status - (values: succes, error)
	 * @param callable $callback
	 * @param array $params
	 */
	public function sendJSONResponse($data = [], $view = false, $action = 'content', $status = 'success', callable $callback, array $params = []) {
		$this->before();

		ob_end_clean();
		ob_start();
		echo $this->getController()
			->getServiceLocator()
			->get('ViewRenderer')
			->render($this->getController()->sendJSONResponse($data, $view, $action, $status));
		$size = ob_get_length();
		header("Content-Length: $size");
		ob_end_flush();

		$this->after($callback, $params);
	}
}
