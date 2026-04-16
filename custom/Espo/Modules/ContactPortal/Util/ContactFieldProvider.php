<?php
declare(strict_types=1);

namespace Espo\Modules\ContactPortal\Util;

use Espo\Tools\Layout\LayoutProvider;
use Espo\Core\Utils\Metadata;
use Espo\Core\Utils\Language;

/**
 * Reads the Contact "detail" layout from EspoCRM and returns structured field
 * definitions for the Contact Portal form.
 *
 * To change which fields are shown — and in what order — go to:
 *   EspoCRM Admin → Entity Manager → Contact → Layouts → Detail
 * Add, remove, or reorder fields there, then do Admin → Clear Cache.
 * No code changes required.
 */
class ContactFieldProvider
{
    /**
     * Fields that must never appear in the portal form regardless of the
     * layout (system / token fields / complex relation fields).
     */
    private const EXCLUDED = [
        'id', 'name', 'createdAt', 'modifiedAt', 'createdBy', 'modifiedBy',
        'deleted', 'portalToken', 'portalTokenExpiry', 'salutationName',
        'description',  // textarea that may contain sensitive history
    ];

    /**
     * Maps EspoCRM field types to HTML rendering config.
     * Types NOT in this map are skipped (link, linkMultiple, image, file, …).
     *
     * @var array<string, array{type: string, step?: string}>
     */
    private const TYPE_MAP = [
        'varchar'        => ['type' => 'text'],
        'email'          => ['type' => 'email'],
        'phone'          => ['type' => 'tel'],
        'url'            => ['type' => 'url'],
        'int'            => ['type' => 'number'],
        'float'          => ['type' => 'number', 'step' => 'any'],
        'currency'       => ['type' => 'number', 'step' => '0.01'],
        'date'           => ['type' => 'date'],
        'datetime'       => ['type' => 'datetime-local'],
        'bool'           => ['type' => 'checkbox'],
        'text'           => ['type' => 'textarea'],
        'enum'           => ['type' => 'select'],
    ];

    public function __construct(
        private readonly LayoutProvider $layout,
        private readonly Metadata $metadata,
        private readonly Language $language,
    ) {}

    /**
     * Returns ordered field definitions from the Contact detail layout.
     * Falls back to a sensible default set if the layout cannot be read.
     *
     * Each entry:
     *   name      – EspoCRM field name (camelCase)
     *   label     – Translated display label from EspoCRM i18n
     *   inputType – HTML input type, or 'textarea' / 'select'
     *   required  – Whether the field is required
     *   maxLength – Character limit, or null
     *   options   – List of allowed values for 'select' fields, or null
     *   step      – HTML step attribute for number inputs, or null
     *
     * @return list<array{name: string, label: string, inputType: string, required: bool, maxLength: int|null, options: list<string>|null, step: string|null}>
     */
    public function getFields(): array
    {
        $names  = $this->extractNamesFromLayout();
        $fields = [];

        foreach ($names as $name) {
            if (in_array($name, self::EXCLUDED, true)) {
                continue;
            }

            /** @var array<string, mixed>|null $def */
            $def = $this->metadata->get(['entityDefs', 'Contact', 'fields', $name]);

            if (!$def) {
                continue;
            }

            $type = (string) ($def['type'] ?? '');

            if (!array_key_exists($type, self::TYPE_MAP)) {
                continue; // skip link, linkMultiple, image, jsonArray, etc.
            }

            $inputConfig = self::TYPE_MAP[$type];
            $label       = $this->language->translate($name, 'fields', 'Contact');

            $fields[] = [
                'name'      => $name,
                'label'     => $label,
                'inputType' => $inputConfig['type'],
                'required'  => !empty($def['required']),
                'maxLength' => isset($def['maxLength']) ? (int) $def['maxLength'] : null,
                'options'   => $type === 'enum'
                    ? array_values(array_map('strval', (array) ($def['options'] ?? [])))
                    : null,
                'step'      => $inputConfig['step'] ?? null,
            ];
        }

        return $fields ?: $this->fallback();
    }

    /**
     * Parses the Contact detail layout and returns field names in layout order.
     *
     * @return list<string>
     */
    private function extractNamesFromLayout(): array
    {
        $json = $this->layout->get('Contact', 'detail');  // LayoutProvider::get(scope, name)

        if ($json === null) {
            return [];
        }

        /** @var list<array<string, mixed>>|null $panels */
        $panels = json_decode($json, true);

        if (!is_array($panels)) {
            return [];
        }

        $names = [];

        foreach ($panels as $panel) {
            foreach ((array) ($panel['rows'] ?? []) as $row) {
                foreach ((array) $row as $cell) {
                    // Cells are either an associative array with 'name', or false (empty slot).
                    if (is_array($cell) && isset($cell['name']) && $cell['name'] !== '') {
                        $names[] = (string) $cell['name'];
                    }
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Safe hardcoded defaults used when the EspoCRM layout cannot be read.
     *
     * @return list<array<string, mixed>>
     */
    private function fallback(): array
    {
        return [
            ['name' => 'firstName',    'label' => 'First Name',    'inputType' => 'text',  'required' => true,  'maxLength' => 100, 'options' => null, 'step' => null],
            ['name' => 'lastName',     'label' => 'Last Name',     'inputType' => 'text',  'required' => true,  'maxLength' => 100, 'options' => null, 'step' => null],
            ['name' => 'title',        'label' => 'Title',         'inputType' => 'text',  'required' => false, 'maxLength' => 100, 'options' => null, 'step' => null],
            ['name' => 'emailAddress', 'label' => 'Email',         'inputType' => 'email', 'required' => true,  'maxLength' => 254, 'options' => null, 'step' => null],
            ['name' => 'phoneNumber',  'label' => 'Phone',         'inputType' => 'tel',   'required' => false, 'maxLength' => 50,  'options' => null, 'step' => null],
        ];
    }
}
