<?php

namespace App\Modules\AgentCore\Services;

use App\Modules\AgentCore\DTOs\InterpretationResult;

class IntentExtractionService
{
    public function extract(string $content): InterpretationResult
    {
        $normalized = $this->normalize($content);

        if ($normalized === '') {
            return new InterpretationResult('unclear', 'other', [], 0.0, 'rules');
        }

        if ($this->containsAny($normalized, ['stop', 'unsubscribe', 'jangan hubungi', 'jangan chat', 'hapus nomor'])) {
            return new InterpretationResult('opt_out', 'opt_out', [], 0.98, 'rules');
        }

        if ($this->containsAny($normalized, ['bukti transfer', 'bukti bayar', 'sudah transfer', 'udah transfer'])) {
            return new InterpretationResult('payment_inquiry', 'payment_proof', [], 0.96, 'rules');
        }

        if ($this->containsAny($normalized, ['dp', 'down payment', 'pelunasan', 'lunas', 'termin pembayaran', 'cara bayar', 'payment', 'pembayaran', 'transfer'])) {
            return new InterpretationResult('payment_inquiry', 'payment_inquiry', [], 0.92, 'rules');
        }

        if ($this->isBookingClarificationOrComplaint($normalized)) {
            return new InterpretationResult('objection', 'complaint', [], 0.91, 'rules');
        }

        if ($this->isPackageCommitment($normalized)) {
            return new InterpretationResult('booking_intent', 'ready_to_book', [], 0.94, 'rules');
        }

        if ($this->isExplicitBookingCommitment($normalized)) {
            return new InterpretationResult('booking_intent', 'ready_to_book', [], 0.95, 'rules');
        }

        if ($this->containsAny($normalized, ['available', 'availability', 'kosong', 'slot tanggal', 'cek tanggal', 'tanggalnya tersedia', 'tanggal available', 'tanggal available', 'tanggalnya kosong'])) {
            return new InterpretationResult('availability_inquiry', 'availability', [], 0.93, 'rules');
        }

        if ($this->containsAny($normalized, ['harga', 'price', 'pricelist', 'price list', 'daftar harga', 'biayanya', 'berapa harganya'])) {
            return new InterpretationResult('price_inquiry', 'tanya_harga', [], 0.88, 'rules');
        }

        if ($this->containsAny($normalized, ['paket', 'package', 'compare', 'bandingkan', 'silver', 'gold', 'platinum', 'isi paket'])) {
            $legacy = $this->containsAny($normalized, ['bandingkan', 'compare', 'lebih bagus mana'])
                ? 'bandingkan_paket'
                : 'tanya_paket';

            return new InterpretationResult('package_inquiry', $legacy, [], 0.86, 'rules');
        }

        if ($this->containsAny($normalized, ['mahal', 'kemahalan', 'keberatan', 'kurang cocok', 'kecewa', 'complaint', 'komplain', 'complain'])) {
            return new InterpretationResult('objection', 'complaint', [], 0.9, 'rules');
        }

        if ($this->containsAny($normalized, ['follow up', 'followup', 'lanjutan', 'jadi gimana', 'udah ada kabar', 'ada kabar', 'masih lanjut', 'masih bisa dibantu'])) {
            return new InterpretationResult('follow_up', 'other', [], 0.8, 'rules');
        }

        if ($this->isGreetingOnly($normalized)) {
            return new InterpretationResult('greeting', 'greeting', [], 0.84, 'rules');
        }

        return new InterpretationResult('unclear', 'other', [], 0.35, 'rules');
    }

    private function isGreetingOnly(string $content): bool
    {
        return preg_match('/^(halo|hai|hi|hello|pagi|siang|sore|malam|permisi|halo kak|hai kak|hi kak)[!. ]*$/u', trim($content)) === 1;
    }

    private function containsAny(string $content, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($content, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isPackageCommitment(string $content): bool
    {
        return preg_match('/\b(ambil|pilih|mau|jadi)\s+paket\s+(silver|gold|platinum|premium|basic)\b/u', $content) === 1
            || preg_match('/\bpaket\s+(silver|gold|platinum|premium|basic)\s+(aja|saja|deh|yang saya ambil|yang aku ambil)\b/u', $content) === 1;
    }

    private function isExplicitBookingCommitment(string $content): bool
    {
        if ($this->isBookingClarificationOrComplaint($content) || $this->isBookingQuestion($content)) {
            return false;
        }

        return preg_match('/\b(saya|aku|kami|kita)?\s*(mau|ingin|siap|jadi|confirm|konfirmasi|oke)\s+(lanjut\s+ke\s+)?booking\b/u', $content) === 1
            || preg_match('/\b(boleh|bisa)\s+lanjut\s+booking\b/u', $content) === 1
            || preg_match('/\b(jadi|oke|siap)\s+booking\s+aja\b/u', $content) === 1
            || preg_match('/\b(book|reserve)\s+sekarang\b/u', $content) === 1
            || preg_match('/\b(lock|kunci)\s+tanggal\b/u', $content) === 1;
    }

    private function isBookingClarificationOrComplaint(string $content): bool
    {
        return preg_match('/\b(kenapa|kok|katanya|bukannya|lah)\b.*\bbooking\b/u', $content) === 1
            || preg_match('/\bbooking\b.*\b(kenapa|kok|katanya|bukannya)\b/u', $content) === 1
            || preg_match('/\b(langsung|langsung ke)\s+booking\b/u', $content) === 1
            || preg_match('/\b(mau|tolong)\s+(jelaskan|jelasin)\b/u', $content) === 1;
    }

    private function isBookingQuestion(string $content): bool
    {
        return str_contains($content, '?')
            && preg_match('/\b(booking|book|reserve|dp|bayar|pembayaran)\b/u', $content) === 1;
    }

    private function normalize(string $content): string
    {
        $content = mb_strtolower(trim($content));
        $content = preg_replace('/\s+/', ' ', $content) ?? $content;
        $content = str_replace(['?', '!', ','], ' ', $content);
        $content = preg_replace('/\s+/', ' ', $content) ?? $content;

        return $content;
    }
}
