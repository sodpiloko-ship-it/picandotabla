<?php
// Comanda de Picando Tabla — configuración.
// Solo correos de la allowlist pueden pedir el magic-link (David + Jessica).
return [
    'brand'  => 'Picando Tabla',
    'emoji'  => '🧀',
    'accent' => '#7c2d3e',
    'base'   => 'https://picandotabla.com/comanda',
    'from'   => 'Picando Tabla <contacto@picandotabla.com>',
    'admins' => [
        'sodpiloko@gmail.com',
        'jesicka623@gmail.com',
        'contacto@picandotabla.com',
    ],
    // Respaldo del magic-link por Telegram: correo -> chat_id ('default' = el chat_id de secrets/telegram.json).
    'telegram' => [
        'sodpiloko@gmail.com'  => 'default',
        'jesicka623@gmail.com' => '5483734975',
    ],
];
