<?php
require_once __DIR__ . '/bootstrap.php';

const SECUREPOS_FACE_CIPHER = 'aes-256-gcm';
const SECUREPOS_FACE_MODEL_VERSION = 'face-api.js-faceRecognitionNet-v1';
const SECUREPOS_FACE_AAD = 'securepos-face-template-v1';
// face-api.js uses Euclidean distance for 128-value face descriptors. Its
// conventional 0.60 threshold is suitable for this controlled FYP prototype.
const SECUREPOS_FACE_DISTANCE_THRESHOLD = 0.60;

/**
 * Configure SECUREPOS_FACE_TEMPLATE_KEY as base64 for exactly 32 random bytes
 * in the Apache/PHP environment. The key must not be committed to this project.
 */
function secureposFaceEncryptionKey(): ?string
{
    $configured = secureposSetting('face_template_key');
    if (!is_string($configured) || trim($configured) === '') {
        return null;
    }
    $decoded = base64_decode(trim($configured), true);
    return is_string($decoded) && strlen($decoded) === 32 ? $decoded : null;
}

function encryptFaceDescriptor(array $descriptor, string $key): array
{
    $plaintext = json_encode($descriptor, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $plaintext,
        SECUREPOS_FACE_CIPHER,
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        SECUREPOS_FACE_AAD,
        16
    );
    if (!is_string($ciphertext) || strlen($tag) !== 16) {
        throw new RuntimeException('Unable to encrypt face template.');
    }
    return ['ciphertext' => $ciphertext, 'iv' => $iv, 'tag' => $tag];
}

function decryptFaceDescriptor(string $ciphertext, string $iv, string $tag, string $key): array
{
    $plaintext = openssl_decrypt(
        $ciphertext,
        SECUREPOS_FACE_CIPHER,
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        SECUREPOS_FACE_AAD
    );
    if (!is_string($plaintext)) {
        throw new RuntimeException('Unable to decrypt face template.');
    }
    $descriptor = json_decode($plaintext, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($descriptor)) {
        throw new RuntimeException('Invalid face template.');
    }
    return $descriptor;
}

function validateFaceDescriptor($descriptor): ?array
{
    if (!is_array($descriptor) || count($descriptor) !== 128) {
        return null;
    }
    $validated = [];
    foreach ($descriptor as $value) {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }
        $number = (float)$value;
        if (!is_finite($number) || abs($number) > 10) {
            return null;
        }
        $validated[] = $number;
    }
    return $validated;
}

function faceDescriptorDistance(array $first, array $second): float
{
    $validatedFirst = validateFaceDescriptor($first);
    $validatedSecond = validateFaceDescriptor($second);
    if ($validatedFirst === null || $validatedSecond === null) {
        throw new InvalidArgumentException('Invalid face descriptor.');
    }
    $sum = 0.0;
    for ($index = 0; $index < 128; $index++) {
        $difference = $validatedFirst[$index] - $validatedSecond[$index];
        $sum += $difference * $difference;
    }
    return sqrt($sum);
}
