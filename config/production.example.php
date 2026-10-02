<?php
// Copy to production.php ON THE HOST ONLY. Never commit the populated file.
return [
    'db_host' => 'sql301.infinityfree.com',
    'db_port' => 3306,
    'db_name' => 'if0_43067047_securepos',
    'db_user' => 'if0_43067047',
    // Enter the password privately in the host's config/production.php copy.
    'db_password' => 'REPLACE_WITH_MYSQL_PASSWORD',
    // Upload the application directly into this domain's htdocs (no /SecurePOS).
    'base_url' => 'https://securepos.xo.je',
    'face_template_key' => 'REPLACE_WITH_EXISTING_BASE64_32_BYTE_KEY',
    'leave_storage' => dirname(__DIR__) . '/storage/private/leave_documents',
    // Leave empty for direct SSL. Add only verified reverse-proxy addresses.
    'trusted_proxy_ips' => [],
];
