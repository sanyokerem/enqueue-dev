<?php

namespace Enqueue\Tests\Client\Extension;

use Enqueue\Client\DriverInterface;
use Enqueue\Client\Extension\PrepareBodyExtension;
use Enqueue\Client\ExtensionInterface;
use Enqueue\Client\Message;
use Enqueue\Client\PreSend;
use Enqueue\Client\ProducerInterface;
use Enqueue\Tests\Mocks\JsonSerializableObject;
use PHPUnit\Framework\TestCase;

class PrepareBodyExtensionTest extends TestCase
{
    public function testShouldImplementExtensionInterface()
    {
        $rc = new \ReflectionClass(PrepareBodyExtension::class);

        $this->assertTrue($rc->implementsInterface(ExtensionInterface::class));
    }

    public function testCouldConstructedWithoutAnyArguments()
    {
        new PrepareBodyExtension();
    }

    /**
     * @dataProvider provideMessages
     *
     * @param mixed      $body
     * @param null|mixed $contentType
     */
    public function testShouldSendStringUnchangedAndAddPlainTextContentTypeIfEmpty(
        $body,
        $contentType,
        string $expectedBody,
        string $expectedContentType
    ) {
        $message = new Message($body);
        $message->setContentType($contentType);

        $context = $this->createDummyPreSendContext('aTopic', $message);

        $extension = new PrepareBodyExtension();

        $extension->onPreSendEvent($context);

        $this->assertSame($expectedBody, $message->getBody());
        $this->assertSame($expectedContentType, $message->getContentType());
    }

    public function testThrowIfBodyIsObject()
    {
        $message = new Message(new \stdClass());

        $context = $this->createDummyPreSendContext('aTopic', $message);

        $extension = new PrepareBodyExtension();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The message\'s body must be either null, scalar, array or object (implements \JsonSerializable). Got: stdClass');

        $extension->onPreSendEvent($context);
    }

    public function testThrowIfBodyIsArrayWithObjectsInsideOnSend()
    {
        $message = new Message(['foo' => new \stdClass()]);

        $context = $this->createDummyPreSendContext('aTopic', $message);

        $extension = new PrepareBodyExtension();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The message\'s body must be an array of scalars. Found not scalar in the array: stdClass');

        $extension->onPreSendEvent($context);
    }

    public function testShouldThrowExceptionIfBodyIsArrayWithObjectsInSubArraysInsideOnSend()
    {
        $message = new Message(['foo' => ['foo' => new \stdClass()]]);

        $context = $this->createDummyPreSendContext('aTopic', $message);

        $extension = new PrepareBodyExtension();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The message\'s body must be an array of scalars. Found not scalar in the array: stdClass');

        $extension->onPreSendEvent($context);
    }

    public static function provideMessages()
    {
        yield ['theBody', null, 'theBody', 'text/plain'];

        yield ['theBody', 'foo/foo', 'theBody', 'foo/foo'];

        yield [12345, null, '12345', 'text/plain'];

        yield [12345, 'foo/foo', '12345', 'foo/foo'];

        yield [12.345, null, '12.345', 'text/plain'];

        yield [12.345, 'foo/foo', '12.345', 'foo/foo'];

        yield [true, null, '1', 'text/plain'];

        yield [true, 'foo/foo', '1', 'foo/foo'];

        yield [null, null, '', 'text/plain'];

        yield [null, 'foo/foo', '', 'foo/foo'];

        yield [['foo' => 'fooVal'], null, '{"foo":"fooVal"}', 'application/json'];

        yield [['foo' => 'fooVal'], 'foo/foo', '{"foo":"fooVal"}', 'foo/foo'];

        yield [new JsonSerializableObject(), null, '{"foo":"fooVal"}', 'application/json'];

        yield [new JsonSerializableObject(), 'foo/foo', '{"foo":"fooVal"}', 'foo/foo'];
    }

    private function createDummyPreSendContext($commandOrTopic, $message): PreSend
    {
        return new PreSend(
            $commandOrTopic,
            $message,
            $this->createMock(ProducerInterface::class),
            $this->createMock(DriverInterface::class)
        );
    }
}
