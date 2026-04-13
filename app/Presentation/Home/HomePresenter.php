<?php

declare(strict_types=1);

namespace App\Presentation\Home;

use Nette;
use Nette\Application\UI\Presenter;
use Nette\Database\Explorer;
use Nette\Http\FileUpload;

final class HomePresenter extends Presenter
{
	// Max upload size: 2 MB (matches PHP's default upload_max_filesize)
	private const MAX_UPLOAD_BYTES = 2_097_152;

	public function __construct(
		private Explorer $database,
		private Nette\Mail\Mailer $mailer,
	) {
	}


	protected function startup(): void
	{
		parent::startup();
		if (!$this->getUser()->isLoggedIn()) {
			$this->redirect('Sign:in');
		}
	}

	// ── Profile ───────────────────────────────────────────────────

	public function renderProfile(): void
	{
		// Profile data comes from $user in the template.
	}

	protected function createComponentProfileForm(): Nette\Application\UI\Form
	{
		$form = new Nette\Application\UI\Form;

		$userRow = $this->database->table('users')->get($this->getUser()->getId());

		$form->addEmail('email', 'E-mail:')
			->setRequired('Zadejte svůj e-mail.')
			->setDefaultValue($userRow ? $userRow->email : null)
			->setMaxLength(255);

		$form->addSubmit('send', 'Uložit e-mail');

		$form->onSuccess[] = [$this, 'profileFormSucceeded'];

		return $form;
	}

	public function profileFormSucceeded(Nette\Application\UI\Form $form, \stdClass $data): void
	{
		$this->database->table('users')
			->where('id', $this->getUser()->getId())
			->update([
				'email' => $data->email,
			]);

		// Aktualizace identity v session, aby změna byla vidět hned bez odhlášení
		$this->getUser()->getIdentity()->email = $data->email;

		// Zkusit odeslat notifikační e-mail
		$mail = new Nette\Mail\Message;
		$mail->setFrom('testovaciwebreport@seznam.cz')
			->addTo($data->email)
			->setSubject('E-mail byl aktualizován')
			->setBody("Dobrý den,\n\nváš e-mail byl úspěšně nastaven na tuto adresu.\n\nS pozdravem\nTým Školního portálu");
		try {
			$this->mailer->send($mail);
			\Tracy\Debugger::log("Email 'Potvrzení změny' úspěšně odeslán na: " . $data->email, 'mail');
			$this->flashMessage('Váš e-mail byl úspěšně aktualizován.', 'success');
		} catch (\Exception $e) {
			\Tracy\Debugger::log("CHYBA odesílání na {$data->email}: " . $e->getMessage(), 'mail-error');
			\Tracy\Debugger::log($e, \Tracy\Debugger::EXCEPTION);
			$this->flashMessage('Váš e-mail byl aktualizován, ale odeslání notifikace se nezdařilo.', 'warning');
		}
		
		$this->redirect('this');
	}

	// ── My Reports ───────────────────────────────────────────────

	public function renderMyReports(): void
	{
		$this->template->reports = $this->database
			->query(
				'SELECT r.id, r.title, c.name AS category
				 FROM reports r
				 LEFT JOIN categories c ON c.id = r.category_id
				 WHERE r.user_id = ?
				 ORDER BY r.id DESC',
				$this->getUser()->getId(),
			)
			->fetchAll();
	}

	// ── Detail / Thread ───────────────────────────────────────────

	public function renderDetail(int $id): void
	{
		$report = $this->database->table('reports')->get($id);

		if (!$report) {
			$this->error('Hlášení nebylo nalezeno.', 404);
		}

		// Regular users can only see their own reports.
		if (!$this->getUser()->isInRole('moderator') && $report->user_id !== $this->getUser()->getId()) {
			$this->redirect('Home:default');
		}

		$this->template->report = $report;

		// Load the thread messages with author info.
		$this->template->messages = $this->database
			->query(
				'SELECT m.id, m.message, m.attachment, m.created_at, u.username, u.role
				 FROM report_messages m
				 JOIN users u ON u.id = m.user_id
				 WHERE m.report_id = ?
				 ORDER BY m.created_at ASC',
				$id,
			)
			->fetchAll();

		$this->template->reportId = $id;
	}

	// ── Message form ──────────────────────────────────────────────

	protected function createComponentMessageForm(): Nette\Application\UI\Form
	{
		$form = new Nette\Application\UI\Form;

		$form->addTextArea('message', 'Vaše zpráva:')
			->setRequired('Napište zprávu.')
			->addRule(Nette\Application\UI\Form::MaxLength, 'Maximálně %d znaků.', 2000);

		$form->addUpload('attachment', 'Přiložit foto/video:')
			->setRequired(false)
			->addRule(Nette\Application\UI\Form::MaxFileSize, 'Maximální velikost souboru je 50 MB.', self::MAX_UPLOAD_BYTES);

		$form->addSubmit('send', 'Odeslat');

		$form->onSuccess[] = [$this, 'messageFormSucceeded'];

		return $form;
	}

