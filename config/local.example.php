<?php
// Copy to local.php (ignored by Git) if your XAMPP settings differ.
return [
    'db_host' => 'localhost',
    'db_port' => 3306,
    'db_name' => 'securepos',
    'db_user' => 'root',
    'db_password' => '',
    'base_url' => '', // Detect localhost/LAN domain and installation folder.
    // Optional: retain an existing private attachment directory.
    // 'leave_storage' => '/absolute/path/to/private/leave_documents',
    // Existing Apache SECUREPOS_FACE_TEMPLATE_KEY remains supported.
    'trusted_proxy_ips' => [],
];
