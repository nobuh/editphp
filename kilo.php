<?php

const KILO_VERSION = "0.0.0";

class erow 
{
    public int $size;
    public string $chars;
}

class editorConfig
{
    public int $cx;
    public int $cy;
    public int $rowoff;
    public int $screenrows;
    public int $screencols;
    public int $numrows;
    public array $row;
    public mixed $stdin;

    function __construct()
    {
        $this->stdin = fopen('php://stdin', 'r');    
        if ($this->stdin === false) die("fopen");
        $this->cx = 0;
        $this->cy = 0;
        $this->rowoff = 0;
        $this->screenrows = 0;
        $this->screencols = 0;
        $this->numrows = 0;
        $this->row = [];
    }
}
$E = new editorConfig();

// append buffer
class abuf
{
    public string $b;
    public int $len;

    function __construct()
    {
        $this->b = '';
        $this->len = 0;
    }
}

function abAppend(abuf $ab, string $s, int $len)
{
    $ab->b .= $s;
    $ab->len += $len;
}

function abFree(abuf $ab)
{
    $ab = null;
}

function CTRL_KEY(string $k): int
{
    return ord($k) & 0x1f;
}

const ARROW_LEFT    = 1000;
const ARROW_RIGHT   = 1001;
const ARROW_UP      = 1002;
const ARROW_DOWN    = 1003;
const DEL_KEY       = 1004;
const HOME_KEY      = 1005;
const END_KEY       = 1006;
const PAGE_UP       = 1007;
const PAGE_DOWN     = 1008;

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

