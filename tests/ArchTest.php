<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;
use Rudisang\Mailbox\Events\MessageCaptured;
use Rudisang\Mailbox\Mailbox;
use Rudisang\Mailbox\Storage\PartRecord;
use Rudisang\Mailbox\Testing\CapturedMessage;
use Rudisang\Mailbox\Testing\InteractsWithMailbox;
use Rudisang\Mailbox\Testing\MailboxTester;

arch()->preset()->php();

arch()->preset()->security();

arch('it will not use dd(), ddd(), env(), or exit()')
    ->expect(['dd', 'ddd', 'env', 'exit'])
    ->each->not->toBeUsed();

arch('the package source declares strict types')
    ->expect('Rudisang\Mailbox')
    ->toUseStrictTypes();

it('pins the documented public surface', function () {
    $source = realpath(__DIR__.'/../src');

    if ($source === false) {
        Assert::fail('Unable to resolve the package source directory.');
    }

    $public = [
        Mailbox::class,
        MessageCaptured::class,
        InteractsWithMailbox::class,
        MailboxTester::class,
        CapturedMessage::class,
        PartRecord::class,
    ];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source));
    $symbols = [];

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($source) + 1, -4);
        $symbol = 'Rudisang\\Mailbox\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

        if (! class_exists($symbol) && ! trait_exists($symbol)) {
            continue;
        }

        $symbols[] = $symbol;
        $reflection = new ReflectionClass($symbol);
        $docblock = $reflection->getDocComment() ?: '';
        $isException = str_starts_with($symbol, 'Rudisang\\Mailbox\\Exceptions\\');

        if (in_array($symbol, $public, true) || $isException) {
            Assert::assertStringNotContainsString('@internal', $docblock, $symbol.' must remain public.');

            continue;
        }

        Assert::assertStringContainsString('@internal', $docblock, $symbol.' must be marked internal.');
    }

    sort($symbols);
    Assert::assertNotEmpty($symbols);
    Assert::assertStringContainsString('@internal', (new ReflectionMethod(CapturedMessage::class, 'record'))->getDocComment() ?: '');
    Assert::assertStringContainsString('@internal', (new ReflectionMethod(MailboxTester::class, '__construct'))->getDocComment() ?: '');
});
