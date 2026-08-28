<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Rudisang\Mailbox\Capture\FailureInjector;
use Rudisang\Mailbox\Storage\MessageStore;
use Rudisang\Mailbox\Storage\Repair;
use Rudisang\Mailbox\Support\StoragePaths;

it('leaves either a complete visible capture or a repairable invisible orphan at every stage', function () {
    $stages = ['before_raw_write', 'after_raw_write', 'after_extract', 'after_rename', 'before_commit', 'after_commit', 'in_event', 'in_prune'];
    $store = app(MessageStore::class);
    $paths = app(StoragePaths::class);

    foreach ($stages as $stage) {
        app(FailureInjector::class)->reset();
        app(FailureInjector::class)->failAt($stage);
        $before = $store->count();

        try {
            Mail::mailer('local')->send([], [], fn ($m) => $m->from('a@example.com')->to('b@example.com')->subject($stage)->text('x'));
            $threw = false;
        } catch (Throwable) {
            $threw = true;
        }

        $after = $store->count();
        $committedStages = ['after_commit', 'in_event', 'in_prune'];

        expect($threw)->toBe(! in_array($stage, $committedStages, true), "stage {$stage}");
        expect($after - $before)->toBe(in_array($stage, $committedStages, true) ? 1 : 0, "stage {$stage}");

        foreach ($store->allIds() as $id) {
            expect($paths->raw($id))->toBeFile();
        }

        expect(app(Repair::class)->scan()['orphan_dirs'])->toBe([], "stage {$stage} left an orphan dir");
        expect(glob($paths->tmpDir().'/*') ?: [])->toBe([], "stage {$stage} left a tmp entry");
    }
});
