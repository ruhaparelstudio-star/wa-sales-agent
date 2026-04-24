<?php

namespace App\Modules\AgentCore\Services;

use App\Modules\AgentCore\DTOs\BusinessResponsePayload;
use App\Modules\AgentCore\DTOs\BusinessResponseRenderResult;
use Illuminate\Support\Str;

class BusinessPayloadResponder
{
    public function render(BusinessResponsePayload $payload): BusinessResponseRenderResult
    {
        return match ($payload->payloadType) {
            'pricelist_info' => $this->renderPricelistInfo($payload),
            'package_details' => $this->renderPackageDetails($payload),
            'booking_field_clarification' => $this->renderBookingFieldClarification($payload),
            default => BusinessResponseRenderResult::text(''),
        };
    }

    private function renderPricelistInfo(BusinessResponsePayload $payload): BusinessResponseRenderResult
    {
        $status = (string) ($payload->data['delivery_status'] ?? 'missing');
        $nextBestAction = $this->stringOrNull($payload->data['next_best_action'] ?? null);
        $toolResultSummary = $this->stringOrNull($payload->data['tool_result_summary'] ?? null);

        if ($status === 'ready_to_send') {
            $intent = (string) ($payload->data['intent'] ?? 'tanya_harga');
            $followUpText = match ($intent) {
                'bandingkan_paket' => 'Pricelist PDF-nya sudah aku kirim ya. Kalau ada bagian yang mau dicek, misalnya perbedaan paket atau kisaran harganya, tinggal bilang aja.',
                'tanya_harga', 'tanya_paket' => 'Pricelist PDF-nya sudah aku kirim ya. Kalau ada bagian yang mau aku jelaskan dari pricelist itu, tinggal bilang aja.',
                default => 'Pricelist PDF-nya sudah aku kirim ya. Kalau ada bagian yang mau dijelaskan, aku bantu lanjut dari situ.',
            };

            return BusinessResponseRenderResult::documentFollowUp(
                followUpText: $followUpText,
                caption: (string) ($payload->data['document_caption'] ?? 'Pricelist terbaru kami'),
                nextBestAction: $nextBestAction,
                toolResultSummary: $toolResultSummary,
            );
        }

        $message = $status === 'dispatch_failed'
            ? 'Pricelist sedang gagal kami kirim otomatis. Admin kami akan lanjut bantu kirimkan detail harga ya.'
            : 'Saat ini pricelist PDF belum tersedia di sistem kami. Admin kami akan lanjut membantu dan segera mengirimkan detail harga ya.';

        return BusinessResponseRenderResult::text($message, $nextBestAction, $toolResultSummary);
    }

    private function renderPackageDetails(BusinessResponsePayload $payload): BusinessResponseRenderResult
    {
        $scope = (string) ($payload->data['scope'] ?? 'paket yang tersedia');
        $mode = (string) ($payload->data['presentation_mode'] ?? 'catalog_summary');
        $nextBestAction = $this->stringOrNull($payload->data['next_best_action'] ?? null);
        $toolResultSummary = $this->stringOrNull($payload->data['tool_result_summary'] ?? null);

        $message = match ($mode) {
            'title_list' => $this->renderPackageTitleList($scope, (array) ($payload->data['titles'] ?? [])),
            'structured_variants' => $this->renderStructuredVariants($scope, (array) ($payload->data['variants'] ?? [])),
            default => $this->renderCatalogSummary($scope, (array) ($payload->data['catalog'] ?? [])),
        };

        return BusinessResponseRenderResult::text($message, $nextBestAction, $toolResultSummary);
    }

    private function renderBookingFieldClarification(BusinessResponsePayload $payload): BusinessResponseRenderResult
    {
        $invalidField = is_array($payload->data['invalid_field'] ?? null) ? $payload->data['invalid_field'] : null;
        $nextField = is_array($payload->data['next_field'] ?? null) ? $payload->data['next_field'] : null;

        if ($invalidField !== null) {
            $label = (string) ($invalidField['label'] ?? 'data ini');
            $error = trim((string) ($invalidField['error'] ?? ''));
            $message = $error !== ''
                ? sprintf('%s Boleh kirim ulang %s dengan format yang benar ya?', $error, $label)
                : sprintf('Format %s belum sesuai. Boleh kirim ulang ya?', $label);
        } else {
            $message = $nextField !== null
                ? sprintf('Siap, aku catat ya. Lanjut, boleh info %s?', (string) ($nextField['label'] ?? 'data berikutnya'))
                : 'Siap, data bookingnya sudah lengkap dan sudah aku catat ya.';
        }

        return BusinessResponseRenderResult::text(
            $message,
            $this->stringOrNull($payload->data['next_best_action'] ?? null),
            $this->stringOrNull($payload->data['tool_result_summary'] ?? null),
        );
    }

