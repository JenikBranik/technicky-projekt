<?php

declare(strict_types=1);

namespace App\Model;

use Nette;
use Nette\Database\Explorer;
use Nette\Security\AuthenticationException;
use Nette\Security\IIdentity;
use Nette\Security\Passwords;
use Nette\Security\SimpleIdentity;


final class Authenticator implements Nette\Security\Authenticator
{
	public function __construct(
		private Explorer $database,
		private Passwords $passwords,
	) {
	}


	/**
	 * Authenticates a user against the database.
	 *
	 * @throws AuthenticationException
	 */
	public function authenticate(string $user, string $password): IIdentity
	{
		$row = $this->database
			->table('users')
			->where('username', $user)
			->fetch();

		if ($row === null) {
			throw new AuthenticationException('Uživatelské jméno nebylo nalezeno.', self::IdentityNotFound);
		}

		if (!$this->passwords->verify($password, $row->password_hash)) {
			throw new AuthenticationException('Zadané heslo je nesprávné.', self::InvalidCredential);
		}

		// Rehash the password if the hashing options changed (e.g. cost factor bump).
		if ($this->passwords->needsRehash($row->password_hash)) {
			$row->update(['password_hash' => $this->passwords->hash($password)]);
		}

		return new SimpleIdentity(
			$row->id,
			$row->role,
			['username' => $row->username],
		);
	}
}
