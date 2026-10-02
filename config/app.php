<?php
require_once __DIR__ . '/bootstrap.php';
define('SECUREPOS_BASE_URL', secureposBaseUrl());
// Production attendance geofence.
define('SECUREPOS_GEOFENCE_LATITUDE', 2.799362);
define('SECUREPOS_GEOFENCE_LONGITUDE', 103.483150);
define('SECUREPOS_GEOFENCE_RADIUS_METRES', 100.0);
define('SECUREPOS_GEOLOCATION_MAX_ACCURACY_METRES', 100.0);

// Development/demo geofence override. Keep disabled until temporary test
// coordinates have been explicitly supplied and confirmed.
define('SECUREPOS_DEV_GEOFENCE_ENABLED', false);
define('SECUREPOS_DEV_GEOFENCE_LATITUDE', 3.1007260595647415);
define('SECUREPOS_DEV_GEOFENCE_LONGITUDE', 101.71467267994616);
define('SECUREPOS_DEV_GEOFENCE_RADIUS_METRES', 100.0);

define('SECUREPOS_ATTENDANCE_OPEN_TIME', '08:00:00');
define('SECUREPOS_ATTENDANCE_CLOSE_TIME', '21:00:00');
// Temporary development-only kiosk testing override. Set to false after testing.
define('SECUREPOS_DEV_ATTENDANCE_WINDOW_ENABLED', false);
define('SECUREPOS_DEV_ATTENDANCE_CLOSE_TIME', '23:59:59');
define('SECUREPOS_ATTENDANCE_QR_LIFETIME_SECONDS', 60);
define('SECUREPOS_KIOSK_PAIRING_LIFETIME_SECONDS', 600);
define('SECUREPOS_KIOSK_SESSION_LIFETIME_SECONDS', 2592000);

/**
 * Development override (optional): enable SECUREPOS_DEV_GEOFENCE_ENABLED and
 * supply the temporary constants above. The existing environment-based mode
 * remains supported for compatibility. Production values remain the safe
 * default and attendance-processing code does not need to be edited.
 */
function secureposGeofenceConfig()
{
    $config = [
        'latitude' => SECUREPOS_GEOFENCE_LATITUDE,
        'longitude' => SECUREPOS_GEOFENCE_LONGITUDE,
        'radius_metres' => SECUREPOS_GEOFENCE_RADIUS_METRES,
        'max_accuracy_metres' => SECUREPOS_GEOLOCATION_MAX_ACCURACY_METRES,
    ];

    $constantMode = defined('SECUREPOS_DEV_GEOFENCE_ENABLED') && SECUREPOS_DEV_GEOFENCE_ENABLED;
    $environmentMode = getenv('SECUREPOS_GEOFENCE_MODE') === 'development';
    if (!$constantMode && !$environmentMode) {
        return $config;
    }

    $latitudeValue = $constantMode ? SECUREPOS_DEV_GEOFENCE_LATITUDE : getenv('SECUREPOS_DEV_GEOFENCE_LATITUDE');
    $longitudeValue = $constantMode ? SECUREPOS_DEV_GEOFENCE_LONGITUDE : getenv('SECUREPOS_DEV_GEOFENCE_LONGITUDE');
    $radiusValue = $constantMode ? SECUREPOS_DEV_GEOFENCE_RADIUS_METRES : getenv('SECUREPOS_DEV_GEOFENCE_RADIUS_METRES');
    $latitude = filter_var($latitudeValue, FILTER_VALIDATE_FLOAT);
    $longitude = filter_var($longitudeValue, FILTER_VALIDATE_FLOAT);
    $radius = filter_var($radiusValue, FILTER_VALIDATE_FLOAT);
    if ($latitude === false || $longitude === false || $radius === false
        || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 || $radius <= 0) {
        return $config;
    }

    $config['latitude'] = (float)$latitude;
    $config['longitude'] = (float)$longitude;
    $config['radius_metres'] = (float)$radius;
    return $config;
}
