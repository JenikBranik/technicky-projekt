<?php

declare(strict_types=1);

namespace App\Presentation\Home;

use Nette;
use Nette\Application\UI\Presenter;
use Nette\Database\Explorer;
use Nette\Http\FileUpload;

final class HomePresenter extends Presenter
{
	// Allowed MIME types for uploads
	private const ALLOWED_MIME = [
		'image/jpeg', 'image/png', 'image/gif', 'image/webp',
		'video/mp4', 'video/webm', 'video/ogg', 'video/quicktime',
	];

	// Max upload size: 50 MB
	private const MAX_UPLOAD_BYTES = 2_097_152; // 2 MB — matches PHP's default upload_max_filesize

	public function __construct(
		private Explorer $database,
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
			->addRule(Nette\Application\UI\Form::MimeType, 'Povolené formáty: obrázky a videa.', self::ALLOWED_MIME)
			->addRule(Nette\Application\UI\Form::MaxFileSize, 'Maximální velikost souboru je 50 MB.', self::MAX_UPLOAD_BYTES);

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
			->addRule(Nette\Application\UI\Form::MimeType, 'Povolené formáty: obrázky a videa.', self::ALLOWED_MIME)
			->addRule(Nette\Application\UI\Form::MaxFileSize, 'Maximální velikost souboru je 50 MB.', self::MAX_UPLOAD_BYTES);

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

		$year    = date('Y');
		$dir     = $this->getHttpRequest()->getUrl()->getBasePath();
		$saveDir = __DIR__ . '/../../../../www/uploads/' . $year;

		if (!is_dir($saveDir)) {
			mkdir($saveDir, 0755, true);
		}

		$ext      = strtolower(pathinfo($upload->getSanitizedName(), PATHINFO_EXTENSION));
		$filename = bin2hex(random_bytes(12)) . '.' . $ext;
		$upload->move($saveDir . '/' . $filename);

		return 'uploads/' . $year . '/' . $filename;
	}
}
