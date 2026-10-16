<?php

$failed = false;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../src')) as $file) {
    if (! $file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    foreach (file($file->getPathname()) as $number => $line) {
        if (mb_strlen(rtrim($line, "\r\n")) > 200) {
            fwrite(STDERR, $file->getPathname().':'.($number + 1).": exceeds 200 characters\n");
            $failed = true;
        }
    }
}
exit($failed ? 1 : 0);
