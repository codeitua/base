<?php

declare(strict_types=1);

namespace CodeIT\Command;

use CodeIT\Form\CreateUserForm;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
class UserCreateCommand extends Command
{
    public static $defaultName = 'user: create';
    protected function configure() : void
    {
        $this->setName(self::$defaultName)->setDescription('Create a new user')->addArgument('email', InputArgument::REQUIRED, 'Email of the user.')->addArgument('password', InputArgument::REQUIRED, 'Password of the user.')->addArgument('level', InputArgument::OPTIONAL, 'Optional parameter.Default is admin.', 'admin');
    }
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        $form = new CreateUserForm();
        $data = ['email' => $input->getArgument('email'), 'password' => $input->getArgument('password'), 'level' => $input->getArgument('level') ?: 'admin'];
        $form->setData($data);
        if (!$form->isValid()) {
            $output->writeln('<error>' . trim($form->getMessages()) . '</error>');
            return Command::FAILURE;
        }
        try {
            $user = new \Application\Model\User();
            $user->setData($form->getData());
            $user->active = 1;
            $user->save();
        } catch (\Exception $ex) {
            $output->writeln('<error>A server error has occured: ' . $ex->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $output->writeln('<info>Successful creation.</info>');
        return Command::SUCCESS;
    }
}
