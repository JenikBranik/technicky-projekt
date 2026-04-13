<?php

declare(strict_types=1);

namespace App\Model;

use Nette\Database\Explorer;

final class UserManager
{
	/** Povolené role, které může admin přiřadit */
	public const ASSIGNABLE_ROLES = ['user', 'moderator'];

	public function __construct(
		private Explorer $database,
	) {
	}


	/**
	 * Vrátí seznam uživatelů, volitelně filtrovaný podle jména, seřazený podle ID.
	 *
	 * @return \Nette\Database\Table\ActiveRow[]
	 */
	public function getAllUsers(?string $search = null): array
	{
		$query = $this->database->table('users')->order('id ASC');
		
		if ($search !== null && $search !== '') {
			$query->where('username LIKE ?', "%$search%");
		}

		return $query->fetchAll();
	}


	/**
	 * Změní roli uživatele.
	 *
	 * @throws \InvalidArgumentException Pokud je role neplatná nebo se jedná o admin účet.
	 */
	public function setRole(int $userId, string $role): void
	{
		if (!in_array($role, self::ASSIGNABLE_ROLES, true)) {
			throw new \InvalidArgumentException("Neplatná role: {$role}");
		}

		$user = $this->database->table('users')->get($userId);
		if ($user === null) {
			throw new \InvalidArgumentException("Uživatel #{$userId} neexistuje.");
		}
		if ($user->role === 'admin') {
			throw new \InvalidArgumentException("Roli administrátora nelze měnit.");
		}

		$this->database->table('users')
			->where('id', $userId)
			->update(['role' => $role]);
	}


	/**
	 * Nastaví stav blokování uživatele.
	 *
	 * @throws \InvalidArgumentException Pokud se jedná o admin účet.
	 */
	public function setBlocked(int $userId, bool $blocked): void
	{
		$user = $this->database->table('users')->get($userId);
		if ($user === null) {
			throw new \InvalidArgumentException("Uživatel #{$userId} neexistuje.");
		}
		if ($user->role === 'admin') {
			throw new \InvalidArgumentException("Administrátora nelze blokovat.");
		}

		$this->database->table('users')
			->where('id', $userId)
			->update(['is_blocked' => $blocked ? 't' : 'f']);
	}
}
