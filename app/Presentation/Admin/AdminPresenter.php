<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Model\UserManager;
use Nette;
use Nette\Application\UI\Presenter;

final class AdminPresenter extends Presenter
{
	public function __construct(
		private UserManager $userManager,
	) {
	}


	protected function startup(): void
	{
		parent::startup();

		if (!$this->getUser()->isLoggedIn()) {
			$this->redirect('Sign:in');
		}

		if (!$this->getUser()->isInRole('admin')) {
			$this->flashMessage('Přístup odmítnut. Tato sekce je pouze pro administrátory.', 'danger');
			$this->redirect('Home:default');
		}
	}


	/** @persistent */
	public string $search = '';

	public function renderDefault(): void
	{
		$this->template->users = $this->userManager->getAllUsers($this->search !== '' ? $this->search : null);
		$this->template->assignableRoles = UserManager::ASSIGNABLE_ROLES;
	}


	/**
	 * Změní roli uživatele (ajax/signal).
	 */
	public function handleSetRole(int $userId, string $role): void
	{
		try {
			$this->userManager->setRole($userId, $role);
			$this->flashMessage('Role uživatele byla změněna.', 'success');
		} catch (\InvalidArgumentException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
		}
		$this->redirect('this');
	}


	/**
	 * Přepne blokování uživatele (ajax/signal).
	 */
	public function handleToggleBlock(int $userId): void
	{
		try {
			$users = $this->userManager->getAllUsers();
			$target = null;
			foreach ($users as $u) {
				if ($u->id === $userId) {
					$target = $u;
					break;
				}
			}

			if ($target === null) {
				$this->flashMessage('Uživatel nebyl nalezen.', 'danger');
				$this->redirect('this');
			}

			$isBlocked = $target->is_blocked === true || $target->is_blocked === 't' || $target->is_blocked === '1' || $target->is_blocked === 1;
			$newState = !$isBlocked;

			$this->userManager->setBlocked($userId, $newState);
			$this->flashMessage(
				$newState ? 'Uživatel byl zablokován.' : 'Uživatel byl odblokován.',
				'success',
			);
		} catch (\InvalidArgumentException $e) {
			$this->flashMessage($e->getMessage(), 'danger');
		}
		$this->redirect('this');
	}
}
