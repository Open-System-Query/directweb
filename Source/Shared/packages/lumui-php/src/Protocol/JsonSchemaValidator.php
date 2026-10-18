<?php
declare(strict_types=1);

namespace Lumui\Protocol;

use RuntimeException;

final class JsonSchemaValidator
{
    private const MAXIMUM_DEPTH = 64;
    private const MAXIMUM_ERRORS = 100;

    
    private $rootSchema;

    
    public function __construct(array $rootSchema)
    {
        $this->rootSchema = $rootSchema;
    }

    public static function fromFile(string $path): self
    {
        $content = file_get_contents($path);
        $schema = is_string($content) ? json_decode($content, true) : null;
        if (!is_array($schema) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("The JSON Schema document '{$path}' is unavailable.");
        }
        return new self($schema);
    }

    
    public function validate($value, string $path = '$'): void
    {
        $errors = $this->errors($value, $path);
        if ($errors !== []) {
            throw new RuntimeException(
                "Schema validation failed at {$errors[0]['path']}: {$errors[0]['message']}"
            );
        }
    }

    
    public function errors($value, string $path = '$'): array
    {
        $errors = [];
        $this->collectErrors($value, $this->rootSchema, $path, $errors);
        return $errors;
    }

    
    private function collectErrors(
        $value,
        array $schema,
        string $path,
        array &$errors,
        int $depth = 0
    ): void {
        if (count($errors) >= self::MAXIMUM_ERRORS) {
            return;
        }
        if ($depth > self::MAXIMUM_DEPTH) {
            $this->addError($errors, $path, 'the value exceeds the validation depth limit.');
            return;
        }

        if (isset($schema['$ref'])) {
            if (!is_string($schema['$ref'])) {
                throw new RuntimeException('A JSON Schema reference must be a string.');
            }
            $this->collectErrors(
                $value,
                $this->resolveReference($schema['$ref']),
                $path,
                $errors,
                $depth + 1
            );
            if (count($errors) >= self::MAXIMUM_ERRORS) {
                return;
            }
        }

        if (is_array($schema['allOf'] ?? null)) {
            foreach ($schema['allOf'] as $candidate) {
                if (!is_array($candidate)) {
                    throw new RuntimeException('A JSON Schema allOf entry must be an object.');
                }
                $this->collectErrors($value, $candidate, $path, $errors, $depth + 1);
            }
        }

        if (is_array($schema['anyOf'] ?? null)) {
            $matches = $this->matchingBranches($value, $schema['anyOf'], $path, $depth);
            if ($matches === 0) {
                $this->addError($errors, $path, 'the value does not match an allowed form.');
                return;
            }
        }

        if (is_array($schema['oneOf'] ?? null)) {
            $matches = $this->matchingBranches($value, $schema['oneOf'], $path, $depth);
            $emptyObjectOrArray = $value === [] && $matches > 0;
            if ($matches !== 1 && !$emptyObjectOrArray) {
                $this->addError($errors, $path, 'the value does not match exactly one allowed form.');
                return;
            }
        }

        if (is_array($schema['if'] ?? null)) {
            $conditionErrors = [];
            $this->collectErrors($value, $schema['if'], $path, $conditionErrors, $depth + 1);
            $branch = $conditionErrors === [] ? ($schema['then'] ?? null) : ($schema['else'] ?? null);
            if (is_array($branch)) {
                $this->collectErrors($value, $branch, $path, $errors, $depth + 1);
            }
        }

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            $this->addError($errors, $path, 'the value is not the defined constant.');
            return;
        }
        if (is_array($schema['enum'] ?? null) && !in_array($value, $schema['enum'], true)) {
            $this->addError($errors, $path, 'the value is not in the defined set.');
            return;
        }

        $types = $this->types($schema['type'] ?? null);
        if ($types !== [] && !$this->matchesAnyType($value, $types)) {
            $this->addError(
                $errors,
                $path,
                'expected ' . implode(' or ', $types) . '.'
            );
            return;
        }

        if (is_string($value)) {
            $this->validateString($value, $schema, $path, $errors);
        }
        if (is_int($value) || is_float($value)) {
            $this->validateNumber($value, $schema, $path, $errors);
        }

        if (!is_array($value)) {
            return;
        }

        $expectsArray = in_array('array', $types, true)
            || ($types === [] && array_key_exists('items', $schema));
        $expectsObject = in_array('object', $types, true)
            || (
                $types === []
                && (
                    array_key_exists('properties', $schema)
                    || array_key_exists('additionalProperties', $schema)
                    || array_key_exists('required', $schema)
                    || array_key_exists('propertyNames', $schema)
                )
            );

