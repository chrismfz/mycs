<?php

/*
 * Larastan learns the models' columns from the schema dump (the migrations are squashed: docs/HANDOFF.md, «Σχήμα της
 * βάσης»). Its SQL parser knows no MariaDB `uuid` type and then drops the whole dump, so it reads this copy instead,
 * the two `uuid` columns as char(36): the same columns, the same PHP type (a string). Written before each run.
 */
$source = __DIR__ . '/database/schema/mariadb-schema.sql';
$target = __DIR__ . '/storage/framework/larastan/mariadb-schema.sql';
$copy = preg_replace('/^(\s+`[^`]+`) uuid\b/m', '$1 char(36)', (string) file_get_contents($source));
if (!is_file($target) || file_get_contents($target) !== $copy) {
    $tmp = $target . '.' . getmypid();
    file_put_contents($tmp, $copy);
    rename($tmp, $target); // whole, also with the parallel workers reading it
}
