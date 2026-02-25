<?php

declare(strict_types=1);

namespace App\Presentation\Register;

use Nette;
use Nette\Application\UI\Form;
use Nette\Application\UI\Presenter;
use Nette\Database\Explorer;
use Nette\Database\UniqueConstraintViolationException;
use Nette\Security\Passwords;


final class RegisterPresenter extends Presenter
{
	public function __construct(
		private Explorer $database,
		private Passwords $passwords,
	) {
	}


	public function actionDefault(): void
	{
		// Already logged-in users have no business here.
		if ($this->getUser()->isLoggedIn()) {
			$this->redirect('Home:default');
		}
	}


	protected function createComponentRegisterForm(): Form
	{
		$form = new Form;

		$form->addText('username', 'Uživatelské jméno:')
			->setRequired('Zadejte uživatelské jméno.')
			->setMaxLength(100);

		$form->addPassword('password', 'Heslo:')
			->setRequired('Zadejte heslo.')
			->addRule(Form::MinLength, 'Heslo musí mít alespoň %d znaků.', 8);

		$form->addPassword('passwordConfirm', 'Heslo znovu:')
			->setRequired('Potvrďte prosím heslo.')
			->addRule(Form::Equal, 'Hesla se neshodují.', $form['password']);

		$form->addSubmit('send', 'Registrovat');

		$form->onSuccess[] = [$this, 'registerFormSucceeded'];

		return $form;
	}


	public function registerFormSucceeded(Form $form, \stdClass $data): void
	{
		// Check for duplicate username before attempting insert.
		$exists = $this->database
			->table('users')
			->where('username', $data->username)
			->fetch();

		if ($exists !== null) {
			$form->addError('Uživatelské jméno je již obsazeno. Zvolte jiné.');
			return;
		}

		try {
			$this->database->table('users')->insert([
				'username'      => $data->username,
				'password_hash' => $this->passwords->hash($data->password),
				'role'          => 'user',
			]);
		} catch (UniqueConstraintViolationException) {
			// Race-condition safety net.
			$form->addError('Uživatelské jméno je již obsazeno. Zvolte jiné.');
			return;
		}

		$this->flashMessage('Registrace proběhla úspěšně. Nyní se můžete přihlásit.', 'success');
		$this->redirect('Sign:in');
	}
}
