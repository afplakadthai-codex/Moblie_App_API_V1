<?php
declare(strict_types=1);

$slug = trim((string)($_GET['slug'] ?? ''));
$id   = (int)($_GET['id'] ?? 0);

if ($slug !== '') {
    $target = '/listing.php?slug=' . rawurlencode($slug);
    header('Location: ' . $target, true, 301);
    exit;
}

if ($id > 0) {
    $target = '/listing.php?id=' . $id;
    header('Location: ' . $target, true, 301);
    exit;
}

header('Location: /listings.php', true, 302);
exit;
