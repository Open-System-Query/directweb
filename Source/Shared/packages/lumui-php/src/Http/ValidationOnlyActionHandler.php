<?php
declare(strict_types=1);

namespace Lumui\Http;

use Lumui\Contracts\ActionHandler;

final class ValidationOnlyActionHandler implements ActionHandler
{
    public function invoke(array $message, array $surface, array $definition): array
    {
        return [
            'status' => 'completed',
            'status_code' => 1000,
            'result' => [
                'message' => 'The action was validated.',
                'mutated' => false,
            ],
        ];
    }
}

