<?php

declare(strict_types=1);

namespace App\Presentation\Error\Error5xx;

use Nette;
use Nette\Application\Attributes\Requires;
use Nette\Application\Responses;
use Nette\Http;


/**
 * Handles uncaught exceptions and errors, and logs them.
 */
#[Requires(forward: true)]
final class Error5xxPresenter implements Nette\Application\IPresenter
{
	public function __construct()
	{
	}


	public function run(Nette\Application\Request $request): Nette\Application\Response
	{
		// Display a generic error message to the user
		return new Responses\CallbackResponse(function (Http\IRequest $httpRequest, Http\IResponse $httpResponse): void {
			if (preg_match('#^text/html(?:;|$)#', (string) $httpResponse->getHeader('Content-Type'))) {
				require __DIR__ . '/500.phtml';
			}
		});
	}
}
