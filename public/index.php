<?php

declare(strict_types=1);

/**
 * Front controller — every request enters here.
 */

use App\Core\App;
use App\Core\Request;

require dirname(__DIR__) . '/src/bootstrap.php';

$app = new App();
$app->handle(Request::capture())->send();
