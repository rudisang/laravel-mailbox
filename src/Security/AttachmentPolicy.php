<?php

declare(strict_types=1);

namespace Rudisang\Mailbox\Security;

use finfo;
use Normalizer;
use Symfony\Component\HttpFoundation\HeaderUtils;

final class AttachmentPolicy
{
    /** @var non-empty-list<non-empty-string> */
    public const INLINE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp'];

    public function inlineType(string $path): ?string
    {
        $type = (new finfo(FILEINFO_MIME_TYPE))->file($path);

        return is_string($type) && in_array($type, self::INLINE_TYPES, true) ? $type : null;
    }

    public function safeFilename(?string $name, string $fallback): string
    {
        $name = (string) $name;

        if (class_exists(Normalizer::class) && ! Normalizer::isNormalized($name)) {
            $name = Normalizer::normalize($name) ?: $name;
        }

        $withoutControls = preg_replace('/[\x00-\x1f\x7f]/u', '', $name);
        $name = is_string($withoutControls) ? $withoutControls : '';
        $name = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '', $name);
        $name = trim(strtr($name, ['..' => '']), ". \t");

        if ($name === '') {
            return $fallback;
        }

        if (mb_strlen($name) > 120) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $extensionLength = mb_strlen($extension);

            if ($extension !== '' && $extensionLength < 120) {
                $name = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, 119 - $extensionLength).'.'.$extension;
            } else {
                $name = mb_substr($name, 0, 120);
            }
        }

        return $name;
    }

    public function disposition(string $filename): string
    {
        $fallback = preg_replace('/[^\x20-\x7e]/', '_', $filename) ?? 'attachment';
        $fallback = str_replace(['"', '\\', '%'], '_', $fallback);

        return HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $filename,
            $fallback !== '' ? $fallback : 'attachment',
        );
    }
}
