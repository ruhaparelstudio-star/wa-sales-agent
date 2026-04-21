<?php

use App\Modules\WhatsApp\Services\BaileysProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config()->set('services.baileys.base_url', 'http://baileys-svc:3001');
    config()->set('services.baileys.secret', 'test-secret');
});

test('sendDocument maps relative local disk paths to the sidecar storage mount', function () {
    Http::fake([
        'http://baileys-svc:3001/*' => Http::response(['message_id' => 'doc-1'], 200),
    ]);

    $provider = new BaileysProvider();
    $relativePath = 'tenants/1/pricelists/test.pdf';

    $provider->sendDocument('agent-1', '62812@s.whatsapp.net', $relativePath, 'test.pdf', 'doc-key-1', 'Pricelist terbaru kami');

    Http::assertSent(function ($request) use ($relativePath) {
        $data = $request->data();

        return $request->url() === 'http://baileys-svc:3001/agents/agent-1/send'
            && ($data['type'] ?? null) === 'document'
            && ($data['content'] ?? null) === 'Pricelist terbaru kami'
            && ($data['file_path'] ?? null) === '/app/storage/app/private/' . $relativePath
            && ($data['filename'] ?? null) === 'test.pdf';
    });
});

test('sendDocument maps absolute local disk paths to the sidecar storage mount', function () {
    Http::fake([
        'http://baileys-svc:3001/*' => Http::response(['message_id' => 'doc-2'], 200),
    ]);

    $provider = new BaileysProvider();
    $relativePath = 'tenants/1/pricelists/test.pdf';
    $absolutePath = Storage::path($relativePath);

    $provider->sendDocument('agent-1', '62812@s.whatsapp.net', $absolutePath, 'test.pdf', 'doc-key-2');

    Http::assertSent(function ($request) use ($relativePath) {
        $data = $request->data();

        return ($data['file_path'] ?? null) === '/app/storage/app/private/' . $relativePath;
    });
});

test('sendDocument throws when sidecar rejects the request', function () {
    Http::fake([
        'http://baileys-svc:3001/*' => Http::response(['error' => 'Document payload requires filePath and filename'], 422),
    ]);

    $provider = new BaileysProvider();

    expect(fn () => $provider->sendDocument(
        'agent-1',
        '62812@s.whatsapp.net',
        'tenants/1/pricelists/test.pdf',
        'test.pdf',
        'doc-key-3',
    ))->toThrow(\RuntimeException::class, 'HTTP 422');
});
