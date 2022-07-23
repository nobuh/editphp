<?php

function CTRL_KEY(string $k): int
{
    return ord($k) & 0x1f;
}

function enableRawMode(): mixed
{
    $stdin = fopen('php://stdin', 'r');
    if ($stdin === false) die("fopen");
    if (!stream_set_blocking($stdin, false)) die("stream_set_blocking");

    exec('stty -echo -icanon');
    exec('stty -isig');     // disable CTRL-C, Z
    exec('stty -ixon');     // disable CTRL-S, Q
    exec('stty -iexten');   // disable CTRL-V, O
    exec('stty -icrnl');    // fix CTRL-M
    exec('stty -opost');
    exec('stty -brkint -inpck -istrip');    // disable misc
    exec('stty cs8');
  
    register_shutdown_function('disableRawMode');

    return $stdin;
}

function disableRawMode(): void
{
    exec('stty sane');
}

function editorReadKey(mixed $input): string
{
    $in = array($input);
    $out = $err = null;
    $seconds = 1;
    if (stream_select($in, $out, $err, $seconds) === false) die("Unalbe to selecton stdin\n");

    $bytes = 1;
    $c = fread($input, $bytes);
    if ($c === false) die("fread");

    return $c;
}

function editorProcessKeypress(mixed $input): void
{
    $c = editorReadKey($input);

    switch (ord($c)) {
        case (CTRL_KEY('q')):
            exit(0);
            break;
    }
}

function editorRefreshScreen(): void
{
    fwrite(STDOUT, "\x1b[2J", 4);
    fwrite(STDOUT, "\x1b[H", 3);
}

function main(): void 
{
    $input = enableRawMode();

    while (1) {
        editorRefreshScreen();
        editorProcessKeypress($input);
    }

    exit(0);
}

main();