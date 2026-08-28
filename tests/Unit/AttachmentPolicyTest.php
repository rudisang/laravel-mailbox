<?php

declare(strict_types=1);

use Rudisang\Mailbox\Security\AttachmentPolicy;

beforeEach(fn () => $this->policy = new AttachmentPolicy);

it('allows only sniffed raster images inline', function () {
    $png = tempnam(sys_get_temp_dir(), 'mb');
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    $svg = tempnam(sys_get_temp_dir(), 'mb');
    file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
    $fake = tempnam(sys_get_temp_dir(), 'mb');
    file_put_contents($fake, 'not a png');

    expect($this->policy->inlineType($png))->toBe('image/png')
        ->and($this->policy->inlineType($svg))->toBeNull()
        ->and($this->policy->inlineType($fake))->toBeNull();
});

it('builds safe filenames and dispositions', function () {
    expect($this->policy->safeFilename("../../etc/passwd\r\nX-Injected: 1.html", 'part.bin'))->toBe('etcpasswdX-Injected 1.html')
        ->and($this->policy->safeFilename(null, 'part.bin'))->toBe('part.bin')
        ->and($this->policy->safeFilename('', 'part.bin'))->toBe('part.bin')
        ->and(strlen($this->policy->safeFilename(str_repeat('a', 300).'.pdf', 'x')))->toBeLessThanOrEqual(120)
        ->and($this->policy->safeFilename('CON.exe', 'x'))->toBe('_CON.exe')
        ->and($this->policy->safeFilename('lpt9.txt', 'x'))->toBe('_lpt9.txt')
        ->and($this->policy->safeFilename('COM10.txt', 'x'))->toBe('COM10.txt');
    expect(mb_strlen($this->policy->safeFilename('x.'.str_repeat('e', 300), 'x')))->toBeLessThanOrEqual(120);
    $disposition = $this->policy->disposition('résumé — 履歴書 🚀.txt');
    expect($disposition)->toStartWith('attachment; filename=')->toContain("filename*=utf-8''r%C3%A9sum%C3%A9")->not->toContain("\n");
});