function editorReadKey(): int
{
    global $E;

    $in = array($E->stdin);
    $out = $err = null;
    $seconds = 1;
    if (stream_select($in, $out, $err, $seconds) === false) die("Unalbe to selecton stdin\n");

    $bytes = 1;
    $c = fread($E->stdin, $bytes);
    if ($c === false) die("fread");

    if (ord($c) === 0x1b) {
        $seq = [];
        if (stream_select($in, $out, $err, $seconds) === false) die("Unalbe to selecton stdin\n");
        $seq[0] = fread($E->stdin, $bytes);
        if (stream_select($in, $out, $err, $seconds) === false) die("Unalbe to selecton stdin\n");
        $seq[1] = fread($E->stdin, $bytes);
        if ($seq[0] === false || $seq[1] === false) return 0x1b;
        if ($seq[0] === '[') {
            if ((ord($seq[1]) >= ord('0')) && (ord($seq[1]) <= ord('9'))) {
                if (stream_select($in, $out, $err, $seconds) === false) die("Unalbe to selecton stdin\n");
                $seq[2] = fread($E->stdin, $bytes);
                if ($seq[2] === '~') {
                    switch ($seq[1]) {
                        case '1': return HOME_KEY;
                        case '3': return DEL_KEY;
                        case '4': return END_KEY;
                        case '5': return PAGE_UP;
                        case '6': return PAGE_DOWN;
                        case '7': return HOME_KEY;
                        case '8': return END_KEY;
                    }
                }                    
            } else {
                switch ($seq[1]) {
                    case 'A': return ARROW_UP;
                    case 'B': return ARROW_DOWN;
                    case 'C': return ARROW_RIGHT;
                    case 'D': return ARROW_LEFT;
                    case 'H': return HOME_KEY;
                    case 'F': return END_KEY;    
                }
            }
        } else if ($seq[0] === 'O') {
            switch ($seq[1]) {
                case 'H': return HOME_KEY;
                case 'F': return END_KEY;
            }
        }

        return 0x1b;        
    } else {
        return ord($c);
    }
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

function editorAppendRow(string $s, int $len): void
{
    global $E;

    $at = $E->numrows;
    $E->row[$at] = new erow();
    $E->row[$at]->size = $len;
    $E->row[$at]->chars = $s;
    $E->numrows++;
}

function editorOpen(string $filename): void 
{
    global $E;

    $fp = fopen($filename, 'r');
    if ($fp === false) die("fopen");

    while ($line = fgets($fp)) {
        $line = rtrim($line);
        editorAppendRow($line, strlen($line));
    };

    fclose($fp);
}

function editorMoveCursor(int $key): void 
{
    global $E;
    switch ($key) {
      case ARROW_LEFT:
        if ($E->cx !== 0) {
            $E->cx--;
        }
        break;
      case ARROW_RIGHT:
        if ($E->cx !== $E->screencols - 1) {
            $E->cx++;
        }
        break;
      case ARROW_UP:
        if ($E->cy !== 0) {
            $E->cy--;
        }
        break;
      case ARROW_DOWN:
        if ($E->cy < $E->numrows) {
            $E->cy++;
        }
        break;
    }
  }

function editorProcessKeypress(): void
{
    global $E;
    $c = editorReadKey();

    switch ($c) {
        case CTRL_KEY('q'):
            fwrite(STDOUT, "\x1b[2J", 4);
            fwrite(STDOUT, "\x1b[H", 3);
            exit(0);
            break;
        case HOME_KEY:
            $E->cx = 0;
            break;
        case END_KEY:
            $E->cx = $E->screencols - 1;
            break;
        case PAGE_UP:
        case PAGE_DOWN:
            $times = $E->screenrows;
            while ($times--) {
                if ($c === PAGE_UP) {
                    editorMoveCursor(ARROW_UP);
                } else {
                    editorMoveCursor(ARROW_DOWN);
                }
            }
            break;
        case ARROW_UP:
        case ARROW_LEFT:
        case ARROW_DOWN:
        case ARROW_RIGHT:
            editorMoveCursor($c);
    }
}

function editorScroll(): void 
{
    global $E;

    if ($E->cy < $E->rowoff) {
        $E->rowoff = $E->cy;
    }
    if ($E->cy >= $E->rowoff + $E->screenrows) {
        $E->rowoff = $E->cy - $E->screenrows + 1;
    }
}

function editorDrawRows(abuf $ab) 
{
    global $E;
    for ($y = 0; $y < $E->screenrows; $y++) {
        $filerow = $y + $E->rowoff;
        if ($filerow >= $E->numrows) {
            if ($E->numrows === 0 && $y === (int)floor($E->screenrows / 3)) {
                $welcome = sprintf("Kilo editor -- version %s", KILO_VERSION);
                $welcomelen = strlen($welcome);
                if ($welcomelen > $E->screencols) $welcomelen = $E->screencols;
                $padding = (int)floor(($E->screencols - $welcomelen) / 2);
                if ($padding) {
                    abAppend($ab, "~", 1);
                    $padding--;
                }
                while ($padding--) abAppend($ab, " ", 1);
                abAppend($ab, $welcome, $welcomelen);
            } else {
                abAppend($ab, "~", 1);
            }
        } else {
            $len = $E->row[$filerow]->size;
            if ($len > $E->screencols) $len = $E->screencols;
            abAppend($ab, $E->row[$filerow]->chars, $len);
        }

        abAppend($ab, "\x1b[K", 3);
        if ($y < $E->screenrows - 1) {
            abAppend($ab, "\r\n", 2);
        }
    }
}
  
function editorRefreshScreen(): void
{
    global $E;

    editorScroll();

    $ab = new abuf();

    abAppend($ab, "\x1b[?25l", 6);
    abAppend($ab, "\x1b[H", 3);

    editorDrawRows($ab);
    $buf = sprintf("\x1b[%d;%dH", ($E->cy - $E->rowoff) + 1, $E->cx + 1);
    abAppend($ab, $buf, strlen($buf));

    abAppend($ab, "\x1b[?25h", 6);

    var_dump($ab);

    fwrite(STDOUT, $ab->b, $ab->len);
    abFree($ab);
}

function initEditor(): void 
{
    global $E;
    if (getWindowSize($E->screenrows, $E->screencols) == -1) die("getWindowSize");
}
  
function main(): void 
{
    global $argc, $argv;

    enableRawMode();
    initEditor();
    if ($argc >= 2) {
        editorOpen($argv[1]);
    }

    while (1) {
        editorRefreshScreen();
        editorProcessKeypress();
    }

    exit(0);
}

main();