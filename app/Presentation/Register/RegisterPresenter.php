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
		private Nette\Mail\Mailer $mailer,
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

		$form->addEmail('email', 'E-mail:')
			->setRequired('Zadejte e-mail.')
			->setMaxLength(255);

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
				'email'         => $data->email,
				'password_hash' => $this->passwords->hash($data->password),
				'role'          => 'user',
			]);
		} catch (UniqueConstraintViolationException) {
			// Race-condition safety net.
			$form->addError('Uživatelské jméno je již obsazeno. Zvolte jiné.');
			return;
		}

		// Odeslání uvítacího e-mailu
		$mail = new Nette\Mail\Message;
		$mail->setFrom('testovaciwebreport@seznam.cz')
				->addTo($data->email)
				->setSubject('Vítejte na Školním portálu')
				->setBody("Dobrý den,\n\nděkujeme za registraci na Školním portálu.\nVaše uživatelské jméno je: {$data->username}\n\nS pozdravem\nTým Školního portálu");
			try {
			$this->mailer->send($mail);
		} catch (\Exception $e) {
		}

		$this->flashMessage('Registrace proběhla úspěšně. Nyní se můžete přihlásit.', 'success');
		$this->redirect('Sign:in');
	}
}
