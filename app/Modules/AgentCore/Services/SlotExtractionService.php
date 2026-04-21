<?php

namespace App\Modules\AgentCore\Services;

class SlotExtractionService
{
    /**
     * @return array<string, mixed>
     */
    public function extract(string $content): array
    {
        $normalized = $this->normalize($content);

        return array_filter([
            'event_type' => $this->extractEventType($normalized),
            'event_date' => $this->extractEventDate($normalized),
            'event_time_start' => $this->extractEventTime($normalized)['start'],
            'event_time_end' => $this->extractEventTime($normalized)['end'],
            'location' => $this->extractLocation($normalized),
            'pricing_focus' => $this->extractPricingFocus($normalized),
            'package_interest' => $this->extractPackageInterest($normalized),
            'budget' => $this->extractBudget($normalized),
            'payment_topic' => $this->extractPaymentTopic($normalized),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function extractEventType(string $content): ?string
    {
        return match (true) {
            str_contains($content, 'prewed') || str_contains($content, 'prewedding') => 'prewedding',
            str_contains($content, 'lamaran') || str_contains($content, 'engagement') => 'engagement',
            str_contains($content, 'akad') || str_contains($content, 'resepsi') || str_contains($content, 'wedding') || str_contains($content, 'nikah') || str_contains($content, 'pernikahan') => 'wedding',
            default => null,
        };
    }

    private function extractEventDate(string $content): ?string
    {
        if (preg_match('/\b(\d{1,2})\s+(januari|februari|maret|april|mei|juni|juli|agustus|september|oktober|november|desember)(?:\s+(\d{4}))?\b/u', $content, $m)) {
            $months = [
                'januari' => 1,
                'februari' => 2,
                'maret' => 3,
                'april' => 4,
                'mei' => 5,
                'juni' => 6,
                'juli' => 7,
                'agustus' => 8,
                'september' => 9,
                'oktober' => 10,
                'november' => 11,
                'desember' => 12,
            ];

            $year = isset($m[3]) ? (int) $m[3] : (int) now()->format('Y');

            return sprintf('%04d-%02d-%02d', $year, $months[$m[2]], (int) $m[1]);
        }

        if (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})(?:[\/\-](\d{2,4}))?\b/u', $content, $m)) {
            $day = (int) $m[1];
            $month = (int) $m[2];

            if ($day < 1 || $day > 31 || $month < 1 || $month > 12) {
                return null;
            }

            $year = isset($m[3]) ? (int) $m[3] : (int) now()->format('Y');
            if ($year < 100) {
                $year += 2000;
            }

            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }

        return null;
    }

    /**
     * @return array{start: ?string, end: ?string}
     */
    private function extractEventTime(string $content): array
    {
        if (preg_match('/(?:jam\s*)?(\d{1,2})(?:[:.](\d{2}))?\s*(?:-|sampai|sd|to)\s*(\d{1,2})(?:[:.](\d{2}))?/u', $content, $m)) {
            return [
                'start' => sprintf('%02d:%02d', (int) $m[1], isset($m[2]) ? (int) $m[2] : 0),
                'end' => sprintf('%02d:%02d', (int) $m[3], isset($m[4]) ? (int) $m[4] : 0),
            ];
        }

        if (preg_match('/(?:jam\s*)(\d{1,2})(?:[:.](\d{2}))?/u', $content, $m)) {
            return [
                'start' => sprintf('%02d:%02d', (int) $m[1], isset($m[2]) ? (int) $m[2] : 0),
                'end' => null,
            ];
        }

        return ['start' => null, 'end' => null];
    }

    private function extractLocation(string $content): ?string
    {
        if (preg_match('/(?:lokasi(?:nya)?|venue(?:nya)?|acara(?:nya)? di|di)\s+([a-z0-9 .,-]{3,60})/u', $content, $m)) {
            $location = trim($m[1]);
            $location = preg_replace('/\s+(jam|tanggal|budget|paket|harga)\b.*$/u', '', $location) ?? $location;
            $location = trim($location, " .,-");

            return $location !== '' ? ucwords($location) : null;
        }

        return null;
    }

    private function extractPackageInterest(string $content): ?string
    {
        return match (true) {
            str_contains($content, 'silver') => 'silver',
            str_contains($content, 'gold') => 'gold',
            str_contains($content, 'platinum') => 'platinum',
            str_contains($content, 'premium') => 'premium',
            str_contains($content, 'basic') => 'basic',
            default => null,
        };
    }

    private function extractPricingFocus(string $content): ?string
    {
        $asksPrice = str_contains($content, 'harga')
            || str_contains($content, 'pricelist')
            || str_contains($content, 'price list')
            || str_contains($content, 'daftar harga')
            || str_contains($content, 'biaya');

        $asksPackage = str_contains($content, 'isi paket')
            || str_contains($content, 'detail paket')
            || str_contains($content, 'paketnya')
            || str_contains($content, 'paket nya')
            || str_contains($content, 'coverage');

        return match (true) {
            $asksPrice && $asksPackage => 'price_and_package',
            $asksPackage => 'package_only',
            $asksPrice => 'price_only',
            default => null,
        };
    }

    private function extractBudget(string $content): ?string
    {
        if (preg_match('/(?:budget|anggaran|dana|max|maks(?:imal)?|under|dibawah|di bawah)\s*(?:rp)?\s*([\d.,]+)\s*(jt|juta|m|ribu|k)?/u', $content, $m)) {
            return $this->normalizeMoneyValue($m[1], $m[2] ?? null);
        }

        if (preg_match('/(?:rp)\s*([\d.]+(?:,\d+)?)\b/u', $content, $m)) {
            return $this->normalizeMoneyValue($m[1], null);
        }

        return null;
    }

    private function extractPaymentTopic(string $content): ?string
    {
        return match (true) {
            str_contains($content, 'bukti transfer') || str_contains($content, 'bukti bayar') => 'payment_proof',
            str_contains($content, 'dp') || str_contains($content, 'down payment') => 'down_payment',
            str_contains($content, 'pelunasan') || str_contains($content, 'lunas') => 'settlement',
            str_contains($content, 'transfer') || str_contains($content, 'pembayaran') || str_contains($content, 'payment') => 'payment_method',
            default => null,
        };
    }

    private function normalizeMoneyValue(string $rawNumber, ?string $unit): ?string
    {
        $normalized = str_replace(',', '.', preg_replace('/\./', '', $rawNumber, substr_count($rawNumber, '.') > 1 ? -1 : 0) ?? $rawNumber);
        if (! is_numeric($normalized)) {
            return null;
        }

        $value = (float) $normalized;
        $multiplier = match (mb_strtolower((string) $unit)) {
            'jt', 'juta' => 1_000_000,
            'm' => 1_000_000,
            'ribu', 'k' => 1_000,
            default => 1,
        };

        return (string) (int) round($value * $multiplier);
    }

    private function normalize(string $content): string
    {
        $content = mb_strtolower(trim($content));

        return preg_replace('/\s+/', ' ', $content) ?? $content;
    }
}
