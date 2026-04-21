<?php
declare(strict_types=1);

namespace Espo\Modules\ContactPortal\Util;

use Espo\Tools\Layout\LayoutProvider;
use Espo\Core\Utils\Metadata;
use Espo\Core\Utils\Language;

/**
 * Returns editable field definitions for the Contact Portal form.
 *
 * HOW TO CONTROL WHICH FIELDS APPEAR IN THE PORTAL
 * =================================================
 * Edit: src/files/custom/Espo/Modules/ContactPortal/Resources/layouts/Contact/portalEdit.json
 * (or on production: custom/Espo/Custom/Resources/layouts/Contact/portalEdit.json — takes priority)
 *
 * Format: a simple flat JSON array of EspoCRM field names in display order:
 *   ["firstName", "lastName", "emailAddress", "cMatrixID", ...]
 * Add a name → it appears. Remove it → it disappears.
 * Then do Admin → Clear Cache. No PHP changes ever needed.
 *
 * SUPPORTED FIELD TYPES (rendered automatically):
 *   varchar, email, phone, url, int, float, currency,
 *   date, datetime, bool, text, enum, multiEnum, urlMultiple, address
 *
 * AUTOMATICALLY SKIPPED (cannot render in plain HTML form):
 *   link, linkMultiple, image, jsonArray, wysiwyg, …
 *
 * FILE UPLOAD FIELDS (attachmentMultiple):
 *   Rendered as <input type="file">. Files are saved as EspoCRM Attachment entities
 *   linked to the Contact. Each field stores at most maxCount files.
 */
class ContactFieldProvider
{
    /** Fields always excluded regardless of the layout. */
    private const EXCLUDED = [
        'id', 'name', 'createdAt', 'modifiedAt', 'createdBy', 'modifiedBy',
        'deleted', 'portalToken', 'portalTokenExpiry', 'salutationName',
        'assignedUser', 'assignedUsers',
    ];

    /**
     * Maps EspoCRM field types to HTML input config.
     * 'address' is expanded into 5 sub-fields rather than a single input.
     *
     * @var array<string, array{type: string, step?: string}>
     */
    private const TYPE_MAP = [
        'varchar'     => ['type' => 'text'],
        'email'       => ['type' => 'email'],
        'phone'       => ['type' => 'tel'],
        'url'         => ['type' => 'url'],
        'int'         => ['type' => 'number'],
        'float'       => ['type' => 'number', 'step' => 'any'],
        'currency'    => ['type' => 'number', 'step' => '0.01'],
        'date'        => ['type' => 'date'],
        'datetime'    => ['type' => 'datetime-local'],
        'bool'        => ['type' => 'checkbox'],
        'text'        => ['type' => 'textarea'],
        'enum'        => ['type' => 'select'],
        'multiEnum'   => ['type' => 'multiselect'],  // rendered as grouped checkboxes
        'urlMultiple'         => ['type' => 'url'],           // simplified: first URL only
        'address'             => ['type' => 'address'],       // composite — expanded below
        'attachmentMultiple'  => ['type' => 'file'],          // file upload
    ];

    public function __construct(
        private readonly LayoutProvider $layout,
        private readonly Metadata $metadata,
        private readonly Language $language,
    ) {}

