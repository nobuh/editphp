<?php

function enableRawMode(): void
{
    exec('stty -echo -icanon');
    exec('stty -isig');     // disable CTRL-C, Z
    exec('stty -ixon');     // disable CTRL-S, Q
    exec('stty -iexten');   // disable CTRL-V, O
    exec('stty -icrnl');    // fix CTRL-M
    exec('stty -opost');
    exec('stty -brkint -inpck -istrip');    // disable misc
    exec('stty cs8');
  
    register_shutdown_function('disableRawMode');
}

function disableRawMode(): void
{
    exec('stty sane');
}

function main(): void 
{
    $stdin = fopen('php://stdin', 'r');

    enableRawMode();

    while (1) {

        $dummy = null;
        $arr = array($stdin);
            if (stream_select($arr, $dummy, $dummy, 0, 800) === false) {
            fwrite(STDERR, "Unable to select timeout on the stream." . PHP_EOL);
            exit(1);
        }  

        $c = fread($stdin, 1);
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