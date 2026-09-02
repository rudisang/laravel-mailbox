<?php

declare(strict_types=1);

use Rudisang\Mailbox\Storage\MaintenanceLock;

it('runs callbacks under shared and exclusive locks and returns their value', function () {
    $lock = new MaintenanceLock(sys_get_temp_dir().'/mailbox-lock-'.bin2hex(random_bytes(4)));

    expect($lock->shared(fn () => 'shared'))->toBe('shared')
        ->and($lock->exclusive(fn () => 'exclusive'))->toBe('exclusive');
});

it('skips a non-blocking exclusive callback while another process holds the lock', function () {
    $path = sys_get_temp_dir().'/mailbox-lock-'.bin2hex(random_bytes(4));
    $holder = proc_open([PHP_BINARY, '-r', '$h=fopen($argv[1],"c+");flock($h,LOCK_SH);echo "held\n";fflush(STDOUT);fgets(STDIN);', $path], [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
    fgets($pipes[1]); // wait until the child holds the shared lock
    $lock = new MaintenanceLock($path);

    expect($lock->exclusive(fn () => 'ran', false))->toBeNull();

    fwrite($pipes[0], "\n");
    proc_close($holder);
    expect($lock->exclusive(fn () => 'ran', false))->toBe('ran');
});
