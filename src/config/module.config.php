<?php
namespace CodeIT;

return array(
	'console' => array(
		'router' => array(
			'routes' => array(
				'getproduct' => array(
					'options' => array(
						'route'    => 'user create <email> <password> [admin|registered]',
						'defaults' => array(
							'controller' => 'CodeIT\Controller\Core',
							'action'     => 'createUser'
						)
					)
				)
			)
		)
	),
	'controllers' => array(
		'invokables' => array(
			'CodeIT\Controller\Core' => 'CodeIT\Controller\CoreController',
		),
	),
);
