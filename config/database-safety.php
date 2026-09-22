<?php

return [
    'allow_destructive_commands' => (bool) env('ALLOW_DESTRUCTIVE_DB_COMMANDS', false),
    'destructive_confirmation' => env('DESTRUCTIVE_DB_CONFIRMATION'),
];
