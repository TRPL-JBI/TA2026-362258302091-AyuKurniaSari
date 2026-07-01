<?php

$url = 'https://api.telegram.org';

$response = file_get_contents($url);

if ($response === false) {
    echo 'GAGAL';
} else {
    echo 'BERHASIL';
}