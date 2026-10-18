<?php
declare(strict_types=1);

namespace Lumui\Protocol;

use RuntimeException;

final class DescriptorValidator
{
    
    private $schemaValidator;

    public function __construct(?string $schemaPath = null)
    {
        $path = $schemaPath ?? SpecificationResources::file(
            'schemas/service-descriptor.schema.json'
        );
        $schema = json_decode((string) file_get_contents($path), true);
        if (!is_array($schema)) {
            throw new RuntimeException('The LUMUI service descriptor schema is unavailable.');
        }
        $this->schemaValidator = new JsonSchemaValidator($schema);
    }

    
    public function validate(array $descriptor): void
    {
        $this->schemaValidator->validate($descriptor, 'Descriptor');

        $this->link($descriptor['surface'], 'surface', MediaTypes::LUMUI_JSON);
        $this->link($descriptor['fallback'], 'fallback', MediaTypes::HTML);
        if (isset($descriptor['actions'])) {
            $this->link($descriptor['actions'], 'actions', MediaTypes::LUMUI_JSON);
        }
        if (isset($descriptor['route_surface'])) {
            if (strpos($descriptor['route_surface']['template'], '{path}') === false) {
                throw new RuntimeException('Descriptor route_surface requires a template containing {path}.');
            }
            $this->safeReference($descriptor['route_surface']['template']);
        }
        foreach (($descriptor['schemas'] ?? []) as $name => $href) {
            $this->safeReference($href);
        }
        if (isset($descriptor['publisher']['url'])) {
            $this->safeReference($descriptor['publisher']['url']);
        }
        if (isset($descriptor['documentation'])) {
            $this->link($descriptor['documentation'], 'documentation', null);
        }
        foreach (['login', 'logout'] as $field) {
            if (isset($descriptor['authentication'][$field])) {
                $this->safeReference($descriptor['authentication'][$field]);
            }
        }
        foreach (($descriptor['origin_policy']['allowed_origins'] ?? []) as $origin) {
            $this->safeReference($origin);
        }
        if (isset($descriptor['protocol'])) {
            if (
                $descriptor['protocol']['surface'] !== '1.0'
                || $descriptor['protocol']['message'] !== '1.0'
            ) {
                throw new RuntimeException('Descriptor protocol versions are unsupported.');
            }
        }
        if (isset($descriptor['discovery']['well_known'])) {
            $this->safeReference($descriptor['discovery']['well_known']);
        }
        if (isset($descriptor['specification']['href'])) {
            $this->safeReference($descriptor['specification']['href']);
        }
    }

    
    private function link($value, string $field, ?string $expectedType): void
    {
        $this->safeReference($value['href']);
        if (
            $expectedType !== null
            && strtolower((string) $value['type']) !== strtolower($expectedType)
        ) {
            throw new RuntimeException("Descriptor link '{$field}' has an unexpected media type.");
        }
    }

    private function safeReference(string $href): void
    {
        if (trim($href) === '' || preg_match('/[\x00-\x1F\x7F]/', $href) === 1) {
            throw new RuntimeException('A descriptor URL is invalid.');
        }
        $scheme = parse_url($href, PHP_URL_SCHEME);
        if (
            is_string($scheme)
            && !in_array(strtolower($scheme), ['http', 'https'], true)
        ) {
            throw new RuntimeException("A descriptor URL uses unsupported scheme '{$scheme}'.");
        }
    }

}