	public function messageFormSucceeded(Nette\Application\UI\Form $form, \stdClass $data): void
	{
		$id = (int) $this->getParameter('id');

		$attachmentPath = $this->saveUpload($data->attachment);

		$this->database->table('report_messages')->insert([
			'report_id'  => $id,
			'user_id'    => $this->getUser()->getId(),
			'message'    => $data->message,
			'attachment' => $attachmentPath,
			'created_at' => new \DateTimeImmutable,
		]);

		// Odeslání e-mailové notifikace vlastníkovi hlášení (pokud zprávu píše někdo jiný)
		$report = $this->database->table('reports')->get($id);
		if ($report && $report->user_id !== $this->getUser()->getId()) {
			$owner = $this->database->table('users')->get($report->user_id);
			if ($owner && $owner->email) {
				try {
					$mail = new Nette\Mail\Message;
					$mail->setFrom('testovaciwebreport@seznam.cz')
						->addTo($owner->email)
						->setSubject('Nová zpráva u vašeho hlášení: ' . $report->title)
						->setBody("Dobrý den,\n\nu vašeho hlášení „{$report->title}“ se objevila nová zpráva.\n\nText zprávy:\n{$data->message}\n\nMůžete na ni odpovědět v portálu: " . $this->link('//Home:detail', ['id' => $id]));
					
					$this->mailer->send($mail);
				} catch (\Exception $e) {
					// Ignorujeme chybu odeslání, aby aplikace nespadla
				}
			}
		}

		$this->redirect('this');
	}

	// ── Report form ───────────────────────────────────────────────

	public function renderDefault(): void
	{
		$this->template->categories = $this->database
			->table('categories')
			->order('name ASC')
			->fetchAll();
	}

	protected function createComponentReportForm(): Nette\Application\UI\Form
	{
		$form = new Nette\Application\UI\Form;

		$categories = $this->database->table('categories')->fetchPairs('id', 'name');

		$form->addSelect('category_id', 'Typ přestupku:', $categories)
			->setPrompt('Zvolte typ')
			->setRequired('Vyberte prosím typ přestupku.');

		$form->addText('title', 'Předmět hlášení:')
			->setRequired('Napište stručný název.');

		$form->addTextArea('description', 'Podrobný popis:');

		$form->addText('location', 'Místo ve škole (např. 2. patro):');

		$form->addUpload('attachment', 'Přiložit foto/video:')
			->setRequired(false)
			->addRule(Nette\Application\UI\Form::MaxFileSize, 'Maximální velikost souboru je 50 MB.', self::MAX_UPLOAD_BYTES);

		$form->addSubmit('send', 'Odeslat hlášení');

		$form->onSuccess[] = [$this, 'reportFormSucceeded'];

		return $form;
	}

	public function reportFormSucceeded(Nette\Application\UI\Form $form, \stdClass $data): void
	{
		$attachmentPath = $this->saveUpload($data->attachment);

		$this->database->table('reports')->insert([
			'category_id' => $data->category_id,
			'title'       => $data->title,
			'description' => $data->description,
			'location'    => $data->location,
			'attachment'  => $attachmentPath,
			'user_id'     => $this->getUser()->getId(),
		]);

		// Odeslání potvrzovacího e-mailu uživateli
		$userRow = $this->database->table('users')->get($this->getUser()->getId());
		if ($userRow && $userRow->email) {
			$mail = new Nette\Mail\Message;
			$mail->setFrom('testovaciwebreport@seznam.cz')
				->addTo($userRow->email)
				->setSubject('Hlášení přijato do systému: ' . $data->title)
				->setBody("Dobrý den,\n\nvaše hlášení „{$data->title}“ bylo úspěšně přijato do systému.\n\nO dalším postupu vás budeme informovat e-mailem.\nDetail hlášení můžete sledovat zde: " . $this->link('//Home:detail', ['id' => $this->database->getInsertId()]));
			try {
				$this->mailer->send($mail);
				\Tracy\Debugger::log("Email 'Hlášení přijato' úspěšně odeslán na: " . $userRow->email, 'mail');
			} catch (\Exception $e) {
				\Tracy\Debugger::log("CHYBA odesílání na {$userRow->email}: " . $e->getMessage(), 'mail-error');
				\Tracy\Debugger::log($e, \Tracy\Debugger::EXCEPTION);
			}
		}

		$this->flashMessage('Hlášení bylo úspěšně uloženo do systému.', 'success');
		$this->redirect('this');
	}

	// ── Moderator report list ─────────────────────────────────────

	public function renderReports(): void
	{
		// Moderator-only page.
		if (!$this->getUser()->isInRole('moderator')) {
			$this->redirect('Home:default');
		}

		$this->template->reports = $this->database
			->query(
				'SELECT r.id, r.title, u.username
				 FROM reports r
				 LEFT JOIN users u ON u.id = r.user_id
				 ORDER BY r.id DESC
				 LIMIT 10',
			)
			->fetchAll();
	}

	// ── Upload helper ─────────────────────────────────────────────

	private function saveUpload(FileUpload $upload): ?string
	{
		if (!$upload->isOk() || !$upload->hasFile()) {
			return null;
		}

		// getSanitizedName() requires INTL extension — use getName() + vlastní sanitizace
		$originalName = $upload->getName();
		$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

		// Whitelist povolených přípon — bez závislosti na fileinfo/finfo_file()
		$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'ogg', 'mov'];
		if (!in_array($ext, $allowed, true)) {
			return null;
		}

		$year    = date('Y');
		$saveDir = __DIR__ . '/../../../www/uploads/' . $year;

		if (!is_dir($saveDir)) {
			mkdir($saveDir, 0755, true);
		}

		// Náhodné jméno souboru — bezpečné, bez ohledu na původní název
		$filename = bin2hex(random_bytes(12)) . '.' . $ext;
		$upload->move($saveDir . '/' . $filename);

		return 'uploads/' . $year . '/' . $filename;
	}
}
