<?php

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

function main(): void 
{
    $input = enableRawMode();

    while (1) {
        $in = array($input);
        $out = $err = null;
        $seconds = 1;
        if (stream_select($in, $out, $err, $seconds) === false) die("Unalbe to selecton stdin\n");
        $bytes = 1;
        $c = fread($input, $bytes);
        if ($c === false) die("fread");
        
        if (IntlChar::iscntrl($c)) {
            printf("%d\r\n", ord($c));
        } else {
            printf("%d ('%s')\r\n", ord($c), $c);
        }
        if ($c === 'q') {
            break;
        }
    }

    exit(0);
}

main();