    /**
     * Returns ordered field definitions for the portal edit form.
     *
     * Each entry:
     *   name         – EspoCRM camelCase field name
     *   label        – Human-readable label (i18n or auto-humanized)
     *   inputType    – 'text'|'email'|'tel'|'url'|'number'|'date'|'datetime-local'
     *                  |'checkbox'|'textarea'|'select'|'multiselect'
     *   originalType – raw EspoCRM type (used by HandleSave for correct storage)
     *   required     – bool
     *   maxLength    – int|null
     *   options      – string[]|null  (for select / multiselect)
     *   step         – string|null    (for number inputs)
     *
     * @return list<array<string, mixed>>
     */
    public function getFields(): array
    {
        $entries = $this->extractNamesFromLayout();
        $fields  = [];

        foreach ($entries as $entry) {
            $name     = $entry['name'];
            $hint     = $entry['hint'];
            $subHints = $entry['subHints'];

            if (in_array($name, self::EXCLUDED, true)) {
                continue;
            }

            /** @var array<string, mixed>|null $def */
            $def  = $this->metadata->get(['entityDefs', 'Contact', 'fields', $name]);
            $type = is_array($def) ? (string) ($def['type'] ?? '') : '';

            // Address composite → expand into individual sub-field entries.
            if ($type === 'address') {
                foreach ($this->addressSubFields($name, $subHints) as $sub) {
                    $fields[] = $sub;
                }
                continue;
            }

            if (!array_key_exists($type, self::TYPE_MAP)) {
                continue; // silently skip link, linkMultiple, image, attachmentMultiple, etc.
            }

            $inputConfig = self::TYPE_MAP[$type];
            $label       = $this->resolveLabel($name, is_array($def) ? $def : []);

            $fields[] = [
                'name'         => $name,
                'label'        => $label,
                'hint'         => $hint,
                'inputType'    => $inputConfig['type'],
                'originalType' => $type,
                'required'     => !empty($def['required']),
                'maxLength'    => isset($def['maxLength']) ? (int) $def['maxLength'] : null,
                'options'      => in_array($type, ['enum', 'multiEnum'])
                    ? array_values(array_map('strval', (array) ($def['options'] ?? [])))
                    : null,
                'step'         => $inputConfig['step'] ?? null,
                // attachment-specific metadata
                'accept'       => $type === 'attachmentMultiple'
                    ? array_values(array_map('strval', (array) ($def['accept'] ?? [])))
                    : null,
                'maxFileSize'  => $type === 'attachmentMultiple' && isset($def['maxFileSize'])
                    ? (int) $def['maxFileSize']   // MB
                    : null,
                'maxCount'     => $type === 'attachmentMultiple' && isset($def['maxCount'])
                    ? (int) $def['maxCount']
                    : null,
            ];
        }

        return $fields ?: $this->fallback();
    }

    // -------------------------------------------------------------------------

    /**
     * Reads portalEdit layout first; falls back to detail layout.
     *
     * Accepted formats:
     *
     *   1. Object array (recommended for portalEdit.json):
     *        [{"name": "firstName", "hint": "..."},
     *         {"name": "address", "subHints": {"addressCity": "..."}}, ...]
     *      `hint` and `subHints` are optional.
     *
     *   2. Flat string array (also accepted for portalEdit.json):
     *        ["firstName", "emailAddress", "cMatrixID"]
     *
     *   3. Standard EspoCRM panels/rows structure (detail.json format).
     *
     * @return list<array{name: string, hint: string, subHints: array<string, string>}>
     */
    private function extractNamesFromLayout(): array
    {
        foreach (['portalEdit', 'detail'] as $layoutName) {
            $json = $this->layout->get('Contact', $layoutName);

            if ($json === null) {
                continue;
            }

            $decoded = json_decode($json, true);

            if (!is_array($decoded)) {
                continue;
            }

            // Format 1: object array [{"name": "...", "hint": "...", "subHints": {...}}, ...]
            if (isset($decoded[0]) && is_array($decoded[0]) && isset($decoded[0]['name'])) {
                $entries = [];
                $seen    = [];
                foreach ($decoded as $item) {
                    if (!is_array($item) || !isset($item['name']) || $item['name'] === '') {
                        continue;
                    }
                    $n = (string) $item['name'];
                    if (in_array($n, $seen, true)) {
                        continue;
                    }
                    $seen[]  = $n;
                    $rawSubs = is_array($item['subHints'] ?? null) ? $item['subHints'] : [];
                    $entries[] = [
                        'name'     => $n,
                        'hint'     => (string) ($item['hint'] ?? ''),
                        'subHints' => array_map('strval', $rawSubs),
                    ];
                }
                if ($entries) {
                    return $entries;
                }
            }

            // Format 2: flat string array ["fieldName", ...]
            if (isset($decoded[0]) && is_string($decoded[0])) {
                return array_values(array_map(
                    fn(string $n) => ['name' => $n, 'hint' => '', 'subHints' => []],
                    array_unique(array_filter($decoded, 'is_string'))
                ));
            }

            // Format 3: standard EspoCRM panels/rows structure.
            $entries = [];
            $seen    = [];
            foreach ($decoded as $panel) {
                foreach ((array) ($panel['rows'] ?? []) as $row) {
                    foreach ((array) $row as $cell) {
                        if (!is_array($cell) || !isset($cell['name']) || $cell['name'] === '') {
                            continue;
                        }
                        $n = (string) $cell['name'];
                        if (in_array($n, $seen, true)) {
                            continue;
                        }
                        $seen[]  = $n;
                        $entries[] = ['name' => $n, 'hint' => '', 'subHints' => []];
                    }
                }
            }

            if ($entries) {
                return $entries;
            }
        }

        return [];
    }

