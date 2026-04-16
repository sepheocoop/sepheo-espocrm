<?php
namespace Espo\Custom\Modules\ModuleName\EntryPoints;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Core\Utils\Client\ActionRenderer;

file_put_contents("php://stdout", "Test Entry Point") 

class MyEntryPoint implements EntryPoint
{
    // Allows the page to load without a server-side auth pre-check.
    // The frontend app will redirect to login if the user is not authenticated.
    use NoAuth;
   
    public function __construct(
        private ActionRenderer $actionRenderer,
    ) {}

    public function run(Request $request, Response $response): void
    {
        $id = $request->getQueryParam('id') ?? '';

        $params = ActionRenderer\Params
            ::create('crm:controllers/contact', 'view')
            ->withData(['id' => $id]);

        $this->actionRenderer->write($response, $params);
    }
}
