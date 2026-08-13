<?php
declare(strict_types=1);

namespace tests\unit\Espo\Modules\ContactPortal\EntryPoints;

use PHPUnit\Framework\TestCase;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Modules\ContactPortal\EntryPoints\ContactPortalRegister;
use Espo\Modules\ContactPortal\Util\HtmlRenderer;
use Espo\Modules\ContactPortal\Util\ContactFieldProvider;

/**
 * Unit tests for Espo\Modules\ContactPortal\EntryPoints\ContactPortalRegister
 *
 * These tests mock HtmlRenderer and ContactFieldProvider so they remain fast,
 * isolated unit tests that don't require a full Espo site.
 */
class ContactPortalRegisterTest extends TestCase
{
    public function testWritesRenderedPageWhenNoFields(): void
    {
        $htmlRenderer = $this->createMock(HtmlRenderer::class);
        $fieldProvider = $this->createMock(ContactFieldProvider::class);
        $request = $this->createMock(Request::class);
        $response = $this->createMock(Response::class);

        // No registration fields configured -> renderForm builds a form with no fields.
        $fieldProvider
            ->expects($this->once())
            ->method('getRegistrationFields')
            ->willReturn([]);

        // htmlRenderer::render should be called with title and a string body.
        $html = '<html>PAGE</html>';
        $htmlRenderer
            ->expects($this->once())
            ->method('render')
            ->with('Join Sepheo', $this->isString())
            ->willReturn($html);

        // Response should receive the rendered HTML.
        $response->expects($this->once())->method('writeBody')->with($html);

        $entry = new ContactPortalRegister($htmlRenderer, $fieldProvider);

        $entry->run($request, $response);
    }

    public function testRendersFieldsViaHtmlRenderer(): void
    {
        $htmlRenderer = $this->createMock(HtmlRenderer::class);
        $fieldProvider = $this->createMock(ContactFieldProvider::class);
        $request = $this->createMock(Request::class);
        $response = $this->createMock(Response::class);

        $fields = [
            [
                'name' => 'firstName',
                'label' => 'First Name',
                'hint' => '',
                'readOnly' => false,
                'inputType' => 'text',
                'originalType' => 'varchar',
                'required' => true,
                'maxLength' => 100,
                'options' => null,
                'step' => null,
                'accept' => null,
                'maxFileSize' => null,
                'maxCount' => null,
            ],
            [
                'name' => 'emailAddress',
                'label' => 'Email',
                'hint' => '',
                'readOnly' => false,
                'inputType' => 'email',
                'originalType' => 'email',
                'required' => true,
                'maxLength' => 254,
                'options' => null,
                'step' => null,
                'accept' => null,
                'maxFileSize' => null,
                'maxCount' => null,
            ],
        ];

        $fieldProvider
            ->expects($this->once())
            ->method('getRegistrationFields')
            ->willReturn($fields);

        // Each field should be passed to renderFormField() once.
        $call = 0;
        $htmlRenderer
            ->expects($this->exactly(2))
            ->method('renderFormField')
            ->willReturnCallback(function (...$args) use (&$call, $fields) {
                $fieldArg = $args[0] ?? null;
                \PHPUnit\Framework\Assert::assertEquals(
                    $fields[$call],
                    $fieldArg,
                );
                $htmlMap = [
                    '<input name="firstName">',
                    '<input name="emailAddress">',
                ];

                $res = $htmlMap[$call] ?? '';
                $call++;

                return $res;
            });

        // render() should be called with the title and an HTML body that contains the field fragments.
        $html = '<html>PAGE WITH FIELDS</html>';
        $htmlRenderer
            ->expects($this->once())
            ->method('render')
            ->with(
                'Join Sepheo',
                $this->stringContains('<input name="firstName">'),
            )
            ->willReturn($html);

        $response->expects($this->once())->method('writeBody')->with($html);

        $entry = new ContactPortalRegister($htmlRenderer, $fieldProvider);

        $entry->run($request, $response);
    }
}
