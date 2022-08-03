<?php
declare(strict_types = 1);

const KILO_VERSION = "0.1.0 ";
const KILO_TAB_STOP = 8;
const KILO_QUIT_TIMES = 3;

class erow
{
    public int $size;
    public int $rsize;
    public string $chars;
    public string $render;
}

class editorConfig
{
    public int $cx;
    public int $cy;
    public int $rx;
    public int $rowoff;
    public int $coloff;
    public int $screenrows;
    public int $screencols;
    public int $numrows;
    public array $row;
    public int $dirty;
    public string $filename;
    public string $statusmsg;
    public int $statusmsg_time;

    public mixed $stdin;
    public int $quit_times;

    function __construct()
    {
        $this->stdin = fopen('php://stdin', 'r');    
        if ($this->stdin === false) die("fopen");
        $this->quit_times = KILO_QUIT_TIMES;
   
        $this->cx = 0;
        $this->cy = 0;
        $this->rx = 0;
        $this->rowoff = 0;
        $this->coloff = 0;
        $this->screenrows = 0;
        $this->screencols = 0;
        $this->numrows = 0;
        $this->row = [];
        $this->dirty = 0;
        $this->filename = "";
        $this->statusmsg = "\0";
        $this->statusmsg_time = 0;
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
    $ab->b .= substr($s, 0, $len);
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

const BACKSPACE     = 127;
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
    fwrite(STDOUT, "\e[2J", 4);
    fwrite(STDOUT, "\e[H", 3);

    exec('stty sane');
}

function editorReadKey(): int
{
    global $E;

    $in = array($E->stdin);
    $out = $err = null;
    $seconds = 1;
    if (stream_select($in, $out, $err, $seconds) === false) die("stream select\n");

    $bytes = 1;
    $c = fread($E->stdin, $bytes);
    if ($c === false) die("fread");

    if (ord($c) === 0x1b) {
        $seq = [];
        if (stream_select($in, $out, $err, $seconds) === false) die("Unalbe to select on stdin\n");
        $seq[0] = fread($E->stdin, $bytes);

        $in2nd = array($E->stdin); // For missing array error for 2nd $in
        if (stream_select($in2nd, $out, $err, $seconds) === false) die("Unalbe to select on stdin\n");
        $seq[1] = fread($E->stdin, $bytes);

        if ($seq[0] === false || $seq[1] === false) return 0x1b;
        if ($seq[0] === '[') {
            if ((ord($seq[1]) >= ord('0')) && (ord($seq[1]) <= ord('9'))) {
                if (stream_select($in, $out, $err, $seconds) === false) 
                    die("Unalbe to selecton stdin\n");
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
    $rows = (int)$size[0];
    $cols = (int)$size[1];
    return 0;
}

function editorRowCxToRx(erow $row, int $cx): int
{
    $rx = 0;
    for ($j = 0; $j < $cx; $j++) {
        if (substr($row->chars, $j, 1) === "\t") {
            $rx += (KILO_TAB_STOP - 1) - ($rx % KILO_TAB_STOP);
        } 
        $rx++;
    }
    return $rx;
}

function editorUpdateRow(erow $row) 
{
    $idx = 0;
    $row->render = "";
    for ($j = 0; $j < $row->size; $j++) {
        if (substr($row->chars, $j, 1) === "\t") {
            $idx++;
            $row->render .= " ";
            while ($idx % KILO_TAB_STOP !== 0) {
                $idx++;
                $row->render .= " ";
            }
        } else {
            $idx++;
            $row->render .= substr($row->chars, $j, 1);
        }
    }

    $row->render .= "\0";
    $row->rsize = strlen($row->render);
}

function editorInsertRow(int $at, string $s, int $len): void
{
    global $E;

    if ($at < 0 || $at > $E->numrows) return;
    array_splice($E->row, $at, 0, "");

    $E->row[$at] = new erow();
    $E->row[$at]->size = $len;
    $E->row[$at]->chars = $s . "\0";
    $E->row[$at]->rsize = 0;
    $E->row[$at]->render = "";
    editorUpdateRow($E->row[$at]);

    $E->numrows++;
    $E->dirty++;
}

function editorDelRow(int $at)
{
    global $E;

    if ($at < 0 || $at >= $E->numrows) return;
    array_splice($E->row, $at, 1);
    $E->numrows--;
    $E->dirty++;
}

function editorRowInsertChar(erow $row, int $at, int $c): void 
{
    global $E;

    if ($at < 0 || $at > $row->size) $at = $row->size;
    $s = substr_replace($row->chars, chr($c), $at, 0); // 0 for inserting
    $row->chars = $s;
    $row->size++;
    editorUpdateRow($row);
    $E->dirty++;
}

function editorRowAppendString(erow $row, string $s, int $len): void
{
    global $E;

    $row->chars = rtrim($row->chars, "\0") . $s . "\0";
    $row->size += $len;
    editorUpdateRow($row);
    $E->dirty++;
}

function editorRowDelChar(erow $row, int $at): void
{
    global $E;

    if ($at < 0 || $at >= $row->size) return;
    $s = substr_replace($row->chars, "", $at, 0);
    $row->chars = $s;
    $row->size--;
    editorUpdateRow($row);
    $E->dirty++;
}

function editorInsertChar(int $c): void 
{
    global $E;

    if ($E->cy === $E->numrows) {
      editorInsertRow($E->numrows, "", 0);
    }
    editorRowInsertChar($E->row[$E->cy], $E->cx, $c);
    $E->cx++;
}

function editorInsertNewLine(): void
{
    global $E;

    if ($E->cx === 0) {
        editorInsertRow($E->cy, "", 0);
    } else {
        $row = $E->row[$E->cy];
        editorInsertRow($E->cy + 1, $row->chars[$E->cx], $row->size - $E->cx);
        $row = $E->row[$E->cy];
        $row->size = $E->cx;
        editorUpdateRow($row);
    }
    $E->cy++;
    $E->cx = 0;
}

function editorDelChar(): void 
{
    global $E;

    if ($E->cy === $E->numrows) return;
    if ($E->cx === 0 && $E->cy === 0) return;

    $row = $E->row[$E->cy];
    if ($E->cx > 0) {
        editorRowDelChar($row, $E->cx - 1);
        $E->cx--;
    } else {
        $E->cx = $E->row[$E->cy - 1]->size;
        editorRowAppendString($E->row[$E->cy - 1], $row->chars, $row->size);
        editorDelRow($E->cy);
        $E->cy--;
    }
}

function editorRowsToString(int &$buflen): string 
{
    global $E;

    $totlen = 0;
    for ($j = 0; $j < $E->numrows; $j++) {
        $totlen += $E->row[$j]->size + 1;   // 1 for "\n"
    }
    $buflen = $totlen;
    $buf = "";
    for ($j = 0; $j < $E->numrows; $j++) {
      $buf .= rtrim($E->row[$j]->chars, "\0");
      $buf .= "\n";
    }
    return $buf;
}

function editorOpen(string $filename): void 
{
    global $E;

    $fp = fopen($filename, 'r');
    if ($fp === false) die("fopen");
    $E->filename = $filename;

    while ($line = fgets($fp)) {
        $line = rtrim($line);
        //$line .= "\0";
        editorInsertRow($E->numrows, $line, strlen($line));
    };

    $line = null;
    fclose($fp);
    $E->dirty = 0;
}

function editorSave(): void 
{
    global $E;
    if (is_null($E->filename) || $E->filename === "") {
        $E->filename = editorPrompt("Save as: %s (ESC to cancel)");
        if (is_null($E->filename) || $E->filename === "") {
            editorSetStatusMessage("Save aborted");
            return;
        }
    }
    
    $len = 0;
    $buf = editorRowsToString($len);
    $fd = fopen($E->filename, 'w+');
    if ($fd !== false) {
        if (fwrite($fd, $buf, $len) === $len) {
            fclose($fd);
            $buf = null;
            $E->dirty = 0;
            editorSetStatusMessage("%d bytes written to disk", $len);
            return;
        }
    } 
    $buf = null;
    editorSetStatusMessage("Can't save! I/O error: len %d");
}

function editorPrompt(string $prompt): string 
{
    $bufsize = 128;
    $buflen = 0;
    $buf = "";
    while (1) {
        editorSetStatusMessage($prompt, $buf);
        editorRefreshScreen();
        $c = editorReadKey();
        if ($c === DEL_KEY || $c === CTRL_KEY('h') || $c === BACKSPACE) {
            $buf = substr($buf, 0, -1);
        } else if ($c === 0x1b) {
            editorSetStatusMessage("");
            return "";
        } else if ($c === ord("\r") || $c === ord("\n")) {
            if ($buflen != 0) {
                editorSetStatusMessage("");
                return $buf;
            }
        } else if ( $c > 0x1f && $c < 128) { // control key is 0..0x1f
            $buf = rtrim($buf);
            $buf .= chr($c);
            $buflen++;
        }
    }
}

function editorMoveCursor(int $key): void 
{
    global $E;
    $row = new erow;
    if ($E->cy >= $E->numrows) {
        $row = null;
    } else {
        $row = $E->row[$E->cy];
    }

    switch ($key) {
      case ARROW_LEFT:
        if ($E->cx !== 0) {
            $E->cx--;
        } else if ($E->cy > 0) {
            $E->cy--;
            $E->cx = $E->row[$E->cy]->size;
        }
        break;
      case ARROW_RIGHT:
        if (!is_null($row) && $E->cx < $row->size) {
            $E->cx++;
        } else if (!is_null($row) && $E->cx === $row->size) {
            $E->cy++;
            $E->cx = 0;
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

    if ($E->cy >= $E->numrows) {
        $row = null;
    } else {
        $row = $E->row[$E->cy];
    }
    if (!is_null($row)) {
        $rowlen = $row->size;
    } else {
        $rowlen = 0;
    }
    if ($E->cx > $rowlen) {
      $E->cx = $rowlen;
    }
}

function editorProcessKeypress(): void
{
    global $E;

    $c = editorReadKey();

    if ($c === 0) return;

    switch ($c) {
        case ord("\r"):
            editorInsertNewLine();
            break;
        case CTRL_KEY('q'):
            if ($E->dirty && $E->quit_times > 0) {
                editorSetStatusMessage("WARNING!!! File has unsaved changes. Press CTRL-Q %d more times to quit.", $E->quit_times);
                $E->quit_times--;
                return;
            }
            fwrite(STDOUT, "\e[2J", 4);
            fwrite(STDOUT, "\e[H", 3);
            exit(0);
            break;
        case CTRL_KEY('s'):
            editorSave();
            break;  
        case HOME_KEY:
            $E->cx = 0;
            break;
        case END_KEY:
            if ($E->cy < $E->numrows) {
                $E->cx = $E->row[$E->cy]->size;
            }
            break;
        case BACKSPACE:
        case CTRL_KEY('h'):
        case DEL_KEY:
            if ($c === DEL_KEY) editorMoveCursor(ARROW_RIGHT);
            editorDelChar();
            break;
        case PAGE_UP:
        case PAGE_DOWN:
            if ($c === PAGE_UP) {
                $E->cy = $E->rowoff;
            } else if ($c === PAGE_DOWN) {
                $E->cy = $E->rowoff + $E->screenrows - 1;
                if ($E->cy > $E->numrows) $E->cy = $E->numrows;
            }

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
            break;
        case CTRL_KEY('l'):
        case 0x1b:
            break;
        default:
            editorInsertChar($c);
            break;
    }

    $E->quit_times = KILO_QUIT_TIMES;
}

function editorScroll(): void 
{
    global $E;

    $E->rx = 0;
    if ($E->cy < $E->numrows) {
        $E->rx = editorRowCxToRx($E->row[$E->cy], $E->cx);
    }

    if ($E->cy < $E->rowoff) {
        $E->rowoff = $E->cy;
    }
    if ($E->cy >= $E->rowoff + $E->screenrows) {
        $E->rowoff = $E->cy - $E->screenrows + 1;
    }
    if ($E->cx < $E->coloff) {
        $E->coloff = $E->rx;
    }
    if ($E->rx >= $E->coloff + $E->screencols) {
        $E->coloff = $E->rx - $E->screencols + 1;
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
            $len = $E->row[$filerow]->rsize - $E->coloff;
            if ($len < 0) $len = 0;
            if ($len > $E->screencols) $len = $E->screencols;
            abAppend($ab, substr($E->row[$filerow]->render, $E->coloff, $len), $len);
        }

        abAppend($ab, "\e[K", 3);
        abAppend($ab, "\r\n", 2);
    }
}

function editorDrawStatusBar(abuf $ab): void
{
    global $E;

    abAppend($ab, "\e[7m", 4);

    $status = sprintf("%.20s - %d lines %s", 
        $E->filename ? $E->filename : "[No Name]", $E->numrows,
        $E->dirty ? "(modified)" : "");
    $len = strlen($status);
    $rstatus = sprintf("%d/%d", $E->cy + 1, $E->numrows);
    $rlen = strlen($rstatus);
    if ($len > $E->screencols) $len = $E->screencols;
    abAppend($ab, $status, $len);
    while ($len < $E->screencols) {
        if (($E->screencols - $len) === $rlen) {
            abAppend($ab, $rstatus, $rlen);
            break;
        } else {
            abAppend($ab, " ", 1);
            $len++;
        }
    }
    abAppend($ab, "\e[m", 3);
    abAppend($ab, "\r\n", 2);
}

function editorDrawMessageBar(abuf $ab) 
{
    global $E;

    abAppend($ab, "\e[K", 3);
    $msglen = strlen($E->statusmsg);
    if ($msglen > $E->screencols) $msglen = $E->screencols;
    if ($msglen > 0 && (time() - $E->statusmsg_time < 5)) abAppend($ab, $E->statusmsg, $msglen);
  }


function editorRefreshScreen(): void
{
    global $E;

    editorScroll();

    $ab = new abuf();

    abAppend($ab, "\e[?25l", 6);
    abAppend($ab, "\e[H", 3);

    editorDrawRows($ab);
    editorDrawStatusBar($ab);
    editorDrawMessageBar($ab);

    $buf = sprintf("\e[%d;%dH", ($E->cy - $E->rowoff) + 1, ($E->rx - $E->coloff) + 1);
    abAppend($ab, $buf, strlen($buf));

    abAppend($ab, "\e[?25h", 6);
    fwrite(STDOUT, $ab->b, $ab->len);
    abFree($ab);
}

function editorSetStatusMessage(string $fmt, ...$arg): void 
{
    global $E;
    $E->statusmsg = sprintf($fmt, ...$arg);
    $E->statusmsg_time = time();
  }


function initEditor(): void 
{
    global $E;
    if (getWindowSize($E->screenrows, $E->screencols) == -1) die("getWindowSize");
    $E->screenrows -= 2;     
}

function main(): void 
{
    global $argc, $argv;

    enableRawMode();
    initEditor();
    if ($argc >= 2) {
        editorOpen($argv[1]);
    }

    editorSetStatusMessage("HELP: Ctrl-S = save | Ctrl-Q = quit");

    while (1) {
        editorRefreshScreen();
        editorProcessKeypress();
    }

    exit(0);
}

main();