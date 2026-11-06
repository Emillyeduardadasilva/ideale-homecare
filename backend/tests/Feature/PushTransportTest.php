<?php

namespace Tests\Feature;

use App\Services\PushTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\VAPID;
use Tests\TestCase;

class PushTransportTest extends TestCase
{
    public function test_real_library_encrypts_payload_and_sends_vapid_headers(): void
    {
        $keys = VAPID::createVapidKeys();
        config(['care.push.subject' => 'mailto:push@example.test', 'care.push.public_key' => $keys['publicKey'], 'care.push.private_key' => $keys['privateKey']]);
        $history = [];
        $handler = HandlerStack::create(new MockHandler([new Response(201)]));
        $handler->push(Middleware::history($history));
        $client = new Client(['handler' => $handler, 'allow_redirects' => false]);
        $subscriber = VAPID::createVapidKeys();
        $result = (new PushTransport($client))->send(['endpoint' => 'https://fcm.googleapis.com/fcm/send/test', 'keys' => ['p256dh' => $subscriber['publicKey'], 'auth' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=')], 'contentEncoding' => 'aes128gcm'], 123);
        $this->assertSame('sent', $result);
        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertStringStartsWith('vapid ', $request->getHeaderLine('Authorization'));
        $this->assertSame('aes128gcm', $request->getHeaderLine('Content-Encoding'));
        $this->assertStringNotContainsString('novo lembrete', (string) $request->getBody());
    }
}
