<?php
declare(strict_types=1);

namespace Lumui\Protocol;

use RuntimeException;

final class SurfaceValidator
{
    
    private $schemaValidator;
    
    private $componentKinds = [];
    
    private $componentDefinitions = [];
    
    private $commonComponentFields = [];
    
    private $actionReferenceFields = [];
    
    private $componentReferenceFields = [];
    
    private $componentCollectionFields = [];
    public function __construct(string $catalogPath, string $surfaceSchemaPath)
    {
        $catalog = json_decode((string) file_get_contents($catalogPath), true);
        if (!is_array($catalog) || !isset($catalog['components']) || !is_array($catalog['components'])) {
            throw new RuntimeException('The LUMUI component catalog is unavailable.');
        }
        $schema = json_decode((string) file_get_contents($surfaceSchemaPath), true);
        if (!is_array($schema)) {
            throw new RuntimeException('The LUMUI surface schema is unavailable.');
        }
        $this->schemaValidator = new JsonSchemaValidator($schema);

        foreach ($catalog['components'] as $kind => $definition) {
            $this->componentKinds[(string) $kind] = true;
            $this->componentDefinitions[(string) $kind] = is_array($definition) ? $definition : [];
        }
        foreach (array_merge(
            is_array($catalog['common_required'] ?? null) ? $catalog['common_required'] : [],
            is_array($catalog['common_optional'] ?? null) ? $catalog['common_optional'] : [],
            array_keys(is_array($catalog['semantic_hints'] ?? null) ? $catalog['semantic_hints'] : [])
        ) as $field) {
            if (is_string($field)) {
                $this->commonComponentFields[$field] = true;
            }
        }
        foreach (($catalog['action_reference_fields'] ?? []) as $field) {
            if (is_string($field)) {
                $this->actionReferenceFields[$field] = true;
            }
        }
        if ($this->actionReferenceFields === []) {
            throw new RuntimeException('The LUMUI catalog does not define action reference fields.');
        }
        foreach (($catalog['component_reference_fields'] ?? []) as $field) {
            if (is_string($field)) {
                $this->componentReferenceFields[$field] = true;
            }
        }
        foreach (($catalog['component_collection_fields'] ?? []) as $field) {
            if (is_string($field)) {
                $this->componentCollectionFields[$field] = true;
            }
        }
        if ($this->componentReferenceFields === [] || $this->componentCollectionFields === []) {
            throw new RuntimeException('The LUMUI catalog does not define component containment fields.');
        }
    }

    
    public function validate(array $surface): void
    {
        $this->schemaValidator->validate($surface, 'Surface');

        $ids = [];
        $pageIds = [];
        $nodeCount = 0;
        foreach ($surface['pages'] as $page) {
            $this->claimId((string) $page['id'], $ids);
            $pageIds[(string) $page['id']] = true;
            $this->validateActionReferences($page, $surface['actions'], "Page '{$page['id']}'");
            $this->validateNodes($page['regions'], $ids, $surface['actions'], $nodeCount);
        }

        $requestedPageId = (string) ($surface['requested_page_id'] ?? '');
        if ($requestedPageId !== '' && !isset($pageIds[$requestedPageId])) {
            throw new RuntimeException('Surface requested_page_id does not reference a published page.');
        }
        $navigation = $surface['navigation'] ?? null;
        if (is_array($navigation)) {
            $startPage = (string) ($navigation['start_page'] ?? '');
            if ($startPage === '' || !isset($pageIds[$startPage])) {
                throw new RuntimeException('Surface navigation.start_page does not reference a published page.');
            }
            foreach (($navigation['routes'] ?? []) as $route) {
                if (!is_array($route)) {
                    continue;
                }
                if (isset($route['href'])) {
                    $this->safeReference((string) $route['href']);
                    continue;
                }
                $from = (string) ($route['from'] ?? '');
                $to = (string) ($route['to'] ?? '');
                $action = (string) ($route['action'] ?? '');
                if (
                    !isset($pageIds[$from])
                    || !isset($pageIds[$to])
                    || !array_key_exists($action, $surface['actions'])
                ) {
                    throw new RuntimeException(
                        'A navigation transition references an unavailable page or action.'
                    );
                }
            }
            foreach (($navigation['groups'] ?? []) as $group) {
                if (!is_array($group)) {
                    continue;
                }
                foreach (($group['links'] ?? []) as $link) {
                    if (is_array($link) && isset($link['href'])) {
                        $this->safeReference((string) $link['href']);
                    }
                }
            }
        }

        foreach (($surface['links'] ?? []) as $link) {
            if (!is_array($link) || !is_string($link['rel'] ?? null) || !is_string($link['href'] ?? null)) {
                throw new RuntimeException('Every surface link requires rel and href.');
            }
            $this->safeReference((string) $link['href']);
        }
    }

    
    public function componentDeclaresAction($nodes, string $componentId, string $actionId): bool
    {
        if (!is_array($nodes)) {
            return false;
        }
        if (isset($nodes['id'], $nodes['kind'])) {
            if ((string) $nodes['id'] === $componentId) {
                foreach ($this->actionReferenceFields as $field => $_) {
                    $value = $nodes[$field] ?? null;
                    if ($value === $actionId) {
                        return true;
                    }
                    if (is_array($value) && in_array($actionId, $value, true)) {
                        return true;
                    }
                }
            }
            foreach ($this->componentCollectionFields as $field => $_) {
                if ($this->componentDeclaresAction(
                    $nodes[$field] ?? null,
                    $componentId,
                    $actionId
                )) {
                    return true;
                }
            }
            foreach ($this->componentReferenceFields as $field => $_) {
                if ($this->componentDeclaresAction(
                    $nodes[$field] ?? null,
                    $componentId,
                    $actionId
                )) {
                    return true;
                }
            }
            return false;
        }
        foreach ($nodes as $node) {
            if ($this->componentDeclaresAction($node, $componentId, $actionId)) {
                return true;
            }
        }
        return false;
    }

    
    private function validateNodes($nodes, array &$ids, $actions, int &$nodeCount, int $depth = 1): void
    {
        if (!is_array($nodes)) {
            throw new RuntimeException('Component collections must be arrays.');
        }
        if ($depth > 12) {
            throw new RuntimeException('The component tree exceeds the maximum depth of 12.');
        }
        if (count($nodes) > 500) {
            throw new RuntimeException('A component collection exceeds 500 entries.');
        }

        foreach ($nodes as $node) {
            if (!is_array($node) || !isset($node['id'], $node['kind'])) {
                throw new RuntimeException('Every LUMUI component requires an id and kind.');
            }

            $id = (string) $node['id'];
            $kind = (string) $node['kind'];
            $this->claimId($id, $ids);
            $nodeCount++;
            if ($nodeCount > 5000) {
                throw new RuntimeException('The surface exceeds the maximum of 5000 components.');
            }

            if (!isset($this->componentKinds[$kind])) {
                throw new RuntimeException("Unknown LUMUI component kind '{$kind}'.");
            }
            $this->validateRequiredFields($node, $kind, $id);
            $this->validateComponentFields($node, $kind, $id);

            $this->validateActionReferences($node, $actions, "Component '{$id}'");
            if (isset($node['href'])) {
                $this->safeReference((string) $node['href']);
            }
            if (isset($node['source'])) {
                $source = $node['source'];
                if (is_string($source)) {
                    $this->safeReference($source);
                } elseif (is_array($source)) {
                    foreach (['src', 'href', 'poster'] as $sourceField) {
                        if (isset($source[$sourceField])) {
                            $this->safeReference((string) $source[$sourceField]);
                        }
                    }
                }
            }

            foreach ($this->componentCollectionFields as $field => $_) {
                if (array_key_exists($field, $node)) {
                    $this->validateNodes(
                        $node[$field],
                        $ids,
                        $actions,
                        $nodeCount,
                        $depth + 1
                    );
                }
            }
            foreach ($this->componentReferenceFields as $field => $_) {
                $child = $node[$field] ?? null;
                if (is_array($child) && isset($child['id'], $child['kind'])) {
                    $this->validateNodes(
                        [$child],
                        $ids,
                        $actions,
                        $nodeCount,
                        $depth + 1
                    );
                }
            }
        }
    }

    
    private function validateActionReferences(array $value, $actions, string $subject): void
    {
        foreach ($value as $field => $fieldValue) {
            if (isset($this->actionReferenceFields[(string) $field])) {
                $references = is_array($fieldValue) ? array_values($fieldValue) : [$fieldValue];
                foreach ($references as $reference) {
                    if (
                        !is_string($reference)
                        || !is_array($actions)
                        || !array_key_exists($reference, $actions)
                    ) {
                        throw new RuntimeException("{$subject} references an undeclared action.");
                    }
                }
                continue;
            }
        }
    }

    
    private function validateRequiredFields(array $node, string $kind, string $id): void
    {
        $definition = $this->componentDefinitions[$kind] ?? [];
        foreach (($definition['required'] ?? []) as $requirement) {
            if (!is_string($requirement) || $requirement === 'id') {
                continue;
            }
            $alternatives = explode('|', $requirement);
            $present = false;
            foreach ($alternatives as $field) {
                if (array_key_exists($field, $node) && $node[$field] !== null) {
                    $present = true;
                    break;
                }
            }
            if (!$present) {
                throw new RuntimeException(
                    "Component '{$id}' of kind '{$kind}' requires field '{$requirement}'."
                );
            }
        }
    }

    
    private function validateComponentFields(array $node, string $kind, string $id): void
    {
        $allowed = $this->commonComponentFields;
        $definition = $this->componentDefinitions[$kind] ?? [];
        foreach (array_merge(
            is_array($definition['required'] ?? null) ? $definition['required'] : [],
            is_array($definition['optional'] ?? null) ? $definition['optional'] : []
        ) as $requirement) {
            if (!is_string($requirement)) {
                continue;
            }
            foreach (explode('|', $requirement) as $field) {
                $allowed[$field] = true;
            }
        }
        $forbidden = [];
        foreach (($definition['forbidden'] ?? []) as $field) {
            if (is_string($field)) {
                $forbidden[$field] = true;
                unset($allowed[$field]);
            }
        }
        foreach (array_keys($node) as $field) {
            if (isset($forbidden[$field])) {
                throw new RuntimeException(
                    "Component '{$id}' of kind '{$kind}' forbids field '{$field}'."
                );
            }
            if (!isset($allowed[$field])) {
                throw new RuntimeException(
                    "Component '{$id}' of kind '{$kind}' does not define field '{$field}'."
                );
            }
        }
    }

    
    private function claimId(string $id, array &$ids): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $id) !== 1 || isset($ids[$id])) {
            throw new RuntimeException("LUMUI id '{$id}' is empty or duplicated.");
        }
        $ids[$id] = true;
    }

    private function safeReference(string $href): void
    {
        if (trim($href) === '' || preg_match('/[\x00-\x1F\x7F]/', $href) === 1) {
            throw new RuntimeException('A LUMUI URL is invalid.');
        }
        $scheme = parse_url($href, PHP_URL_SCHEME);
        if (
            is_string($scheme)
            && !in_array(strtolower($scheme), ['http', 'https', 'mailto', 'tel'], true)
        ) {
            throw new RuntimeException("A LUMUI URL uses unsupported scheme '{$scheme}'.");
        }
    }

}
