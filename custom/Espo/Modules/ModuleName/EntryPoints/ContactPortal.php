<?php
namespace Espo\Modules\ModuleName\EntryPoints;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\ORM\EntityManager;

class ContactPortal implements EntryPoint
{
    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public function run(Request $request, Response $response): void
    {
        $id = $request->getQueryParam('id') ?? '';

        $contact = $this->entityManager->getEntityById('Contact', $id);

        if (!$contact) {
            $response->setStatus(404);
            $response->writeBody('<p>Contact not found.</p>');
            return;
        }

        $saved = false;

        if ($request->getMethod() === 'POST') {
            $contact->set('firstName', strip_tags($request->getParsedBodyParam('firstName') ?? ''));
            $contact->set('lastName',  strip_tags($request->getParsedBodyParam('lastName')  ?? ''));
            $contact->set('title',     strip_tags($request->getParsedBodyParam('title')     ?? ''));
            $contact->set('emailAddress', strip_tags($request->getParsedBodyParam('emailAddress') ?? ''));
            $contact->set('phoneNumber',  strip_tags($request->getParsedBodyParam('phoneNumber')  ?? ''));

            $this->entityManager->saveEntity($contact);
            $saved = true;
        }

        $e = fn(string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        $firstName = $e((string) $contact->get('firstName'));
        $lastName  = $e((string) $contact->get('lastName'));
        $title     = $e((string) $contact->get('title'));
        $email     = $e((string) $contact->get('emailAddress'));
        $phone     = $e((string) $contact->get('phoneNumber'));
        $fullName  = $e((string) $contact->get('name'));

        $savedBanner = $saved
            ? '<div class="banner">Changes saved.</div>'
            : '';

        $actionUrl = $e('?entryPoint=contactPortal&id=' . $id);

        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>{$fullName}</title>
            <style>
                *, *::before, *::after { box-sizing: border-box; }
                body    { font-family: sans-serif; max-width: 640px; margin: 48px auto; padding: 0 24px; color: #333; }
                h1      { font-size: 1.6rem; margin-bottom: 32px; }
                .field  { margin-bottom: 20px; }
                label   { display: block; font-size: 0.78rem; font-weight: 600; text-transform: uppercase;
                          letter-spacing: 0.05em; color: #999; margin-bottom: 4px; }
                input   { width: 100%; padding: 8px 10px; border: 1px solid #ccc; border-radius: 4px;
                          font-size: 1rem; color: #333; }
                input:focus { outline: none; border-color: #3273dc; box-shadow: 0 0 0 2px #3273dc33; }
                .row    { display: flex; gap: 16px; }
                .row .field { flex: 1; }
                button  { padding: 10px 24px; background: #3273dc; color: #fff; border: none;
                          border-radius: 4px; font-size: 1rem; cursor: pointer; }
                button:hover { background: #2563c0; }
                .banner { background: #d4edda; color: #155724; border: 1px solid #c3e6cb;
                          border-radius: 4px; padding: 10px 16px; margin-bottom: 24px; }
            </style>
        </head>
        <body>
            <h1>Edit Contact</h1>
            {$savedBanner}
            <form method="POST" action="{$actionUrl}">
                <div class="row">
                    <div class="field">
                        <label for="firstName">First Name</label>
                        <input id="firstName" name="firstName" type="text" value="{$firstName}">
                    </div>
                    <div class="field">
                        <label for="lastName">Last Name</label>
                        <input id="lastName" name="lastName" type="text" value="{$lastName}">
                    </div>
                </div>
                <div class="field">
                    <label for="title">Title</label>
                    <input id="title" name="title" type="text" value="{$title}">
                </div>
                <div class="field">
                    <label for="emailAddress">Email</label>
                    <input id="emailAddress" name="emailAddress" type="email" value="{$email}">
                </div>
                <div class="field">
                    <label for="phoneNumber">Phone</label>
                    <input id="phoneNumber" name="phoneNumber" type="tel" value="{$phone}">
                </div>
                <button type="submit">Save</button>
            </form>
        </body>
        </html>
        HTML;

        $response->setHeader('Content-Type', 'text/html; charset=utf-8');
        $response->writeBody($html);
    }
}
