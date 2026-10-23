<?php
// Copy this file to config.local.php and fill in your server's values.
// config.local.php is listed in .gitignore, so real passwords never reach GitHub.
// Any value left out falls back to an environment variable of the same name, then to the XAMPP default.

return [
    // 'production' shows visitors a friendly error page and logs the details.
    // 'development' shows full error details. Use it only on your own computer.
    'APP_ENV' => 'production',

    // The database your host created for you (cPanel > MySQL Databases, or similar).
    'DB_HOST' => 'localhost',
    'DB_PORT' => '3306',
    'DB_NAME' => 'zerowaste',
    'DB_USER' => 'zerowaste_user',
    'DB_PASSWORD' => 'change-me',

    // Optional: an Anthropic API key (console.anthropic.com > API keys) lets Claude answer any question
    // in the AI Waste Advisor's chat. Leave it empty to use the built-in answers. Usage is billed by Anthropic.
    'ANTHROPIC_API_KEY' => '',
];