    /**
     * Expands an 'address' composite field into 5 individual sub-field definitions.
     * EspoCRM stores these as addressStreet, addressCity, etc. on the entity.
     *
     * @param  array<string, string> $subHints  Per-sub-field hint text from the layout entry.
     * @return list<array<string, mixed>>
     */
    private function addressSubFields(string $fieldName, array $subHints = []): array
    {
        $h = fn(string $key): string => $subHints[$key] ?? '';
        return [
            ['name' => $fieldName . 'Street',     'label' => 'Street',      'hint' => $h($fieldName . 'Street'),     'inputType' => 'text', 'originalType' => 'varchar', 'required' => false, 'maxLength' => 255, 'options' => null, 'step' => null, 'accept' => null, 'maxFileSize' => null, 'maxCount' => null],
            ['name' => $fieldName . 'City',       'label' => 'City',        'hint' => $h($fieldName . 'City'),       'inputType' => 'text', 'originalType' => 'varchar', 'required' => false, 'maxLength' => 100, 'options' => null, 'step' => null, 'accept' => null, 'maxFileSize' => null, 'maxCount' => null],
            ['name' => $fieldName . 'State',      'label' => 'State',       'hint' => $h($fieldName . 'State'),      'inputType' => 'text', 'originalType' => 'varchar', 'required' => false, 'maxLength' => 100, 'options' => null, 'step' => null, 'accept' => null, 'maxFileSize' => null, 'maxCount' => null],
            ['name' => $fieldName . 'PostalCode', 'label' => 'Postal code', 'hint' => $h($fieldName . 'PostalCode'), 'inputType' => 'text', 'originalType' => 'varchar', 'required' => false, 'maxLength' => 40,  'options' => null, 'step' => null, 'accept' => null, 'maxFileSize' => null, 'maxCount' => null],
            ['name' => $fieldName . 'Country',    'label' => 'Country',     'hint' => $h($fieldName . 'Country'),    'inputType' => 'text', 'originalType' => 'varchar', 'required' => false, 'maxLength' => 100, 'options' => null, 'step' => null, 'accept' => null, 'maxFileSize' => null, 'maxCount' => null],
        ];
    }

    /**
     * Resolves a label via EspoCRM i18n; falls back to auto-humanizing the name.
     *
     * @param array<string, mixed> $def
     */
    private function resolveLabel(string $name, array $def): string
    {
        $translated = $this->language->translate($name, 'fields', 'Contact');

        // Language::translate returns the key unchanged when no translation exists.
        if ($translated === $name) {
            return $this->humanize($name);
        }

        return $translated;
    }

    /**
     * Converts a camelCase field name to a readable label.
     * Strips the leading lowercase 'c' custom-field prefix.
     *
     *   cMatrixID             → Matrix ID
     *   cMembershipAspirations → Membership Aspirations
     *   emailAddress          → Email Address
     */
    private function humanize(string $name): string
    {
        $name = preg_replace('/^c(?=[A-Z])/', '', $name) ?? $name;
        $name = preg_replace('/([a-z\d])([A-Z])/', '$1 $2', $name) ?? $name;
        $name = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $name) ?? $name;

        return ucfirst(trim($name));
    }

    /**
     * Hardcoded fallback when no layout file can be found at all.
     *
     * @return list<array<string, mixed>>
     */
    private function fallback(): array
    {
        return [
            ['name' => 'firstName',    'label' => 'First Name', 'hint' => '', 'inputType' => 'text',  'originalType' => 'varchar', 'required' => true,  'maxLength' => 100, 'options' => null, 'step' => null, 'accept' => null, 'maxFileSize' => null, 'maxCount' => null],
            ['name' => 'lastName',     'label' => 'Last Name',  'hint' => '', 'inputType' => 'text',  'originalType' => 'varchar', 'required' => true,  'maxLength' => 100, 'options' => null, 'step' => null, 'accept' => null, 'maxFileSize' => null, 'maxCount' => null],
            ['name' => 'emailAddress', 'label' => 'Email',      'hint' => '', 'inputType' => 'email', 'originalType' => 'email',   'required' => true,  'maxLength' => 254, 'options' => null, 'step' => null, 'accept' => null, 'maxFileSize' => null, 'maxCount' => null],
            ['name' => 'phoneNumber',  'label' => 'Phone',      'hint' => '', 'inputType' => 'tel',   'originalType' => 'phone',   'required' => false, 'maxLength' => 50,  'options' => null, 'step' => null, 'accept' => null, 'maxFileSize' => null, 'maxCount' => null],
        ];
    }
}