    /**
     * @param  list<string>  $titles
     */
    private function renderPackageTitleList(string $scope, array $titles): string
    {
        $titles = array_values(array_filter(array_map(
            static fn (mixed $title): string => trim((string) $title),
            $titles,
        )));

        if ($titles === []) {
            return sprintf('Untuk %s, aku bisa bantu jelaskan detail yang paling relevan buat kebutuhanmu.', $scope);
        }

        $lines = [sprintf('Untuk %s, pilihannya ada:', $scope), ''];

        foreach ($titles as $index => $title) {
            $lines[] = ($index + 1) . '. ' . $title;
        }

        $lines[] = '';
        $lines[] = 'Kalau ada yang mau dicek, tinggal sebut judul paketnya ya. Nanti aku jelaskan detailnya satu per satu.';

        return implode("\n", $lines);
    }

    /**
     * @param  list<array{name?: mixed, duration?: mixed, team?: mixed, include?: mixed, price?: mixed}>  $variants
     */
    private function renderStructuredVariants(string $scope, array $variants): string
    {
        $variants = array_values(array_filter($variants, static fn (mixed $variant): bool => is_array($variant)));

        if ($variants === []) {
            return sprintf('Untuk %s, aku bisa bantu jelaskan opsi yang paling relevan buat kebutuhanmu.', $scope);
        }

        $lines = [count($variants) === 1
            ? sprintf('Untuk %s, pilihan yang paling relevan ini ya:', $scope)
            : sprintf('Untuk %s, pilihan yang paling relevan ada %s:', $scope, (string) count($variants)), ''];

        foreach ($variants as $index => $variant) {
            $lines[] = ($index + 1) . '. ' . $this->formatVariant($variant);
        }

        $lines[] = '';
        $lines[] = 'Kalau kamu mau, aku bisa bantu jelaskan mana yang paling pas buat kebutuhanmu.';

        return implode("\n", $lines);
    }

    /**
     * @param  list<array{title?: mixed, summary?: mixed}>  $catalog
     */
    private function renderCatalogSummary(string $scope, array $catalog): string
    {
        $segments = [];

        foreach ($catalog as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = trim((string) ($item['title'] ?? 'Paket'));
            $summary = trim((string) ($item['summary'] ?? 'detail paketnya tersedia dan bisa dijelaskan satu per satu'));
            $segments[] = sprintf('%s: %s.', $title, rtrim($summary, '.'));
        }

        return sprintf(
            'Untuk %s saat ini ada %s Kalau kamu mau, aku bisa bantu jelaskan mana yang paling pas buat kebutuhanmu.',
            $scope,
            implode(' ', $segments),
        );
    }

    /**
     * @param  array{name?: mixed, duration?: mixed, team?: mixed, include?: mixed, price?: mixed}  $variant
     */
    private function formatVariant(array $variant): string
    {
        $segments = [trim((string) ($variant['name'] ?? 'Paket'))];

        if ($this->stringOrNull($variant['team'] ?? null) !== null) {
            $segments[] = 'dengan ' . trim((string) $variant['team']);
        }

        if ($this->stringOrNull($variant['duration'] ?? null) !== null) {
            $segments[] = 'durasi ' . trim((string) $variant['duration']);
        }

        if ($this->stringOrNull($variant['include'] ?? null) !== null) {
            $segments[] = 'sudah termasuk ' . Str::limit(trim((string) $variant['include']), 120, '...');
        }

        if ($this->stringOrNull($variant['price'] ?? null) !== null) {
            $segments[] = 'harga ' . trim((string) $variant['price']);
        }

        return implode(', ', $segments);
    }

    private function stringOrNull(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
