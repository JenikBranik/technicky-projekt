<?php

declare(strict_types=1);

namespace App\Presentation\Api;

use Nette\Application\UI\Presenter;
use Nette\Database\Explorer;
use Nette\Http\IResponse;

/**
 * REST API presenter – never renders a Latte template.
 *
 * Endpoints
 *  GET  /api/reports  – moderators: all rows; regular users: 403 (no user_id column yet)
 *  POST /api/reports  – create a new report (all logged-in users)
 */
final class ApiPresenter extends Presenter
{
	/** Disable template auto-rendering entirely. */
	public bool $autoCanonicalize = false;


	public function __construct(
		private Explorer $database,
	) {
	}


	// ── Lifecycle ────────────────────────────────────────────────────────────

	protected function startup(): void
	{
		parent::startup();
		$this->sendCorsHeaders();

		// Handle pre-flight OPTIONS request immediately.
		if ($this->getHttpRequest()->getMethod() === 'OPTIONS') {
			$this->getHttpResponse()->setCode(IResponse::S204_NoContent);
			$this->terminate();
		}

		// Every endpoint requires an authenticated session.
		if (!$this->getUser()->isLoggedIn()) {
			$this->getHttpResponse()->setCode(IResponse::S401_Unauthorized);
			$this->sendJson([
				'error'  => 'Unauthorized',
				'detail' => 'You must be logged in to access the API.',
			]);
		}
	}


	// ── Endpoints ────────────────────────────────────────────────────────────

	/**
	 * GET  /api/reports  – fetch reports (role-based)
	 * POST /api/reports  – create a new report
	 */
	public function actionReports(): void
	{
		$method = $this->getHttpRequest()->getMethod();

		match ($method) {
			'GET'  => $this->getReports(),
			'POST' => $this->createReport(),
			default => $this->sendError(405, 'Method Not Allowed'),
		};
	}


	// ── GET /api/reports ─────────────────────────────────────────────────────

	private function getReports(): void
	{
		$user        = $this->getUser();
		$isModerator = $user->isInRole('moderator');

		$query = $this->database->table('reports');

		if (!$isModerator) {
			// Filter by the logged-in user's ID.
			$query = $query->where('user_id', $this->getUser()->getId());
		}

		$reports = [];
		foreach ($query->fetchAll() as $row) {
			$reports[] = $row->toArray();
		}

		$this->sendJson([
			'status'  => 'ok',
			'role'    => 'moderator',
			'count'   => count($reports),
			'reports' => $reports,
		]);
	}


	// ── POST /api/reports ────────────────────────────────────────────────────

	/**
	 * Accepts JSON body:
	 * {
	 *   "category_id": 1,          // required, integer
	 *   "title":       "...",      // required, max 255 chars
	 *   "description": "...",      // optional
	 *   "location":    "..."       // optional, max 100 chars
	 * }
	 */
	private function createReport(): void
	{
		// Parse the JSON request body.
		$raw  = file_get_contents('php://input');
		$data = json_decode($raw, associative: true);

		if (!is_array($data)) {
			$this->sendError(400, 'Invalid JSON body.');
		}

		// ── Validate required fields ──────────────────────────────────────
		$errors = [];

		$categoryId = isset($data['category_id']) ? (int) $data['category_id'] : null;
		if ($categoryId === null || $categoryId <= 0) {
			$errors[] = 'category_id is required and must be a positive integer.';
		}

		$title = trim((string) ($data['title'] ?? ''));
		if ($title === '') {
			$errors[] = 'title is required.';
		} elseif (mb_strlen($title) > 255) {
			$errors[] = 'title must not exceed 255 characters.';
		}

		$location = isset($data['location']) ? trim((string) $data['location']) : null;
		if ($location !== null && mb_strlen($location) > 100) {
			$errors[] = 'location must not exceed 100 characters.';
		}

		if ($errors !== []) {
			$this->getHttpResponse()->setCode(IResponse::S422_UnprocessableEntity);
			$this->sendJson(['error' => 'Validation failed', 'details' => $errors]);
		}

		// ── Verify the category exists ────────────────────────────────────
		if (!$this->database->table('categories')->get($categoryId)) {
			$this->sendError(422, "category_id {$categoryId} does not exist.");
		}

		// ── Insert ───────────────────────────────────────────────────────
		$row = $this->database->table('reports')->insert([
			'category_id' => $categoryId,
			'title'       => $title,
			'description' => $data['description'] ?? null,
			'location'    => $location ?: null,
			'user_id'     => $this->getUser()->getId(),
		]);

		$this->getHttpResponse()->setCode(IResponse::S201_Created);
		$this->sendJson([
			'status' => 'created',
			'report' => $row->toArray(),
		]);
	}


	// ── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Attach CORS headers to every response so a separate frontend
	 * (e.g. a React / Vue SPA on a different origin) can call this API.
	 *
	 * Adjust the allowed origin for production – never use '*' with credentials
	 * in a real deployment.
	 */
	private function sendCorsHeaders(): void
	{
		$response = $this->getHttpResponse();
		$response->setHeader('Access-Control-Allow-Origin',  '*');
		$response->setHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
		$response->setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
		$response->setHeader('Access-Control-Max-Age',       '86400');
	}


	/** Send a JSON error response and terminate. */
	private function sendError(int $code, string $message): never
	{
		$this->getHttpResponse()->setCode($code);
		$this->sendJson(['error' => $message]);
	}
}

