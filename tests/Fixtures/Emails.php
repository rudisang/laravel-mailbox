<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Tests\Fixtures;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\MessagePart;

final class Emails
{
    public static function base(): Email
    {
        return (new Email)
            ->from(new Address('sender@example.com', 'Sender'))
            ->to(new Address('to@example.com', 'To Person'))
            ->subject('Fixture subject');
    }

    public static function plain(): Email
    {
        return self::base()->text('Plain body text');
    }

    public static function html(): Email
    {
        return self::base()->html('<p>Hello <b>HTML</b></p>');
    }

    public static function alternative(): Email
    {
        return self::base()->text('Text alternative')->html('<p>HTML alternative</p>');
    }

    public static function mixedWithAttachments(): Email
    {
        return self::alternative()
            ->attach('%PDF-1.4 fake', 'invoice.pdf', 'application/pdf')
            ->attach("a,b\n1,2\n", 'data.csv', 'text/csv');
    }

    public static function relatedWithCid(): Email
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $email = self::base()->html('<p>Logo: <img src="cid:logo"></p>');
        $email->addPart((new FixtureDataPart($png, 'logo.png', 'image/png'))->asInline()->setContentId('logo'));

        return $email;
    }

    public static function nestedRfc822(): Email
    {
        $inner = self::plain()->subject('Inner message');
        $email = self::base()->text('Forwarded below');
        $email->addPart(new MessagePart($inner));

        return $email;
    }

    public static function calendar(): Email
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nSUMMARY:Sync\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        return self::alternative()->attach($ics, 'invite.ics', 'text/calendar');
    }

    public static function unicodeHeaders(): Email
    {
        return self::base()->from(new Address('sender@example.com', 'Sénder 日本'))->subject('Ünïcödé — 日本語 🚀')->text('Ünïcödé body');
    }

    public static function rfc2231Filename(): Email
    {
        return self::plain()->attach('x', 'résumé — 履歴書 🚀 with a very long file name that exceeds seventy six characters easily.txt', 'text/plain');
    }

    public static function duplicateHeaders(): Email
    {
        $email = self::plain();
        $email->getHeaders()->addTextHeader('X-Dup', 'one');
        $email->getHeaders()->addTextHeader('X-Dup', 'two');

        return $email;
    }

    public static function customHeaders(): Email
    {
        $email = self::plain();
        $email->getHeaders()->addTextHeader('X-Tag', 'billing');
        $email->getHeaders()->addTextHeader('X-Metadata-user_id', '42');
        $email->getHeaders()->addTextHeader('X-Custom', 'custom value');

        return $email;
    }

    public static function bccOnly(): Email
    {
        return (new Email)->from('sender@example.com')->bcc(new Address('hidden@example.com', 'Hidden'))->subject('Bcc only')->text('secret');
    }

    public static function manyParts(int $count): Email
    {
        $email = self::plain();
        for ($i = 0; $i < $count; $i++) {
            $email->attach('x'.$i, 'file'.$i.'.txt', 'text/plain');
        }

        return $email;
    }

    public static function eightBit(): Email
    {
        $email = self::base();
        $email->text('Ünïcödé 8bit', 'utf-8');
        $email->getHeaders()->addTextHeader('X-Encoding-Hint', '8bit');

        return $email;
    }

    public static function quotedPrintable(): Email
    {
        return self::base()->html('<p>'.str_repeat('Ünïcödé long line ', 30).'</p>');
    }

    public static function base64Body(): Email
    {
        return self::base()->attach(random_bytes(3000), 'blob.bin', 'application/octet-stream');
    }
}

/** @internal Keeps the brief's deliberately bare fixture CID on supported Symfony versions. */
final class FixtureDataPart extends DataPart
{
    private ?string $fixtureContentId = null;

    public function setContentId(string $cid): static
    {
        $this->fixtureContentId = $cid;

        return $this;
    }

    public function getContentId(): string
    {
        return $this->fixtureContentId ?? parent::getContentId();
    }

    public function hasContentId(): bool
    {
        return $this->fixtureContentId !== null || parent::hasContentId();
    }
}
