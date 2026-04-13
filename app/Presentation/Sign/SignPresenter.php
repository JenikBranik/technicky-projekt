<?php

declare(strict_types=1);

namespace App\Presentation\Sign;

use Nette;
use Nette\Application\UI\Form;
use Nette\Application\UI\Presenter;
use Nette\Security\AuthenticationException;


final class SignPresenter extends Presenter
{
	public function actionIn(): void
	{
		if ($this->getUser()->isLoggedIn()) {
			$this->redirect('Home:default');
		}
	}


	public function actionOut(): void
	{
		$this->getUser()->logout(clearIdentity: true);
		$this->flashMessage('Byl jsi úspěšně odhlášen.', 'success');
		$this->redirect('Sign:in');
	}


	protected function createComponentSignInForm(): Form
	{
		$form = new Form;

		$form->addText('username', 'Uživatelské jméno:')
			->setRequired('Zadejte uživatelské jméno.');

		$form->addPassword('password', 'Heslo:')
			->setRequired('Zadejte heslo.');

		$form->addSubmit('send', 'Přihlásit se');

		$form->onSuccess[] = [$this, 'signInFormSucceeded'];

		return $form;
	}


	public function signInFormSucceeded(Form $form, \stdClass $data): void
	{
		try {
			$user = $this->getUser();
			$user->setExpiration('20 minutes', true);
			$user->login($data->username, $data->password);
			$this->redirect('Home:default');

		} catch (AuthenticationException $e) {
			$form->addError('Neplatné přihlašovací údaje.');
		}
	}
}