        if ($expectsArray && !$expectsObject) {
            $this->validateArray($value, $schema, $path, $errors, $depth);
            return;
        }
        if ($expectsObject && !$expectsArray) {
            $this->validateObject($value, $schema, $path, $errors, $depth);
            return;
        }
        if ($this->isList($value)) {
            $this->validateArray($value, $schema, $path, $errors, $depth);
            return;
        }
        $this->validateObject($value, $schema, $path, $errors, $depth);
    }

    
    private function matchingBranches($value, array $branches, string $path, int $depth): int
    {
        $matches = 0;
        foreach ($branches as $branch) {
            if (!is_array($branch)) {
                throw new RuntimeException('A JSON Schema branch must be an object.');
            }
            $branchErrors = [];
            $this->collectErrors($value, $branch, $path, $branchErrors, $depth + 1);
            if ($branchErrors === []) {
                $matches++;
            }
        }
        return $matches;
    }

    
    private function validateString(
        string $value,
        array $schema,
        string $path,
        array &$errors
    ): void {
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if (isset($schema['minLength']) && $length < (int) $schema['minLength']) {
            $this->addError($errors, $path, 'the text is shorter than allowed.');
        }
        if (isset($schema['maxLength']) && $length > (int) $schema['maxLength']) {
            $this->addError($errors, $path, 'the text is longer than allowed.');
        }
        if (is_string($schema['pattern'] ?? null)) {
            $pattern = '~' . str_replace('~', '\~', $schema['pattern']) . '~u';
            $match = @preg_match($pattern, $value);
            if ($match === false) {
                throw new RuntimeException("The JSON Schema pattern at '{$path}' is invalid.");
            }
            if ($match !== 1) {
                $this->addError($errors, $path, 'the text does not match the defined pattern.');
            }
        }
        if (
            is_string($schema['format'] ?? null)
            && !$this->matchesFormat($value, $schema['format'])
        ) {
            $this->addError(
                $errors,
                $path,
                "the text is not a valid {$schema['format']} value."
            );
        }
    }

    
    private function validateNumber($value, array $schema, string $path, array &$errors): void
    {
        if (isset($schema['minimum']) && $value < $schema['minimum']) {
            $this->addError($errors, $path, 'the number is below the minimum.');
        }
        if (isset($schema['maximum']) && $value > $schema['maximum']) {
            $this->addError($errors, $path, 'the number is above the maximum.');
        }
        if (isset($schema['exclusiveMinimum']) && $value <= $schema['exclusiveMinimum']) {
            $this->addError($errors, $path, 'the number must be above the exclusive minimum.');
        }
        if (isset($schema['exclusiveMaximum']) && $value >= $schema['exclusiveMaximum']) {
            $this->addError($errors, $path, 'the number must be below the exclusive maximum.');
        }
    }

    
    private function validateArray(
        array $value,
        array $schema,
        string $path,
        array &$errors,
        int $depth
    ): void {
        if (isset($schema['minItems']) && count($value) < (int) $schema['minItems']) {
            $this->addError($errors, $path, 'the list contains too few items.');
        }
        if (isset($schema['maxItems']) && count($value) > (int) $schema['maxItems']) {
            $this->addError($errors, $path, 'the list contains too many items.');
        }
        if (($schema['uniqueItems'] ?? false) === true) {
            $encoded = [];
            foreach ($value as $item) {
                $encoded[] = (string) json_encode(
                    $this->canonicalValue($item),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
            }
            if (count($encoded) !== count(array_unique($encoded))) {
                $this->addError($errors, $path, 'the list contains duplicate items.');
            }
        }
        if (is_array($schema['items'] ?? null)) {
            foreach (array_values($value) as $index => $item) {
                $this->collectErrors(
                    $item,
                    $schema['items'],
                    $path . '[' . $index . ']',
                    $errors,
                    $depth + 1
                );
            }
        }
    }

    
    private function validateObject(
        array $value,
        array $schema,
        string $path,
        array &$errors,
        int $depth
    ): void {
        if (isset($schema['minProperties']) && count($value) < (int) $schema['minProperties']) {
            $this->addError($errors, $path, 'the object contains too few fields.');
        }
        if (isset($schema['maxProperties']) && count($value) > (int) $schema['maxProperties']) {
            $this->addError($errors, $path, 'the object contains too many fields.');
        }

        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        foreach (($schema['required'] ?? []) as $field) {
            if (is_string($field) && !array_key_exists($field, $value)) {
                $this->addError($errors, $path . '.' . $field, 'the field is required.');
            }
        }

        foreach ($value as $field => $fieldValue) {
            $fieldName = (string) $field;
            $fieldPath = $path . '.' . $fieldName;
            if (is_array($schema['propertyNames'] ?? null)) {
                $this->collectErrors(
                    $fieldName,
                    $schema['propertyNames'],
                    $fieldPath,
                    $errors,
                    $depth + 1
                );
            }
            if (array_key_exists($fieldName, $properties)) {
                if (!is_array($properties[$fieldName])) {
                    throw new RuntimeException("The JSON Schema field '{$fieldPath}' is invalid.");
                }
                $this->collectErrors(
                    $fieldValue,
                    $properties[$fieldName],
                    $fieldPath,
                    $errors,
                    $depth + 1
                );
                continue;
            }

            $additional = $schema['additionalProperties'] ?? true;
            if ($additional === false) {
                $this->addError($errors, $fieldPath, 'the field is not defined.');
                continue;
            }
            if (is_array($additional)) {
                $this->collectErrors(
                    $fieldValue,
                    $additional,
                    $fieldPath,
                    $errors,
                    $depth + 1
                );
            }
        }
    }

    
    private function types($type): array
    {
        if (is_string($type)) {
            return [$type];
        }
        if (!is_array($type)) {
            return [];
        }
        $types = [];
        foreach ($type as $candidate) {
            if (is_string($candidate)) {
                $types[] = $candidate;
            }
        }
        return $types;
    }

    
    private function matchesAnyType($value, array $types): bool
    {
        foreach ($types as $type) {
            if ($this->matchesType($value, $type)) {
                return true;
            }
        }
        return false;
    }

    
    private function matchesType($value, string $type): bool
    {
        switch ($type) {
            case 'null':
                return $value === null;
            case 'string':
                return is_string($value);
            case 'boolean':
                return is_bool($value);
            case 'integer':
                return is_int($value)
                    || (
                        is_float($value)
                        && is_finite($value)
                        && floor($value) === $value
                    );
            case 'number':
                return is_int($value) || (is_float($value) && is_finite($value));
            case 'array':
                return is_array($value) && ($value === [] || $this->isList($value));
            case 'object':
                return is_array($value) && ($value === [] || !$this->isList($value));
            default:
                throw new RuntimeException("The JSON Schema type '{$type}' is unsupported.");
        }
    }

    private function matchesFormat(string $value, string $format): bool
    {
        switch ($format) {
            case 'uri-reference':
                return $this->validUriReference($value);
            case 'uri':
                return preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $value) === 1
                    && $this->validUriReference($value);
            case 'email':
                return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
            case 'date-time':
                return $this->validDateTime($value);
            default:
                throw new RuntimeException("The JSON Schema format '{$format}' is unsupported.");
        }
    }

    private function validUriReference(string $value): bool
    {
        return preg_match('/[\x00-\x20\x7F]/', $value) !== 1
            && preg_match('/%(?![0-9A-Fa-f]{2})/', $value) !== 1
            && strpos($value, '\\') === false
            && parse_url($value) !== false;
    }

    private function validDateTime(string $value): bool
    {
        $matches = [];
        if (
            preg_match(
                '/^([0-9]{4})-([0-9]{2})-([0-9]{2})T'
                . '([0-9]{2}):([0-9]{2}):([0-9]{2})'
                . '(?:\.[0-9]+)?(?:Z|[+-][0-9]{2}:[0-9]{2})$/',
                $value,
                $matches
            ) !== 1
        ) {
            return false;
        }
        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])
            && (int) $matches[4] <= 23
            && (int) $matches[5] <= 59
            && (int) $matches[6] <= 60;
    }

    
    private function resolveReference(string $reference): array
    {
        if ($reference === '#') {
            return $this->rootSchema;
        }
        if (strpos($reference, '#/') !== 0) {
            throw new RuntimeException("The JSON Schema reference '{$reference}' is unsupported.");
        }

        $value = $this->rootSchema;
        foreach (explode('/', substr($reference, 2)) as $segment) {
            $key = str_replace(['~1', '~0'], ['/', '~'], $segment);
            if (!is_array($value) || !array_key_exists($key, $value)) {
                throw new RuntimeException("The JSON Schema reference '{$reference}' cannot be resolved.");
            }
            $value = $value[$key];
        }
        if (!is_array($value)) {
            throw new RuntimeException("The JSON Schema reference '{$reference}' is not an object.");
        }
        return $value;
    }

    
    private function canonicalValue($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($this->isList($value)) {
            return array_map([$this, 'canonicalValue'], $value);
        }
        ksort($value);
        foreach ($value as $field => $fieldValue) {
            $value[$field] = $this->canonicalValue($fieldValue);
        }
        return $value;
    }

    private function isList(array $value): bool
    {
        $expectedKey = 0;
        foreach ($value as $key => $item) {
            if ($key !== $expectedKey) {
                return false;
            }
            $expectedKey++;
        }

        return true;
    }

    
    private function addError(array &$errors, string $path, string $message): void
    {
        if (count($errors) < self::MAXIMUM_ERRORS) {
            $errors[] = ['path' => $path, 'message' => $message];
        }
    }
}

