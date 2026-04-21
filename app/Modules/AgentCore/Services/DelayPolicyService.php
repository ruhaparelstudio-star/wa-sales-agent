<?php

namespace App\Modules\AgentCore\Services;

class DelayPolicyService
{
    public function getDelay(string $responseText): int
    {
        $wordCount = $this->countWords($responseText);

        return match (true) {
            $wordCount < 30                           => random_int(2, 5),
            $wordCount >= 30 && $wordCount <= 100     => random_int(4, 10),
            default                                   => random_int(8, 15),
        };
    }

    private function countWords(string $text): int
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return 0;
        }

        return count(preg_split('/\s+/', $trimmed) ?: []);
    }
}
