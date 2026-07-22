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
];
