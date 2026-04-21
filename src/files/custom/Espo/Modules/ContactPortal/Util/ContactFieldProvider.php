<?php
declare(strict_types=1);

namespace Espo\Modules\ContactPortal\Util;

use Espo\Core\Utils\Metadata;
use Espo\Core\Utils\Language;

/**
 * Returns editable field definitions for the Contact Portal form.
 *
 * HOW TO CONTROL WHICH FIELDS APPEAR IN THE PORTAL
 * =================================================
 * Edit: src/files/custom/Espo/Modules/ContactPortal/Resources/metadata/contactPortal/Contact.json
 *
 * The "layout" key controls field order and readOnly flags:
 *   [{"name": "firstName"}, {"name": "emailAddress", "readOnly": true}, ...]
 * The "fields" key holds per-field hints:
 *   {"firstName": {"hint": "Your given names."}, ...}
 *
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
        $entries = $this->extractNamesFromMetadata();
        $fields  = [];

        foreach ($entries as $entry) {
            $name     = $entry['name'];
            $readOnly = $entry['readOnly'];

            if (in_array($name, self::EXCLUDED, true)) {
                continue;
            }

            /** @var array<string, mixed>|null $def */
            $def  = $this->metadata->get(['entityDefs', 'Contact', 'fields', $name]);
            $type = is_array($def) ? (string) ($def['type'] ?? '') : '';

            // Address composite → expand into individual sub-field entries.
            if ($type === 'address') {
                foreach ($this->addressSubFields($name) as $sub) {
                    $fields[] = $sub;
                }
                continue;
            }

            if (!array_key_exists($type, self::TYPE_MAP)) {
                continue; // silently skip link, linkMultiple, image, etc.
            }

            $inputConfig = self::TYPE_MAP[$type];
            $label       = $this->resolveLabel($name, is_array($def) ? $def : []);
            $hint        = $entry['hint'];

            $fields[] = [
                'name'         => $name,
                'label'        => $label,
                'hint'         => $hint,
                'readOnly'     => $readOnly,
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
     * Reads the ordered field list from metadata/contactPortal/Contact.json ("fields" array).
     *
     * Each entry is an object with required "name" and optional "hint", "readOnly":
     *   [{"name": "firstName", "hint": "Your given names."},
     *    {"name": "emailAddress", "readOnly": true, "hint": "..."}]
     *
     * @return list<array{name: string, readOnly: bool, hint: string}>
     */
    private function extractNamesFromMetadata(): array
    {
        $raw = $this->metadata->get(['contactPortal', 'Contact', 'fields']);

        if (!is_array($raw) || empty($raw)) {
            return [];
        }

        $entries = [];
        $seen    = [];

        foreach ($raw as $item) {
            if (!is_array($item) || !isset($item['name']) || $item['name'] === '') {
                continue;
            }
            $n = (string) $item['name'];
            if (in_array($n, $seen, true)) {
                continue;
            }
            $seen[]    = $n;
            $entries[] = [
                'name'     => $n,
                'readOnly' => !empty($item['readOnly']),
                'hint'     => (string) ($item['hint'] ?? ''),
            ];
        }

        return $entries;
    }

    /**
     * Expands an 'address' composite field into 5 individual sub-field definitions.
     * EspoCRM stores these as addressStreet, addressCity, etc. on the entity.
     *
     * @return list<array<string, mixed>>
     */
    private function addressSubFields(string $fieldName): array
    {
        return [
            ['name' => $fieldName . 'Street',     'label' => 'Street',      'hint' => '', 'readOnly' => false, 'inputType' => 'text', 'originalType' => 'varchar', 'required' => false, 'maxLength' => 255, 'options' => null, 'step' => null, 'accept' => null, 'maxFileSize' => null, 'maxCount' => null],
            ['name' => $fieldName . 'City',       'label' => 'City',        'hint' => '', 'readOnly' => false, 'inputType' => 'text', 'originalType' => 'varchar', 'required' => false, 'maxLength' => 100, 'options' => null, 'step' => null, 'accept' => null, 'maxFileSize' => null, 'maxCount' => null],
            ['name' => $fieldName . 'State',      'label' => 'State',       'hint' => '', 'readOnly' => false, 'inputType' => 'text', 'originalType' => 'varchar', 'required' => false, 'maxLength' => 100, 'options' => null, 'step' => null, 'accept' => null, 'maxFileSize' => null, 'maxCount' => null],
            ['name' => $fieldName . 'PostalCode', 'label' => 'Postal code', 'hint' => '', 'readOnly' => false, 'inputType' => 'text', 'originalType' => 'varchar', 'required' => false, 'maxLength' => 40,  'options' => null, 'step' => null, 'accept' => null, 'maxFileSize' => null, 'maxCount' => null],
            ['name' => $fieldName . 'Country',    'label' => 'Country',     'hint' => '', 'readOnly' => false, 'inputType' => 'text', 'originalType' => 'varchar', 'required' => false, 'maxLength' => 100, 'options' => null, 'step' => null, 'accept' => null, 'maxFileSize' => null, 'maxCount' => null],
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
            ['name' => 'firstName',    'label' => 'First Name', 'hint' => '', 'readOnly' => false, 'inputType' => 'text',  'originalType' => 'varchar', 'required' => true,  'maxLength' => 100, 'options' => null, 'step' => null, 'accept' => null, 'maxFileSize' => null, 'maxCount' => null],
            ['name' => 'lastName',     'label' => 'Last Name',  'hint' => '', 'readOnly' => false, 'inputType' => 'text',  'originalType' => 'varchar', 'required' => true,  'maxLength' => 100, 'options' => null, 'step' => null, 'accept' => null, 'maxFileSize' => null, 'maxCount' => null],
            ['name' => 'emailAddress', 'label' => 'Email',      'hint' => '', 'readOnly' => false, 'inputType' => 'email', 'originalType' => 'email',   'required' => true,  'maxLength' => 254, 'options' => null, 'step' => null, 'accept' => null, 'maxFileSize' => null, 'maxCount' => null],
            ['name' => 'phoneNumber',  'label' => 'Phone',      'hint' => '', 'readOnly' => false, 'inputType' => 'tel',   'originalType' => 'phone',   'required' => false, 'maxLength' => 50,  'options' => null, 'step' => null, 'accept' => null, 'maxFileSize' => null, 'maxCount' => null],
        ];
    }
}
