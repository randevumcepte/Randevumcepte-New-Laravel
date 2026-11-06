<?php

return [
    /*
     | Asistan Egitimi PDF → kalip cagrisi. Env adlari ve model eski monolitle (config/services.php anthropic) AYNI.
     */
    'anahtar' => env('ANTHROPIC_API_KEY', ''),
    'model'   => env('ANTHROPIC_MODEL', 'claude-haiku-4-5'),
    'url'     => 'https://api.anthropic.com/v1/messages',
];
