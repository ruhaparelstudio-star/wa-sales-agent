<?php

namespace App\Modules\Conversations\Enums;

enum MessageType: string
{
    case Text = 'text';
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Document = 'document';
    case Sticker = 'sticker';
    case Reaction = 'reaction';
    case System = 'system';

    public function isMedia(): bool
    {
        return in_array($this, [self::Image, self::Video, self::Audio, self::Document]);
    }

    public function autoResponse(): string
    {
        return match($this) {
            self::Image    => 'Gambar sudah kami terima, terima kasih!',
            self::Video    => 'Video sudah kami terima, terima kasih!',
            self::Audio    => 'Pesan suara sudah kami terima, terima kasih!',
            self::Document => 'Dokumen sudah kami terima, terima kasih!',
            self::Sticker  => '',
            self::Reaction => '',
            default        => '',
        };
    }
}
