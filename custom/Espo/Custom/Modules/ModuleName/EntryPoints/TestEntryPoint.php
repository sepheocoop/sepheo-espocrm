<?php
namespace Espo\Custom\Modules\ModuleName\EntryPoints;

use Espo\Core\Utils\Client\Script;
use Espo\Tools\LeadCapture\FormService;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Client\ActionRenderer;

/**
 * @noinspection PhpUnused
 */
class TestEntryPoint implements EntryPoint
{
    use NoAuth;

    public function __construct(
        private ActionRenderer $actionRenderer,
        private FormService $service,
    ) {}

    /**
     * @throws BadRequest
     * @throws NotFound
     */
    public function run(Request $request, Response $response): void
    {
        $id = $request->getQueryParam('id');

        if (!$id) {
            throw new BadRequest("No ID.");
        }

        [$leadCapture, $data, $captchaScript] = $this->service->getData($id);

        $params = new ActionRenderer\Params(
            controller: 'controllers/lead-capture-form',
            action: 'show',
            data: $data,
        );

        $params = $params
            ->withFrameAncestors($leadCapture->getFormFrameAncestors())
            ->withPageTitle($leadCapture->getFormTitle())
            ->withTheme($leadCapture->getFormTheme());

        if ($captchaScript) {
            $params = $params->withScripts([new Script(source: $captchaScript)]);
        }

        $this->actionRenderer->write($response, $params);
    }
}
