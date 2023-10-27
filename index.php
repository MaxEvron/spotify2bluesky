<pre><?php
$state = @file_get_contents(__DIR__ . '/rw/state.dat');
if (FALSE === $state) {
    die ('Not ready.');
} else {
    if (!array_key_exists('state', $_GET) || ($_GET['state'] != $state)) {
        die ('State verification failed.');
    } elseif (array_key_exists('error', $_GET)) {
        die ("Error: {$_GET['error']}.");
    } elseif (!array_key_exists('code', $_GET)) {
        die ('Missing authorization code.');
    } else {
        file_put_contents(__DIR__ . '/rw/code.dat', $_GET['code']);
        die ('Authorization code saved');
    }
}
?></pre>