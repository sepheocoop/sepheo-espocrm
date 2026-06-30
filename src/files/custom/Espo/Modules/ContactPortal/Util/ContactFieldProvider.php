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
 * "editFormFields" — ordered array of objects controlling the edit form:
 *   [{"name": "firstName", "hint": "Your given names."},
 *    {"name": "emailAddress", "readOnly": true},
 *    {"name": "cMatrixID", "required": true, "hint": "..."}]
 *
 * "registrationFields" — ordered array for the registration form. Each entry
 *   is either a plain name string or an object with per-field overrides:
 *   ["firstName", {"name": "emailAddress", "required": true}, ...]
 *
 * Add a name → it appears. Remove it → it disappears.
 * Then Admin → Clear Cache. No PHP changes needed.
 *
 * SUPPORTED FIELD TYPES (rendered automatically):
 *   varchar, email, phone, url, int, float, currency,
 *   date, datetime, bool, text, enum, multiEnum, urlMultiple, address
 *
 * AUTOMATICALLY SKIPPED (cannot render in plain HTML form): FIXME - consequences?
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
        'id',
        'name',
        'createdAt',
        'modifiedAt',
        'createdBy',
        'modifiedBy',
        'deleted',
        'portalToken',
        'portalTokenExpiry',
        'salutationName',
        'assignedUser',
        'assignedUsers',
    ];

    /**
     * Maps EspoCRM field types to HTML input config.
     * 'address' is expanded into 5 sub-fields rather than a single input.
     *
     * @var array<string, array{type: string, step?: string}>
     */
    private const TYPE_MAP = [
        'varchar' => ['type' => 'text'],
        'email' => ['type' => 'email'],
        'phone' => ['type' => 'tel'],
        'url' => ['type' => 'url'],
        'int' => ['type' => 'number'],
        'float' => ['type' => 'number', 'step' => 'any'],
        'currency' => ['type' => 'number', 'step' => '0.01'],
        'date' => ['type' => 'date'],
        'datetime' => ['type' => 'datetime-local'],
        'bool' => ['type' => 'checkbox'],
        'text' => ['type' => 'textarea'],
        'enum' => ['type' => 'select'],
        'multiEnum' => ['type' => 'multiselect'], // rendered as grouped checkboxes
        'urlMultiple' => ['type' => 'url'], // simplified: first URL only
        'address' => ['type' => 'address'], // composite — expanded below
        'attachmentMultiple' => ['type' => 'file'], // file upload
    ];

    public function __construct(
        private readonly Metadata $metadata,
        private readonly Language $language,
    ) {}

    /**
     * Returns ordered field definitions for the portal edit form.
     *
     * @return list<array<string, mixed>>
     */
    public function getFields(): array
    {
        return $this->buildFields($this->extractNamesFromMetadata());
    }

    /**
     * Returns ordered field definitions for the registration form.
     * Field order comes from the "registrationFields" key in contactPortal/Contact.json.
     * Hints and required overrides are looked up from the "editFormFields" array.
     *
     * @return list<array<string, mixed>>
     */
    public function getRegistrationFields(): array
    {
        return $this->buildFields($this->extractRegistrationEntries());
    }

    // -------------------------------------------------------------------------

    /**
     * When an upload exceeds PHP's post_max_size, PHP silently empties both
     * $_POST and $_FILES before any application code runs. This method detects
     * that case and returns a ready-made fieldErrors map pointing at the first
     * file field (or '_form' if there is none), or null if the body is intact.
     *
     * @param list<array<string, mixed>> $fields
     * @return array<string, string>|null
     */
    public function truncationErrors(array $fields): ?array
    {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength === 0 || !empty($_POST) || !empty($_FILES)) {
            return null;
        }

        $fileField = null;
        foreach ($fields as $f) {
            if ($f['inputType'] === 'file') {
                $fileField = $f['name'];
                break;
            }
        }

        return [
            $fileField ??
            '_form' => 'The uploaded file is too large. Please try a smaller file.',
        ];
    }

    /**
     * Reads submitted form values from $_POST for the given field list.
     * File fields are intentionally skipped (handled separately via $_FILES).
     * The values returned are one of: booleans, strings, or arrays (of strings).
     *
     * @param list<array<string, mixed>> $fields
     * @return array<string, mixed>
     */
    public function sanitise(array $fields): array
    {
        $post = $_POST;
        $out = [];

        foreach ($fields as $field) {
            $name = $field['name'];
            $inputType = $field['inputType'];

            if ($field['readOnly'] ?? false) {
                continue;
            }

            if ($inputType === 'file') {
                continue;
            }

            if ($inputType === 'checkbox') {
                $out[$name] = !empty($post[$name]); // bool
            } elseif ($inputType === 'multiselect') {
                // array
                $raw = $post[$name] ?? null;
                if (is_array($raw)) {
                    $out[$name] = array_map(
                        fn($v) => strip_tags((string) $v),
                        $raw,
                    );
                } elseif ($raw !== null && $raw !== '') {
                    $out[$name] = [strip_tags((string) $raw)];
                } else {
                    $out[$name] = [];
                }
            } else {
                // string
                $out[$name] = trim(strip_tags((string) ($post[$name] ?? '')));
            }
        }

        return $out;
    }

    /**
     * Validates sanitised input against a field list.
     * Returns a map of field name → error message for every failing field.
     * An empty array means all fields are valid.
     *
     * @param array<string, mixed>       $input
     * @param list<array<string, mixed>> $fields
     * @return array<string, string>
     */
    public function validate(array $input, array $fields): array
    {
        $errors = [];

        foreach ($fields as $field) {
            $name = $field['name'];
            $label = $field['label'];
            $inputType = $field['inputType'];
            $value = $input[$name] ?? ($inputType === 'multiselect' ? [] : '');

            if ($inputType === 'checkbox') {
                continue;
            }

            if ($inputType === 'file') {
                $fileInfo = $_FILES[$name] ?? null;
                if ($fileInfo === null) {
                    continue;
                }

                $uploadError = $fileInfo['error'] ?? UPLOAD_ERR_NO_FILE;

                if ($uploadError === UPLOAD_ERR_NO_FILE) {
                    continue;
                }

                if (
                    $uploadError === UPLOAD_ERR_INI_SIZE ||
                    $uploadError === UPLOAD_ERR_FORM_SIZE
                ) {
                    $errors[
                        $name
                    ] = "{$label} is too large. Please try a smaller file.";
                    continue;
                }

                if ($uploadError !== UPLOAD_ERR_OK) {
                    $errors[
                        $name
                    ] = "Upload error for {$label} (code {$uploadError}).";
                    continue;
                }

                if (!is_uploaded_file((string) $fileInfo['tmp_name'])) {
                    $errors[$name] = "Invalid file upload for {$label}.";
                    continue;
                }

                $sizeMb = ((int) ($fileInfo['size'] ?? 0)) / (1024 * 1024);
                if (
                    $field['maxFileSize'] !== null &&
                    $sizeMb > (float) $field['maxFileSize']
                ) {
                    $errors[
                        $name
                    ] = "{$label} exceeds the maximum allowed size of {$field['maxFileSize']} MB.";
                    continue;
                }

                $accept = (array) ($field['accept'] ?? []);
                if (!empty($accept)) {
                    $ext =
                        '.' .
                        strtolower(
                            pathinfo(
                                basename((string) ($fileInfo['name'] ?? '')),
                                PATHINFO_EXTENSION,
                            ),
                        );
                    $lowerAccept = array_map('strtolower', $accept);
                    if (!in_array($ext, $lowerAccept, true)) {
                        $errors[$name] =
                            "{$label}: file type not allowed. Accepted: " .
                            implode(', ', $accept) .
                            '.';
                    }
                }

                continue;
            }

            if ($inputType === 'multiselect') {
                if ($field['required'] && empty($value)) {
                    $errors[$name] = "{$label} is required.";
                } elseif ($field['options'] !== null && is_array($value)) {
                    foreach ($value as $v) {
                        if (!in_array($v, $field['options'], true)) {
                            $errors[
                                $name
                            ] = "{$label} contains an invalid value.";
                            break;
                        }
                    }
                }
                continue;
            }

            if ($field['required'] && (string) $value === '') {
                $errors[$name] = "{$label} is required.";
                continue;
            }

            if (
                $inputType === 'email' &&
                $value !== '' &&
                !filter_var($value, FILTER_VALIDATE_EMAIL)
            ) {
                $errors[$name] = "{$label} must be a valid email address.";
                continue;
            }

            if (
                $field['maxLength'] !== null &&
                is_string($value) &&
                strlen($value) > $field['maxLength']
            ) {
                $errors[
                    $name
                ] = "{$label} must not exceed {$field['maxLength']} characters.";
                continue;
            }

            if (
                $inputType === 'select' &&
                $value !== '' &&
                $field['options'] !== null &&
                !in_array($value, $field['options'], true)
            ) {
                $errors[$name] = "{$label} contains an invalid value.";
            }
        }

        return $errors;
    }

    // -------------------------------------------------------------------------

    /**
     * Core field-building logic: resolves EspoCRM metadata for each entry and
     * returns a typed field array ready for the form renderer.
     *
     * @param list<array{name: string, readOnly: bool, hint: string, required: bool|null}> $entries
     * @return list<array<string, mixed>>
     */
    private function buildFields(array $entries): array
    {
        $fields = [];

        foreach ($entries as $entry) {
            $name = $entry['name'];
            $readOnly = $entry['readOnly'];

            if (in_array($name, self::EXCLUDED, true)) {
                continue;
            }

            /** @var array<string, mixed>|null $def */
            $def = $this->metadata->get([
                'entityDefs',
                'Contact',
                'fields',
                $name,
            ]);
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
            $label = $this->resolveLabel($name, is_array($def) ? $def : []);
            $hint = $entry['hint'];

            $fields[] = [
                'name' => $name,
                'label' => $label,
                'hint' => $hint,
                'readOnly' => $readOnly,
                'inputType' => $inputConfig['type'],
                'originalType' => $type,
                // "required" comes exclusively from the portal config (editFormFields).
                'required' => (bool) ($entry['required'] ?? false),
                'maxLength' => isset($def['maxLength'])
                    ? (int) $def['maxLength']
                    : null,
                'options' => in_array($type, ['enum', 'multiEnum'])
                    ? array_values(
                        array_map('strval', (array) ($def['options'] ?? [])),
                    )
                    : null,
                'step' => $inputConfig['step'] ?? null,
                'accept' =>
                    $type === 'attachmentMultiple'
                        ? array_values(
                            array_map('strval', (array) ($def['accept'] ?? [])),
                        )
                        : null,
                'maxFileSize' =>
                    $type === 'attachmentMultiple' && isset($def['maxFileSize'])
                        ? (int) $def['maxFileSize']
                        : null,
                'maxCount' =>
                    $type === 'attachmentMultiple' && isset($def['maxCount'])
                        ? (int) $def['maxCount']
                        : null,
            ];
        }

        return $fields ?: $this->fallback();
    }

    /**
     * Reads registration field order from the "registrationFields" string array
     * in contactPortal/Contact.json. Hints are resolved from the main "fields" array.
     *
     * @return list<array{name: string, readOnly: bool, hint: string}>
     */
    private function extractRegistrationEntries(): array
    {
        $regLayout = $this->metadata->get([
            'contactPortal',
            'Contact',
            'registrationFields',
        ]);

        if (!is_array($regLayout) || empty($regLayout)) {
            return $this->extractNamesFromMetadata(); // fallback to full list
        }

        // Build name → item map from editFormFields for hint/required lookup.
        $fieldsRaw =
            $this->metadata->get([
                'contactPortal',
                'Contact',
                'editFormFields',
            ]) ?? [];
        $fieldMap = [];
        foreach ((array) $fieldsRaw as $item) {
            if (is_array($item) && isset($item['name'])) {
                $fieldMap[(string) $item['name']] = $item;
            }
        }

        $entries = [];
        $seen = [];

        foreach ($regLayout as $entry) {
            // Accept both plain strings and {"name": "...", "readOnly": true} objects.
            $name = is_string($entry)
                ? $entry
                : (string) ($entry['name'] ?? '');
            if ($name === '' || in_array($name, $seen, true)) {
                continue;
            }
            $seen[] = $name;
            $base = $fieldMap[$name] ?? [];
            $entries[] = [
                'name' => $name,
                'readOnly' => is_array($entry)
                    ? !empty($entry['readOnly'])
                    : false,
                'hint' =>
                    is_array($entry) && isset($entry['hint'])
                        ? (string) $entry['hint']
                        : (string) ($base['hint'] ?? ''),
                // registrationFields entry "required" wins; falls back to editFormFields, then entityDefs.
                'required' =>
                    is_array($entry) && isset($entry['required'])
                        ? (bool) $entry['required']
                        : (isset($base['required'])
                            ? (bool) $base['required']
                            : null),
            ];
        }

        return $entries ?: $this->extractNamesFromMetadata();
    }

    // -------------------------------------------------------------------------

    /**
     * Reads the ordered field list from metadata/contactPortal/Contact.json ("editFormFields" array).
     *
     * Each entry: required "name", optional "hint", "readOnly", "required":
     *   [{"name": "firstName", "hint": "Your given names."},
     *    {"name": "emailAddress", "required": true},
     *    {"name": "cWebsite", "readOnly": true}]
     *
     * @return list<array{name: string, readOnly: bool, hint: string, required: bool|null}>
     */
    private function extractNamesFromMetadata(): array
    {
        $raw = $this->metadata->get([
            'contactPortal',
            'Contact',
            'editFormFields',
        ]);

        if (!is_array($raw) || empty($raw)) {
            return [];
        }

        $entries = [];
        $seen = [];

        foreach ($raw as $item) {
            if (
                !is_array($item) ||
                !isset($item['name']) ||
                $item['name'] === ''
            ) {
                continue;
            }
            $n = (string) $item['name'];
            if (in_array($n, $seen, true)) {
                continue;
            }
            $seen[] = $n;
            $entries[] = [
                'name' => $n,
                'readOnly' => !empty($item['readOnly']),
                'hint' => (string) ($item['hint'] ?? ''),
                'required' => isset($item['required'])
                    ? (bool) $item['required']
                    : null,
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
            [
                'name' => $fieldName . 'Street',
                'label' => 'Street',
                'hint' => '',
                'readOnly' => false,
                'inputType' => 'text',
                'originalType' => 'varchar',
                'required' => false,
                'maxLength' => 255,
                'options' => null,
                'step' => null,
                'accept' => null,
                'maxFileSize' => null,
                'maxCount' => null,
            ],
            [
                'name' => $fieldName . 'City',
                'label' => 'City',
                'hint' => '',
                'readOnly' => false,
                'inputType' => 'text',
                'originalType' => 'varchar',
                'required' => false,
                'maxLength' => 100,
                'options' => null,
                'step' => null,
                'accept' => null,
                'maxFileSize' => null,
                'maxCount' => null,
            ],
            [
                'name' => $fieldName . 'State',
                'label' => 'State',
                'hint' => '',
                'readOnly' => false,
                'inputType' => 'text',
                'originalType' => 'varchar',
                'required' => false,
                'maxLength' => 100,
                'options' => null,
                'step' => null,
                'accept' => null,
                'maxFileSize' => null,
                'maxCount' => null,
            ],
            [
                'name' => $fieldName . 'PostalCode',
                'label' => 'Postal code',
                'hint' => '',
                'readOnly' => false,
                'inputType' => 'text',
                'originalType' => 'varchar',
                'required' => false,
                'maxLength' => 40,
                'options' => null,
                'step' => null,
                'accept' => null,
                'maxFileSize' => null,
                'maxCount' => null,
            ],
            [
                'name' => $fieldName . 'Country',
                'label' => 'Country',
                'hint' => '',
                'readOnly' => false,
                'inputType' => 'text',
                'originalType' => 'varchar',
                'required' => false,
                'maxLength' => 100,
                'options' => null,
                'step' => null,
                'accept' => null,
                'maxFileSize' => null,
                'maxCount' => null,
            ],
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
                'name' => 'lastName',
                'label' => 'Last Name',
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
            [
                'name' => 'phoneNumber',
                'label' => 'Phone',
                'hint' => '',
                'readOnly' => false,
                'inputType' => 'tel',
                'originalType' => 'phone',
                'required' => false,
                'maxLength' => 50,
                'options' => null,
                'step' => null,
                'accept' => null,
                'maxFileSize' => null,
                'maxCount' => null,
            ],
        ];
    }
}
