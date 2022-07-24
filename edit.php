<?php

class editorConfig
{
    public mixed $stdin;
    public int $screenrows;
    public int $screencols;

    function __construct()
    {
        $this->stdin = fopen('php://stdin', 'r');    
        if ($this->stdin === false) die("fopen");
        $this->screenrows = 0;
        $this->screencols = 0;
    }
}
$E = new editorConfig();

function CTRL_KEY(string $k): int
{
    return ord($k) & 0x1f;
}

function enableRawMode(): void
{
    global $E;
    if (!stream_set_blocking($E->stdin, false)) die("stream_set_blocking");
    
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
    fwrite(STDOUT, "\x1b[2J", 4);
    fwrite(STDOUT, "\x1b[H", 3);

    exec('stty sane');
}

function editorReadKey(): string
{
    global $E;
    $in = array($E->stdin);
    $out = $err = null;
    $seconds = 1;
    if (stream_select($in, $out, $err, $seconds) === false) die("Unalbe to selecton stdin\n");

    $bytes = 1;
    $c = fread($E->stdin, $bytes);
    if ($c === false) die("fread");

    return $c;
}

function getWindowSize(int &$rows, int &$cols): int {
    if (exec('stty size', $output, $result) === false) {
        return -1;
    }
    $size = explode(' ', $output[0]);
    $rows = $size[0];
    $cols = $size[1];
    return 0;
}

function editorProcessKeypress(): void
{
    global $E;
    $c = editorReadKey();

    switch (ord($c)) {
        case (CTRL_KEY('q')):
            fwrite(STDOUT, "\x1b[2J", 4);
            fwrite(STDOUT, "\x1b[H", 3);
            exit(0);
            break;
    }
}

function editorDrawRows() 
{
    global $E;
    for ($y = 0; $y < $E->screenrows; $y++) {
      fwrite(STDOUT, "~\r\n", 3);
    }
}
  
function editorRefreshScreen(): void
{
    fwrite(STDOUT, "\x1b[2J", 4);
    fwrite(STDOUT, "\x1b[H", 3);
    editorDrawRows();
    fwrite(STDOUT, "\x1b[H", 3);
}

function initEditor(): void 
{
    global $E;
    if (getWindowSize($E->screenrows, $E->screencols) == -1) die("getWindowSize");
}
  
function main(): void 
{
    enableRawMode();
    initEditor();

    while (1) {
        editorRefreshScreen();
        editorProcessKeypress();
    }

    exit(0);
}

main();