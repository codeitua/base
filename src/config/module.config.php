<?php
namespace CodeIT;

return [
	'console' => [
		'router' => [
			'routes' => [
				'usercreate' => [
					'options' => [
						'route'    => 'user create <email> <password> [<level>]',
						'defaults' => [
							'controller' => 'CodeIT\Controller\Core',
							'action'     => 'createUser'
						],
					],
				],
			],
		],
	],
	'controllers' => [
		'factories' => [
			'CodeIT\Controller\Core' => function(\Zend\ServiceManager\ServiceManager $serviceManager) {
				return new Controller\CoreController($serviceManager->get('console'));
			},
		],
	],
	'view_helpers' => [
		'factories' => [
			'appviewalias' => function(\Zend\ServiceManager\ServiceManager $serviceManager) {
				$application = $serviceManager->get('Application');
				$route = $application->getMvcEvent()->getRouteMatch();
				return new \CodeIT\View\Helper\AppViewHelper($route);
			},
		],
		'invokables' => [
			'wrappedElement' => 'CodeIT\View\Helper\WrappedElement',
			'wrappedForm' => 'CodeIT\View\Helper\WrappedForm',
		],
	],
];
