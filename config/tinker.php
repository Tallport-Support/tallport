<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Project Trust Mode
    |--------------------------------------------------------------------------
    |
    | Tinker's default ("always") lets PsySH load a .psysh.php file from the
    | project directory, which would run code from any file placed there.
    | Other Tinker options keep their defaults.
    |
    */

    'trust_project' => env('TINKER_TRUST_PROJECT', 'never'),

];
