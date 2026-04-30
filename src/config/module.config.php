<?php

declare(strict_types=1);

namespace CodeIT;

use Laminas\Mvc\Controller\LazyControllerAbstractFactory;
use Psr\Container\ContainerInterface;
return ['laminas-cli' => ['commands' => ['user: create' => Command\UserCreateCommand::class]], 'service_manager' => ['factories' => [Command\UserCreateCommand::class => static fn() => new Command\UserCreateCommand()]], 'controllers' => ['abstract_factories' => [LazyControllerAbstractFactory::class]], 'view_helpers' => ['factories' => ['appviewalias' => static function (ContainerInterface $container) {
    $application = $container->get('Application');
    $event = $application->getMvcEvent();
    return new View\Helper\AppViewHelper($event->getRouteMatch(), $event->getRequest());
}, View\Helper\WrappedElement::class => static fn() => new View\Helper\WrappedElement(), View\Helper\WrappedForm::class => static fn() => new View\Helper\WrappedForm()], 'aliases' => ['wrappedElement' => View\Helper\WrappedElement::class, 'wrappedForm' => View\Helper\WrappedForm::class]]];
