<?php

function check(string $name, $got, $want): void {
    global $fail;
    if ($got === $want) {
        echo "ok   $name\n";
    } else {
        $fail++;
        echo "FAIL $name: ";
        var_dump($got);
    }
}

function finish(): void {
    global $fail;
    echo $fail ? "$fail FAILED\n" : "all ok\n";
    exit($fail ? 1 : 0);
}
