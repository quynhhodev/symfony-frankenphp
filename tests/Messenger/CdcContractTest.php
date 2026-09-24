<?php

namespace App\Tests\Messenger;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Stamp\SerializedMessageStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Hợp đồng giữa redpanda-connect và Symfony. Mọi envelope mà unit test Bloblang mong đợi
 * phải decode được thành đúng message.
 *
 * Lệch hợp đồng ở môi trường thật là mất message: Symfony nack không requeue, message
 * không vào `failed`. Vì vậy test đọc thẳng file test Bloblang thay vì chép JSON sang đây.
 */
final class CdcContractTest extends KernelTestCase
{
    private const BLOBLANG_TESTS = __DIR__.'/../../redpanda-connect/app_benthos_test.yaml';

    #[DataProvider('envelopes')]
    public function testEnvelopeDecodesToMessage(string $type, array $body): void
    {
        /** @var SerializerInterface $serializer */
        $serializer = self::getContainer()->get('messenger.transport.symfony_serializer');

        $envelope = $serializer->decode([
            'body' => json_encode($body, JSON_THROW_ON_ERROR),
            'headers' => ['type' => $type],
        ]);

        self::assertInstanceOf($type, $envelope->getMessage());

        // Encode ngược lại để bắt key bị bỏ qua âm thầm. Ví dụ body gửi `mail` trong khi
        // constructor chờ `email` (optional): vẫn decode được, chỉ là email thành null.
        // Phải bỏ SerializedMessageStamp mà decode() gắn vào; còn nó thì encode() trả
        // nguyên văn body gốc, và assertion này luôn xanh.
        $encoded = $serializer->encode($envelope->withoutAll(SerializedMessageStamp::class));
        $roundTrip = json_decode($encoded['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertEquals($body, $roundTrip);
    }

    public static function envelopes(): iterable
    {
        foreach (Yaml::parseFile(self::BLOBLANG_TESTS)['tests'] as $test) {
            foreach ($test['output_batches'] as $batch) {
                foreach ($batch as $i => $output) {
                    yield "{$test['name']} #{$i}" => [$output['metadata_equals']['type'], $output['json_equals']];
                }
            }
        }
    }
}
