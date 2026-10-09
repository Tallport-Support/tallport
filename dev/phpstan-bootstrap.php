<?php

require_once __DIR__.'/../vendor/autoload.php';

$aliases = (require __DIR__.'/../config/app.php')['aliases'];
$aliases['Eventy'] = \TorMorten\Eventy\Facades\Events::class;

\Illuminate\Foundation\AliasLoader::getInstance($aliases)->register();
