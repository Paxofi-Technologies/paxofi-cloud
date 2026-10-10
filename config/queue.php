<?php

declare(strict_types=1);

return [
    'name' => 'paxoficloud:default',
    'reliable_name' => 'paxoficloud:reliable',
    'visibility_timeout' => 60,
    // Only these job classes may be (de)serialised. Empty until Sprint 4.
    'allowed_classes' => [],
];
