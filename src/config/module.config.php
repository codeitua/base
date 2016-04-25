<?php
namespace CodeIT;

return array(
	'console' => array(
		'router' => array(
			'routes' => array(
				'usercreate' => array(
					'options' => array(
						'route'    => 'user create <email> <password> [<level>]',
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
