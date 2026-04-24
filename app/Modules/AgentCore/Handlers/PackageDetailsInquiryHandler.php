<?php

namespace App\Modules\AgentCore\Handlers;

use App\Modules\AgentCore\DTOs\BusinessResponsePayload;
use App\Modules\AgentCore\DTOs\PackageDetailsHandlerInput;
use App\Modules\AgentCore\Enums\FinalAction;
use App\Modules\Conversations\Models\ConversationState;
use App\Modules\Knowledge\Services\KnowledgeRetrievalService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PackageDetailsInquiryHandler
{
    public function __construct(
        private readonly KnowledgeRetrievalService $knowledgeRetrievalService,
    ) {}

    public function buildPayload(PackageDetailsHandlerInput $input): ?BusinessResponsePayload
    {
        $state = $input->conversation->state()->first();
        $filledSlots = is_array($state?->filled_slots) ? $state->filled_slots : [];
        $eventType = $this->normalizeEventTypeContext($this->stringOrNull($filledSlots['event_type'] ?? null));
        $packageInterest = $this->resolveRequestedPackageInterest(
            $this->stringOrNull($filledSlots['package_interest'] ?? null),
            (string) $input->message->content,
        );

        $items = $this->knowledgeRetrievalService->getPackageSubset(
            $input->lead->tenant,
            $packageInterest,
            $eventType,
            3,
        );

        if ($items->isEmpty()) {
            return null;
        }

        $scope = $this->buildGroundedPackageScope($eventType, $packageInterest);
        $presentation = $this->buildPresentationPayload(
            $items,
            $scope,
            $packageInterest,
            $state,
            (string) $input->message->content,
        );

        return new BusinessResponsePayload(
            payloadType: 'package_details',
            action: FinalAction::ReplyWithGroundedPackage,
            data: array_merge($presentation, [
                'scope' => $scope,
                'event_type' => $eventType,
                'package_interest' => $packageInterest,
                'next_best_action' => 'respond_to_user',
                'tool_result_summary' => 'grounded_package_answer',
            ]),
            responseRules: [
                'must_answer_latest_question_first' => true,
                'must_not_invent_price' => true,
                'must_not_invent_availability' => true,
                'must_not_promise_followup_without_action' => true,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPresentationPayload(
        Collection $items,
        string $scope,
        ?string $packageInterest,
        ?ConversationState $state,
        string $messageContent,
    ): array {
        $wantsDetail = $this->shouldExplainPackageDetails($messageContent, $state);
        $titles = $this->buildPackageTitleList($scope, $items, $packageInterest);

        if (! $wantsDetail && $titles !== []) {
            return [
                'presentation_mode' => 'title_list',
                'titles' => $titles,
            ];
        }

        $primaryItem = $this->selectPrimaryGroundedPackageItem($items, $packageInterest);
        $variants = $primaryItem !== null
            ? $this->buildStructuredVariants($primaryItem, $packageInterest)
            : [];

        if ($variants !== []) {
            return [
                'presentation_mode' => 'structured_variants',
                'variants' => $variants,
            ];
        }

        return [
            'presentation_mode' => 'catalog_summary',
            'catalog' => $items->map(function ($item): array {
                return [
                    'title' => trim((string) ($item->title ?? 'Paket')),
                    'summary' => $this->summarizePackageKnowledge((string) ($item->content ?? '')),
                ];
            })->values()->all(),
        ];
    }

    /**
     * @return list<string>
     */
    private function buildPackageTitleList(string $scope, Collection $items, ?string $packageInterest): array
    {
        $titles = [];
        $fallbackTitles = [];

        foreach ($items as $item) {
            $variants = $this->extractPackageVariants((string) ($item->content ?? ''));
            $allVariantNames = array_values(array_filter(array_map(
                static fn (array $variant): string => trim((string) ($variant['name'] ?? '')),
                $variants,
            )));

            if ($packageInterest !== null && $packageInterest !== '') {
                $variants = array_values(array_filter($variants, function (array $variant) use ($packageInterest): bool {
                    $haystack = mb_strtolower((string) $variant['name']);

                    foreach ($this->packageInterestKeywords($packageInterest) as $keyword) {
                        if (str_contains($haystack, $keyword)) {
                            return true;
                        }
                    }

                    return false;
                }));
            }

            foreach ($allVariantNames as $name) {
                $fallbackTitles[] = $this->normalizePackageTitleForScope($name, $scope);
            }

            foreach ($variants as $variant) {
                $name = trim((string) ($variant['name'] ?? ''));
                if ($name !== '') {
                    $titles[] = $this->normalizePackageTitleForScope($name, $scope);
                }
            }
        }

        $titles = array_values(array_unique($titles));
        if ($titles === []) {
            $titles = array_values(array_unique($fallbackTitles));
        }

        return array_slice($titles, 0, 6);
    }

    private function selectPrimaryGroundedPackageItem(Collection $items, ?string $packageInterest): mixed
    {
        return $items
            ->sortByDesc(fn ($item): int => $this->groundedPackageItemScore($item, $packageInterest))
            ->first();
    }

    private function groundedPackageItemScore(mixed $item, ?string $packageInterest): int
    {
        $haystack = mb_strtolower(trim(sprintf('%s %s', (string) ($item->title ?? ''), (string) ($item->content ?? ''))));
        $score = 0;

        if ($packageInterest !== null && $packageInterest !== '') {
            foreach ($this->packageInterestKeywords($packageInterest) as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    $score += 6;
                    break;
                }
            }
        }

        return $score;
    }

    /**
     * @return list<array{name: string, duration: ?string, team: ?string, include: ?string, price: ?string}>
     */
    private function buildStructuredVariants(mixed $item, ?string $packageInterest): array
    {
        $variants = $this->extractPackageVariants((string) ($item->content ?? ''));

        if ($packageInterest !== null && $packageInterest !== '') {
            $filtered = array_values(array_filter($variants, function (array $variant) use ($packageInterest): bool {
                $haystack = mb_strtolower((string) $variant['name']);

                foreach ($this->packageInterestKeywords($packageInterest) as $keyword) {
                    if (str_contains($haystack, $keyword)) {
                        return true;
                    }
                }

                return false;
            }));

            if ($filtered !== []) {
                $variants = $filtered;
            }
        }

        return array_slice($variants, 0, 2);
    }

    /**
     * @return list<array{name: string, duration: ?string, team: ?string, include: ?string, price: ?string}>
     */
    private function extractPackageVariants(string $content): array
    {
        $variants = [];
        $current = null;

        foreach (preg_split('/\r?\n/u', trim($content)) ?: [] as $rawLine) {
            $line = trim($rawLine);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^\d+\.\s*(.+)$/u', $line, $matches) === 1) {
                if ($current !== null) {
                    $variants[] = $current;
                }

                $current = [
                    'name' => trim($matches[1]),
                    'duration' => null,
                    'team' => null,
                    'include' => null,
                    'price' => null,
                ];

                continue;
            }

            if ($current === null) {
                continue;
            }

            if (preg_match('/^-\s*Durasi:\s*(.+)$/iu', $line, $matches) === 1) {
                $current['duration'] = trim($matches[1]);
                continue;
            }

            if (preg_match('/^-\s*Tim:\s*(.+)$/iu', $line, $matches) === 1) {
                $current['team'] = trim($matches[1]);
                continue;
            }

            if (preg_match('/^-\s*Include:\s*(.+)$/iu', $line, $matches) === 1) {
                $current['include'] = trim($matches[1]);
                continue;
            }

            if (preg_match('/^-\s*Harga:\s*(.+)$/iu', $line, $matches) === 1) {
                $current['price'] = trim($matches[1]);
            }
        }

        if ($current !== null) {
            $variants[] = $current;
        }

        return array_values(array_filter($variants, static fn (array $variant): bool => $variant['name'] !== ''));
    }

    private function summarizePackageKnowledge(string $content): string
    {
        $trimmed = trim($content);

        if ($trimmed === '') {
            return 'detail paketnya tersedia dan bisa dijelaskan satu per satu';
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $trimmed) ?: [$trimmed];
        $summary = trim((string) ($sentences[0] ?? $trimmed));

        return Str::limit(rtrim($summary, '.'), 120, '');
    }

    private function shouldExplainPackageDetails(string $messageContent, ?ConversationState $state): bool
    {
        $content = mb_strtolower(trim($messageContent));

        if ($content === '') {
            return false;
        }

        if ($this->containsAnyFragment($content, [
            'detail',
            'jelasin detail',
            'jelaskan detail',
            'isi paket',
            'dapat apa',
            'dapet apa',
            'durasi',
            'album',
            'jam',
            'include',
            'termasuk',
        ])) {
            return true;
        }

        if (! $this->isShortContinuationCandidate($content)) {
            return false;
        }

        $lastAgentMessage = mb_strtolower(trim((string) ($state?->last_agent_message ?? '')));

        return $this->containsAnyFragment($lastAgentMessage, [
            'tinggal sebut judul paketnya',
            'sebut judul paketnya',
            'pilihannya ada',
        ]);
    }

    private function isShortContinuationCandidate(string $content): bool
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $content) ?? $content);
        $wordCount = count(array_values(array_filter(preg_split('/\s+/u', $normalized) ?: [])));

        return $wordCount <= 6 || mb_strlen($normalized) <= 40;
    }

    private function buildGroundedPackageScope(?string $eventType, ?string $packageInterest): string
    {
        if ($eventType !== null) {
            return 'paket ' . $this->displayEventType($eventType);
        }

        if ($packageInterest !== null) {
            return 'paket ' . $packageInterest;
        }

        return 'paket yang tersedia';
    }

    private function resolveRequestedPackageInterest(?string $storedPackageInterest, string $messageContent): ?string
    {
        $normalizedStored = $this->normalizePackageInterest($storedPackageInterest);

        return $normalizedStored ?? $this->inferPackageInterestFromMessage($messageContent);
    }

    private function normalizeEventTypeContext(?string $eventType): ?string
    {
        $normalized = mb_strtolower(trim((string) $eventType));

        if ($normalized === '') {
            return null;
        }

        return match (true) {
            str_contains($normalized, 'lamaran'), str_contains($normalized, 'engagement') => 'engagement',
            str_contains($normalized, 'prewedding'), str_contains($normalized, 'pre wedding') => 'prewedding',
            str_contains($normalized, 'wedding'), str_contains($normalized, 'akad'), str_contains($normalized, 'resepsi') => 'wedding',
            default => trim((string) $eventType),
        };
    }

    private function displayEventType(string $eventType): string
    {
        return match ($this->normalizeEventTypeContext($eventType)) {
            'engagement' => 'lamaran',
            'prewedding' => 'prewedding',
            'wedding' => 'wedding',
            default => trim($eventType),
        };
    }

    private function normalizePackageInterest(?string $packageInterest): ?string
    {
        $normalized = mb_strtolower(trim((string) $packageInterest));

        if ($normalized === '') {
            return null;
        }

        return match (true) {
            str_contains($normalized, 'photo') && str_contains($normalized, 'video') => 'photo + video',
            str_contains($normalized, 'foto') && str_contains($normalized, 'video') => 'photo + video',
            str_contains($normalized, 'photo') && str_contains($normalized, 'album') => 'photo + album',
            str_contains($normalized, 'foto') && str_contains($normalized, 'album') => 'photo + album',
            str_contains($normalized, 'photo'), str_contains($normalized, 'foto') => 'photo only',
            str_contains($normalized, 'video') => 'video only',
            default => trim((string) $packageInterest),
        };
    }

    private function inferPackageInterestFromMessage(string $message): ?string
    {
        $normalized = mb_strtolower(trim($message));

        if ($normalized === '') {
            return null;
        }

        return match (true) {
            $this->containsAnyFragment($normalized, ['photo dan video', 'foto dan video', 'photo + video', 'foto + video', 'photo video']) => 'photo + video',
            $this->containsAnyFragment($normalized, ['photo album', 'foto album', 'photo + album', 'foto + album']) => 'photo + album',
            $this->containsAnyFragment($normalized, ['photo only', 'foto only']) => 'photo only',
            $this->containsAnyFragment($normalized, ['video only']) => 'video only',
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function packageInterestKeywords(string $packageInterest): array
    {
        return match ($this->normalizePackageInterest($packageInterest)) {
            'photo + video' => ['photo + video', 'photo video', 'photo dan video', 'foto dan video', 'foto + video'],
            'photo + album' => ['photo + album', 'photo album', 'foto + album', 'foto album'],
            'photo only' => ['photo only', 'foto only', 'photo'],
            'video only' => ['video only', 'video'],
            default => [mb_strtolower(trim($packageInterest))],
        };
    }

    private function normalizePackageTitleForScope(string $name, string $scope): string
    {
        $normalizedName = trim($name);
        $normalizedScope = mb_strtolower(trim($scope));

        if ($normalizedScope === 'paket wedding' && ! str_contains(mb_strtolower($normalizedName), 'wedding')) {
            $normalizedName = 'Wedding ' . $normalizedName;
        }

        return $normalizedName;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    /**
     * @param  list<string>  $fragments
     */
    private function containsAnyFragment(string $content, array $fragments): bool
    {
        foreach ($fragments as $fragment) {
            if (str_contains($content, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
