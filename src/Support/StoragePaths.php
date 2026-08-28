<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use RuntimeException;

final class StoragePaths
{
    public function __construct(public readonly string $root) {}

    public static function fromConfig(Repository $config, Application $app): self
    {
        $root = $config->get('mailbox.storage_path');

        if (! is_string($root) || $root === '') {
            $root = $app->storagePath('framework'.DIRECTORY_SEPARATOR.'mailbox');
        }

        return new self(rtrim($root, '/\\'));
    }

    public function index(): string
    {
        return $this->root.DIRECTORY_SEPARATOR.'index.sqlite';
    }

    public function lock(): string
    {
        return $this->root.DIRECTORY_SEPARATOR.'.lock';
    }

    public function messagesDir(): string
    {
        return $this->root.DIRECTORY_SEPARATOR.'messages';
    }

    public function tmpDir(): string
    {
        return $this->root.DIRECTORY_SEPARATOR.'tmp';
    }

    public function tmp(string $id): string
    {
        self::assertId($id);

        return $this->tmpDir().DIRECTORY_SEPARATOR.$id;
    }

    public function message(string $id): string
    {
        self::assertId($id);

        return $this->messagesDir().DIRECTORY_SEPARATOR.$id;
    }

    public function raw(string $id): string
    {
        self::assertId($id);

        return $this->message($id).DIRECTORY_SEPARATOR.'raw.eml';
    }

    public function partsDir(string $id): string
    {
        self::assertId($id);

        return $this->message($id).DIRECTORY_SEPARATOR.'parts';
    }

    public function part(string $id, string $partId): string
    {
        self::assertId($id);
        self::assertId($partId);

        return $this->partsDir($id).DIRECTORY_SEPARATOR.$partId.'.bin';
    }

    public static function isValidId(string $id): bool
    {
        return preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id) === 1;
    }

    public function ensureRoot(): void
    {
        // The mailbox is a private store containing message bodies and other secrets.
        // Windows ignores POSIX directory modes.
        foreach ([$this->root, $this->messagesDir(), $this->tmpDir()] as $dir) {
            if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
                throw new RuntimeException('Mailbox storage directory could not be created.');
            }
        }
    }

    private static function assertId(string $id): void
    {
        if (! self::isValidId($id)) {
            throw new InvalidArgumentException('Invalid mailbox identifier.');
        }
    }
}
