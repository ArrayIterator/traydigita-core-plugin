<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\TwoFactor;

use function array_key_exists;
use function base64_decode;
use function base64_encode;
use function bin2hex;
use function chunk_split;
use function count;
use function explode;
use function hash;
use function hash_equals;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function ltrim;
use function openssl_pkey_get_public;
use function openssl_verify;
use function ord;
use function preg_match;
use function random_bytes;
use function str_repeat;
use function str_replace;
use function strlen;
use function strtr;
use function substr;
use function trim;
use function unpack;
use const OPENSSL_ALGO_SHA256;

/**
 * Minimal WebAuthn (passkey) server.
 *
 * Pure PHP with no WordPress dependency, so the ceremony logic can
 * be unit-tested standalone with synthetic authenticator responses
 * (the harness builds registration and assertion payloads with
 * OpenSSL keys and checks every rejection path). The theme ships
 * zero runtime Composer dependencies, so this is a deliberate,
 * scope-limited implementation instead of the 31-package
 * web-auth/webauthn-lib tree — see the class docblock of
 * TwoFactorModule for the trade-off.
 *
 * Scope (documented, not accidental):
 * - Algorithms: ES256 (P-256) and RS256 (RSA with SHA-256) — the
 *   algorithms real passkeys use: platform authenticators
 *   (Touch ID, Face ID, Windows Hello, Android) create ES256
 *   credentials, and security keys offer ES256 or RS256.
 *   EdDSA (Ed25519) is deliberately NOT accepted: PHP's
 *   openssl_verify() cannot verify Ed25519 signatures — OpenSSL
 *   requires a NULL digest for Ed25519 and PHP always supplies
 *   one, so verification returns an error (verified 2026-11-21:
 *   the CLI verifies the same signature fine, PHP returns -1).
 *   Accepting EdDSA keys at registration would enroll passkeys
 *   that can never log in, so the server rejects them with a
 *   clear error instead. P-384/P-521 are rejected the same way.
 * - Attestation formats: `none` and `packed` with self-attestation
 *   only. `packed` with an x5c certificate chain is rejected —
 *   validating attestation chains is out of scope for a theme, and
 *   the creation options ask for attestation `none` anyway, so a
 *   conforming authenticator returns `none`.
 * - User verification is recorded (the UV flag) but not required:
 *   the creation options ask for `preferred`, so a security key
 *   without biometrics still works; the profile page shows whether
 *   each passkey was created with user verification.
 * - Counter-based clone detection follows the spec: a counter that
 *   does not advance past the stored value (while both are
 *   non-zero) is treated as a cloned authenticator and the login
 *   is rejected. Authenticators that report a zero counter are
 *   exempt from the check, per the spec's guidance.
 *
 * Everything the browser sends is treated as hostile: client data
 * is JSON-decoded and checked field by field, the challenge is
 * compared with hash_equals(), the origin must match exactly, the
 * relying-party id hash must match, and the signature is verified
 * against the stored public key over authenticatorData || SHA-256
 * (clientDataJSON). No step is skipped for convenience.
 */
final class WebAuthn
{
    /**
     * COSE algorithm identifiers we accept.
     */
    public const int ALG_ES256 = -7;

    public const int ALG_RS256 = -257;

    /**
     * COSE key types.
     */
    private const int KTY_EC2 = 2;

    private const int KTY_RSA = 3;

    /**
     * COSE elliptic curve for P-256.
     */
    private const int CRV_P256 = 1;

    /**
     * Authenticator-data flag bits.
     */
    private const int FLAG_USER_PRESENT = 0x01;

    private const int FLAG_USER_VERIFIED = 0x04;

    private const int FLAG_BACKUP_ELIGIBLE = 0x08;

    private const int FLAG_BACKUP_STATE = 0x10;

    private const int FLAG_ATTESTED_DATA = 0x40;

    /**
     * Challenge length in bytes.
     */
    public const int CHALLENGE_BYTES = 32;

    /**
     * Relying-party id (normally the site host).
     */
    private readonly string $rpId;

    /**
     * Expected origin (scheme + host + port, e.g. https://example.com).
     */
    private readonly string $origin;

    /**
     * @param string $rpId Relying-party id (the site host).
     * @param string $origin Expected origin (scheme://host[:port]).
     */
    public function __construct(string $rpId, string $origin)
    {
        $this->rpId = $rpId;
        $this->origin = $origin;
    }

    /**
     * Encode bytes as unpadded base64url (RFC 4648 §5).
     *
     * @param string $data Binary data.
     * @return string Base64url text without padding.
     */
    public static function base64urlEncode(string $data): string
    {
        return str_replace('=', '', strtr(base64_encode($data), '+/', '-_'));
    }

    /**
     * Decode unpadded base64url strictly.
     *
     * Rejects characters outside the base64url alphabet, bad
     * padding, and inputs whose length mod 4 is 1. Returns null
     * for anything malformed instead of guessing.
     *
     * @param string $input Base64url text.
     * @return string|null Binary data, or null when malformed.
     */
    public static function base64urlDecode(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return '';
        }
        if (strlen($input) % 4 === 1) {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9\-_]*$/', $input) !== 1) {
            return null;
        }
        $padded = $input . str_repeat('=', (4 - strlen($input) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }
        // Round-trip check: a canonical encoding must re-encode to
        // the same text, so non-canonical trailing bits are caught.
        if (self::base64urlEncode($decoded) !== $input) {
            return null;
        }
        return $decoded;
    }

    /**
     * Generate a random challenge.
     *
     * @param int $bytes Challenge length in bytes.
     * @return string Random bytes.
     */
    public static function randomChallenge(int $bytes = self::CHALLENGE_BYTES): string
    {
        return random_bytes(max(1, $bytes));
    }

    /**
     * Build PublicKeyCredentialCreationOptions for registration.
     *
     * The returned `options` array is JSON-serializable as-is: the
     * challenge, user id, and excluded credential ids are base64url
     * strings, and the frontend converts them back to ArrayBuffers
     * before calling navigator.credentials.create(). The raw
     * challenge bytes are returned alongside for storage in a
     * short-lived transient — never send the raw bytes to the
     * browser, and never reuse a challenge.
     *
     * @param string $userId Stable user id bytes (the WordPress user id).
     * @param string $userName Account name shown by the authenticator.
     * @param string $displayName Display name shown by the authenticator.
     * @param string $rpName Relying-party display name (site name).
     * @param array<int, array{id: string, transports?: array<int, string>}> $excludeCredentials Already-registered credentials, keyed by base64url id.
     * @param string|null $challenge Raw challenge bytes (generated when null).
     * @return array{options: array<string, mixed>, challenge: string} Options plus raw challenge bytes.
     */
    public function registrationOptions(
        string $userId,
        string $userName,
        string $displayName,
        string $rpName,
        array $excludeCredentials = [],
        ?string $challenge = null
    ): array {
        $challenge = $challenge ?? self::randomChallenge();
        $exclude = [];
        foreach ($excludeCredentials as $credential) {
            $entry = ['type' => 'public-key', 'id' => $credential['id']];
            if (!empty($credential['transports'])) {
                $entry['transports'] = $credential['transports'];
            }
            $exclude[] = $entry;
        }
        $options = [
            'rp' => ['id' => $this->rpId, 'name' => $rpName],
            'user' => [
                'id' => self::base64urlEncode($userId),
                'name' => $userName,
                'displayName' => $displayName,
            ],
            'challenge' => self::base64urlEncode($challenge),
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => self::ALG_ES256],
                ['type' => 'public-key', 'alg' => self::ALG_RS256],
            ],
            'timeout' => 60000,
            'excludeCredentials' => $exclude,
            'authenticatorSelection' => [
                'residentKey' => 'required',
                'requireResidentKey' => true,
                'userVerification' => 'preferred',
            ],
            'attestation' => 'none',
        ];
        return ['options' => $options, 'challenge' => $challenge];
    }

    /**
     * Build PublicKeyCredentialRequestOptions for authentication.
     *
     * With an empty allow list the browser may use any discoverable
     * credential (usernameless login); with a list it only offers
     * the given credentials (second factor after a password login).
     *
     * @param array<int, array{id: string, transports?: array<int, string>}> $allowCredentials Credentials to offer, keyed by base64url id.
     * @param string|null $challenge Raw challenge bytes (generated when null).
     * @return array{options: array<string, mixed>, challenge: string} Options plus raw challenge bytes.
     */
    public function assertionOptions(array $allowCredentials = [], ?string $challenge = null): array
    {
        $challenge = $challenge ?? self::randomChallenge();
        $allow = [];
        foreach ($allowCredentials as $credential) {
            $entry = ['type' => 'public-key', 'id' => $credential['id']];
            if (!empty($credential['transports'])) {
                $entry['transports'] = $credential['transports'];
            }
            $allow[] = $entry;
        }
        $options = [
            'challenge' => self::base64urlEncode($challenge),
            'timeout' => 60000,
            'rpId' => $this->rpId,
            'allowCredentials' => $allow,
            'userVerification' => 'preferred',
        ];
        return ['options' => $options, 'challenge' => $challenge];
    }

    /**
     * Verify a registration (create) response.
     *
     * The $response array is the JSON the browser posted, with
     * base64url strings (id, rawId, response.clientDataJSON,
     * response.attestationObject, response.transports).
     *
     * @param array<string, mixed> $response Browser response.
     * @param string $expectedChallenge Raw challenge bytes issued with the options.
     * @return array{id: string, public_key: string, counter: int, transports: array<int, string>, aaguid: string, backup_eligible: bool, backup_state: bool, user_verified: bool} Credential record for storage.
     * @throws WebAuthnException When any check fails.
     */
    public function verifyRegistration(array $response, string $expectedChallenge): array
    {
        $type = $response['type'] ?? null;
        if ($type !== 'public-key') {
            throw new WebAuthnException('bad_type', 'Registration response type is not public-key.');
        }
        $responseData = $response['response'] ?? null;
        if (!is_array($responseData)) {
            throw new WebAuthnException('bad_response', 'Registration response has no response object.');
        }
        $clientData = $this->parseClientData($responseData['clientDataJSON'] ?? null, 'webauthn.create', $expectedChallenge);
        $attestationBytes = $this->requireBase64url($responseData['attestationObject'] ?? null, 'bad_attestation');
        $attestation = Cbor::decode($attestationBytes);
        if (!is_array($attestation)) {
            throw new WebAuthnException('bad_attestation', 'Attestation object is not a map.');
        }
        $format = $attestation['fmt'] ?? null;
        if (!is_string($format) || !in_array($format, ['none', 'packed'], true)) {
            throw new WebAuthnException('unsupported_attestation', 'Attestation format is not supported.');
        }
        $authData = $attestation['authData'] ?? null;
        if (!is_string($authData)) {
            throw new WebAuthnException('bad_auth_data', 'Attestation object has no authenticator data.');
        }
        $attStmt = $attestation['attStmt'] ?? null;
        if (!is_array($attStmt)) {
            throw new WebAuthnException('bad_attestation', 'Attestation object has no attestation statement.');
        }
        $parsed = $this->parseAuthData($authData, true);
        $credentialId = $parsed['credential_id'];
        $coseKey = $parsed['cose_key'];

        // The credential id the browser reports must be the one in
        // the authenticator data — a mismatch means the response was
        // stitched together from two ceremonies.
        $reportedId = $this->requireBase64url($response['id'] ?? null, 'bad_credential_id');
        $reportedRawId = $this->requireBase64url($response['rawId'] ?? null, 'bad_credential_id');
        if (!hash_equals($credentialId, $reportedId) || !hash_equals($credentialId, $reportedRawId)) {
            throw new WebAuthnException('credential_id_mismatch', 'Reported credential id does not match the authenticator data.');
        }

        if ($format === 'none') {
            if ($attStmt !== []) {
                throw new WebAuthnException('bad_attestation', 'None attestation must carry an empty statement.');
            }
        } else {
            $this->verifyPackedAttestation($attStmt, $authData, $clientData['hash'], $coseKey);
        }

        $transports = [];
        $rawTransports = $responseData['transports'] ?? $response['transports'] ?? null;
        if (is_array($rawTransports)) {
            $allowed = ['usb', 'nfc', 'ble', 'internal', 'hybrid'];
            foreach ($rawTransports as $transport) {
                if (is_string($transport) && in_array($transport, $allowed, true)) {
                    $transports[] = $transport;
                }
            }
        }
        return [
            'id' => self::base64urlEncode($credentialId),
            'public_key' => self::base64urlEncode($parsed['cose_key_bytes']),
            'counter' => $parsed['counter'],
            'transports' => $transports,
            'aaguid' => $parsed['aaguid'],
            'backup_eligible' => $parsed['backup_eligible'],
            'backup_state' => $parsed['backup_state'],
            'user_verified' => $parsed['user_verified'],
        ];
    }

    /**
     * Verify an authentication (get) response.
     *
     * The $findCredential callback resolves a credential id (and the
     * user handle the browser returned) to the stored record, or
     * null when unknown. It receives the base64url credential id and
     * the base64url user handle, and must return
     * `['user_id' => int, 'record' => [...]]` where the record has
     * the keys `id` (base64url), `public_key` (base64url COSE CBOR),
     * and `counter` (int) — or null. Ownership is the callback's
     * job: for a second-factor flow it must only return credentials
     * of the pending user; for a discoverable flow it resolves the
     * user from the handle.
     *
     * @param array<string, mixed> $response Browser response.
     * @param string $expectedChallenge Raw challenge bytes issued with the options.
     * @param callable(string, string): (array{user_id: int, record: array<string, mixed>}|null) $findCredential Credential resolver.
     * @return array{user_id: int, credential_id: string, counter: int, user_verified: bool, backup_state: bool} Verification result; the caller persists `counter`.
     * @throws WebAuthnException When any check fails.
     */
    public function verifyAssertion(array $response, string $expectedChallenge, callable $findCredential): array
    {
        $type = $response['type'] ?? null;
        if ($type !== 'public-key') {
            throw new WebAuthnException('bad_type', 'Assertion response type is not public-key.');
        }
        $responseData = $response['response'] ?? null;
        if (!is_array($responseData)) {
            throw new WebAuthnException('bad_response', 'Assertion response has no response object.');
        }
        $clientData = $this->parseClientData($responseData['clientDataJSON'] ?? null, 'webauthn.get', $expectedChallenge);
        $authenticatorData = $this->requireBase64url($responseData['authenticatorData'] ?? null, 'bad_auth_data');
        $signature = $this->requireBase64url($responseData['signature'] ?? null, 'bad_signature');
        $credentialId = $this->requireBase64url($response['id'] ?? null, 'bad_credential_id');
        $reportedRawId = $this->requireBase64url($response['rawId'] ?? null, 'bad_credential_id');
        if (!hash_equals($credentialId, $reportedRawId)) {
            throw new WebAuthnException('credential_id_mismatch', 'Reported credential id does not match the raw id.');
        }
        $userHandle = '';
        $rawHandle = $responseData['userHandle'] ?? null;
        if (is_string($rawHandle) && $rawHandle !== '') {
            $decodedHandle = self::base64urlDecode($rawHandle);
            if ($decodedHandle === null) {
                throw new WebAuthnException('bad_user_handle', 'User handle is not valid base64url.');
            }
            $userHandle = $decodedHandle;
        }

        $parsed = $this->parseAuthData($authenticatorData, false);
        $found = $findCredential(self::base64urlEncode($credentialId), $userHandle);
        if ($found === null || !isset($found['user_id'], $found['record']) || !is_array($found['record'])) {
            throw new WebAuthnException('unknown_credential', 'Credential is not registered for this user.');
        }
        $record = $found['record'];
        $storedId = self::base64urlDecode((string) ($record['id'] ?? ''));
        if ($storedId === null || !hash_equals($storedId, $credentialId)) {
            throw new WebAuthnException('unknown_credential', 'Stored credential id does not match the assertion.');
        }
        $coseKeyBytes = self::base64urlDecode((string) ($record['public_key'] ?? ''));
        if ($coseKeyBytes === null) {
            throw new WebAuthnException('bad_public_key', 'Stored public key is not valid base64url.');
        }
        $coseKey = $this->parseCoseKey($coseKeyBytes);

        $signedData = $authenticatorData . $clientData['hash'];
        if (!$this->verifySignature($coseKey, $signedData, $signature)) {
            throw new WebAuthnException('bad_signature', 'Assertion signature does not verify.');
        }

        $storedCounter = (int) ($record['counter'] ?? 0);
        $receivedCounter = $parsed['counter'];
        if ($storedCounter > 0 && $receivedCounter > 0 && $receivedCounter <= $storedCounter) {
            throw new WebAuthnException(
                'counter_regression',
                'Authenticator counter did not advance; the credential may be cloned.'
            );
        }
        $newCounter = $receivedCounter > $storedCounter ? $receivedCounter : $storedCounter;
        return [
            'user_id' => (int) $found['user_id'],
            'credential_id' => self::base64urlEncode($credentialId),
            'counter' => $newCounter,
            'user_verified' => $parsed['user_verified'],
            'backup_state' => $parsed['backup_state'],
        ];
    }

    /**
     * Parse and verify clientDataJSON.
     *
     * Checks the JSON parses to an object, the type matches the
     * ceremony, the challenge decodes and equals the issued one
     * (constant-time), and the origin matches exactly. Returns the
     * SHA-256 hash of the raw JSON bytes, which the signature and
     * attestation checks need.
     *
     * @param mixed $encoded Base64url clientDataJSON from the browser.
     * @param string $expectedType `webauthn.create` or `webauthn.get`.
     * @param string $expectedChallenge Raw challenge bytes issued with the options.
     * @return array{hash: string} SHA-256 of the raw client data JSON.
     * @throws WebAuthnException When any check fails.
     */
    private function parseClientData(mixed $encoded, string $expectedType, string $expectedChallenge): array
    {
        $json = $this->requireBase64url($encoded, 'bad_client_data');
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new WebAuthnException('bad_client_data', 'Client data is not valid JSON.');
        }
        if (($data['type'] ?? null) !== $expectedType) {
            throw new WebAuthnException('bad_client_data', 'Client data type does not match the ceremony.');
        }
        $challenge = self::base64urlDecode((string) ($data['challenge'] ?? ''));
        if ($challenge === null || !hash_equals($challenge, $expectedChallenge)) {
            throw new WebAuthnException('challenge_mismatch', 'Client data challenge does not match the issued challenge.');
        }
        if (($data['origin'] ?? null) !== $this->origin) {
            throw new WebAuthnException('bad_origin', 'Client data origin does not match this site.');
        }
        return ['hash' => hash('sha256', $json, true)];
    }

    /**
     * Parse authenticator data.
     *
     * Layout: rpIdHash (32) || flags (1) || counter (4, big-endian)
     * || [attested credential data when the AT flag is set]. The
     * rpIdHash must equal SHA-256 of the relying-party id, and the
     * user-present flag must be set. For registration the attested
     * data is parsed into AAGUID, credential id, and COSE key.
     *
     * @param string $authData Authenticator data bytes.
     * @param bool $expectAttested True for registration (AT flag required).
     * @return array{counter: int, user_verified: bool, backup_eligible: bool, backup_state: bool, credential_id: string, cose_key: array<string, mixed>, cose_key_bytes: string, aaguid: string}
     * @throws WebAuthnException When any check fails.
     */
    private function parseAuthData(string $authData, bool $expectAttested): array
    {
        if (strlen($authData) < 37) {
            throw new WebAuthnException('bad_auth_data', 'Authenticator data is too short.');
        }
        $rpIdHash = substr($authData, 0, 32);
        if (!hash_equals($rpIdHash, hash('sha256', $this->rpId, true))) {
            throw new WebAuthnException('bad_rp_id', 'Authenticator data is for a different relying party.');
        }
        $flags = ord($authData[32]);
        if (($flags & self::FLAG_USER_PRESENT) === 0) {
            throw new WebAuthnException('user_not_present', 'Authenticator reports the user was not present.');
        }
        $counterBytes = unpack('N', substr($authData, 33, 4));
        if ($counterBytes === false) {
            throw new WebAuthnException('bad_auth_data', 'Cannot read the authenticator counter.');
        }
        $result = [
            'counter' => (int) $counterBytes[1],
            'user_verified' => ($flags & self::FLAG_USER_VERIFIED) !== 0,
            'backup_eligible' => ($flags & self::FLAG_BACKUP_ELIGIBLE) !== 0,
            'backup_state' => ($flags & self::FLAG_BACKUP_STATE) !== 0,
            'credential_id' => '',
            'cose_key' => [],
            'cose_key_bytes' => '',
            'aaguid' => '',
        ];
        if (!$expectAttested) {
            return $result;
        }
        if (($flags & self::FLAG_ATTESTED_DATA) === 0) {
            throw new WebAuthnException('bad_auth_data', 'Registration data has no attested credential data.');
        }
        $cursor = 37;
        if (strlen($authData) < $cursor + 18) {
            throw new WebAuthnException('bad_auth_data', 'Attested credential data is truncated.');
        }
        $result['aaguid'] = bin2hex(substr($authData, $cursor, 16));
        $cursor += 16;
        $lengthBytes = unpack('n', substr($authData, $cursor, 2));
        if ($lengthBytes === false) {
            throw new WebAuthnException('bad_auth_data', 'Cannot read the credential id length.');
        }
        $credentialIdLength = (int) $lengthBytes[1];
        $cursor += 2;
        if ($credentialIdLength === 0 || $credentialIdLength > 1023) {
            throw new WebAuthnException('bad_auth_data', 'Credential id length is out of range.');
        }
        if (strlen($authData) < $cursor + $credentialIdLength) {
            throw new WebAuthnException('bad_auth_data', 'Credential id is truncated.');
        }
        $result['credential_id'] = substr($authData, $cursor, $credentialIdLength);
        $cursor += $credentialIdLength;
        $key = Cbor::decodeAt(substr($authData, $cursor), 0);
        if (!is_array($key['value'])) {
            throw new WebAuthnException('bad_public_key', 'COSE public key is not a map.');
        }
        $result['cose_key'] = $this->parseCoseKey(substr($authData, $cursor, $key['length']));
        $result['cose_key_bytes'] = substr($authData, $cursor, $key['length']);
        return $result;
    }

    /**
     * Parse a COSE public key from its CBOR bytes.
     *
     * Accepts EC2 P-256 (kty 2, crv 1) and RSA (kty 3). Anything
     * else — including OKP Ed25519 (PHP's OpenSSL cannot verify
     * Ed25519 signatures, see the class docblock) and P-384/P-521
     * — is rejected with a clear error instead of being silently
     * misread.
     *
     * @param string $coseBytes COSE key CBOR.
     * @return array{kty: int, alg: int, crv?: int, x?: string, y?: string, n?: string, e?: string}
     * @throws WebAuthnException When the key is malformed or unsupported.
     */
    private function parseCoseKey(string $coseBytes): array
    {
        $key = Cbor::decode($coseBytes);
        if (!is_array($key)) {
            throw new WebAuthnException('bad_public_key', 'COSE public key is not a map.');
        }
        $kty = $key[1] ?? null;
        $alg = $key[3] ?? null;
        if (!is_int($kty) || !is_int($alg)) {
            throw new WebAuthnException('bad_public_key', 'COSE public key has no key type or algorithm.');
        }
        if ($kty === self::KTY_EC2) {
            if ($alg !== self::ALG_ES256) {
                throw new WebAuthnException('unsupported_algorithm', 'EC2 public key algorithm is not supported.');
            }
            $crv = $key[-1] ?? null;
            $x = $key[-2] ?? null;
            $y = $key[-3] ?? null;
            if ($crv !== self::CRV_P256 || !is_string($x) || strlen($x) !== 32 || !is_string($y) || strlen($y) !== 32) {
                throw new WebAuthnException('unsupported_algorithm', 'EC2 public key is not a P-256 key.');
            }
            return ['kty' => $kty, 'alg' => $alg, 'crv' => $crv, 'x' => $x, 'y' => $y];
        }
        if ($kty === self::KTY_RSA) {
            if ($alg !== self::ALG_RS256) {
                throw new WebAuthnException('unsupported_algorithm', 'RSA public key algorithm is not supported.');
            }
            $n = $key[-1] ?? null;
            $e = $key[-2] ?? null;
            if (!is_string($n) || strlen($n) < 128 || !is_string($e) || $e === '') {
                throw new WebAuthnException('bad_public_key', 'RSA public key is malformed.');
            }
            return ['kty' => $kty, 'alg' => $alg, 'n' => $n, 'e' => $e];
        }
        throw new WebAuthnException('unsupported_algorithm', 'COSE public key type is not supported.');
    }

    /**
     * Verify a `packed` self-attestation statement.
     *
     * Only self-attestation is accepted: the statement must carry
     * `alg` and `sig`, must not carry an `x5c` certificate chain
     * (validating one is out of scope), the algorithm must match
     * the credential key, and the signature must verify over
     * authenticatorData || SHA-256(clientDataJSON).
     *
     * @param array<string, mixed> $attStmt Attestation statement.
     * @param string $authData Authenticator data bytes.
     * @param string $clientDataHash SHA-256 of the raw client data JSON.
     * @param array<string, mixed> $coseKey Parsed COSE key.
     * @return void
     * @throws WebAuthnException When the statement is invalid.
     */
    private function verifyPackedAttestation(array $attStmt, string $authData, string $clientDataHash, array $coseKey): void
    {
        if (array_key_exists('x5c', $attStmt)) {
            throw new WebAuthnException('unsupported_attestation', 'Packed attestation with a certificate chain is not supported.');
        }
        $alg = $attStmt['alg'] ?? null;
        $signature = $attStmt['sig'] ?? null;
        if (!is_int($alg) || !is_string($signature) || $signature === '') {
            throw new WebAuthnException('bad_attestation', 'Packed attestation statement is malformed.');
        }
        if ($alg !== ($coseKey['alg'] ?? null)) {
            throw new WebAuthnException('bad_attestation', 'Packed attestation algorithm does not match the credential key.');
        }
        if (!$this->verifySignature($coseKey, $authData . $clientDataHash, $signature)) {
            throw new WebAuthnException('bad_attestation', 'Packed attestation signature does not verify.');
        }
    }

    /**
     * Verify a signature against a parsed COSE key.
     *
     * ES256 signatures arrive as raw R||S (64 bytes) and are
     * converted to DER for OpenSSL. RS256 uses PKCS#1 v1.5 with
     * SHA-256.
     *
     * @param array<string, mixed> $coseKey Parsed COSE key.
     * @param string $data Signed bytes.
     * @param string $signature Signature bytes from the authenticator.
     * @return bool True when the signature verifies.
     */
    private function verifySignature(array $coseKey, string $data, string $signature): bool
    {
        $pem = $this->publicKeyToPem($coseKey);
        $publicKey = openssl_pkey_get_public($pem);
        if ($publicKey === false) {
            return false;
        }
        if (($coseKey['alg'] ?? null) === self::ALG_ES256) {
            if (strlen($signature) !== 64) {
                return false;
            }
            $signature = $this->rawToDer($signature);
        }
        $result = openssl_verify($data, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        return $result === 1;
    }

    /**
     * Build a PEM public key from a parsed COSE key.
     *
     * The DER SubjectPublicKeyInfo is assembled by hand (a small
     * TLV builder below) and wrapped in PEM armor. The test harness
     * checks the output byte-for-byte against OpenSSL-generated
     * keys for both key types.
     *
     * @param array<string, mixed> $coseKey Parsed COSE key.
     * @return string PEM public key.
     */
    private function publicKeyToPem(array $coseKey): string
    {
        $kty = $coseKey['kty'] ?? 0;
        if ($kty === self::KTY_EC2) {
            $algorithm = $this->derTlv(0x30, $this->derOid('1.2.840.10045.2.1') . $this->derOid('1.2.840.10045.3.1.7'));
            $keyBytes = "\x04" . (string) $coseKey['x'] . (string) $coseKey['y'];
            $der = $this->derTlv(0x30, $algorithm . $this->derTlv(0x03, "\x00" . $keyBytes));
            return $this->pemWrap($der, 'PUBLIC KEY');
        }
        $algorithm = $this->derTlv(0x30, $this->derOid('1.2.840.113549.1.1.1') . $this->derTlv(0x05, ''));
        $keyBytes = $this->derTlv(0x30, $this->derInteger((string) $coseKey['n']) . $this->derInteger((string) $coseKey['e']));
        $der = $this->derTlv(0x30, $algorithm . $this->derTlv(0x03, "\x00" . $keyBytes));
        return $this->pemWrap($der, 'PUBLIC KEY');
    }

    /**
     * Convert a raw ECDSA signature (R||S) to DER.
     *
     * @param string $raw 64 raw bytes.
     * @return string DER signature.
     */
    private function rawToDer(string $raw): string
    {
        return $this->derTlv(0x30, $this->derInteger(substr($raw, 0, 32)) . $this->derInteger(substr($raw, 32, 32)));
    }

    /**
     * DER-encode one INTEGER, stripping leading zeros and adding a
     * zero byte when the high bit is set.
     *
     * @param string $bytes Unsigned big-endian integer bytes.
     * @return string DER INTEGER TLV.
     */
    private function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }
        return $this->derTlv(0x02, $bytes);
    }

    /**
     * DER-encode an object identifier from its dotted form.
     *
     * @param string $oid Dotted OID (e.g. `1.2.840.10045.2.1`).
     * @return string DER OID TLV.
     */
    private function derOid(string $oid): string
    {
        $parts = explode('.', $oid);
        $first = (int) $parts[0];
        $second = (int) $parts[1];
        $content = chr($first * 40 + $second);
        $count = count($parts);
        for ($i = 2; $i < $count; $i++) {
            $value = (int) $parts[$i];
            $encoded = chr($value & 127);
            $value >>= 7;
            while ($value > 0) {
                $encoded = chr(0x80 | ($value & 127)) . $encoded;
                $value >>= 7;
            }
            $content .= $encoded;
        }
        return $this->derTlv(0x06, $content);
    }

    /**
     * DER-encode a tag-length-value triple.
     *
     * @param int $tag Tag byte.
     * @param string $content Content bytes.
     * @return string DER TLV.
     */
    private function derTlv(int $tag, string $content): string
    {
        $length = strlen($content);
        if ($length < 128) {
            $lengthBytes = chr($length);
        } elseif ($length < 256) {
            $lengthBytes = chr(0x81) . chr($length);
        } else {
            $lengthBytes = chr(0x82) . chr($length >> 8) . chr($length & 255);
        }
        return chr($tag) . $lengthBytes . $content;
    }

    /**
     * Wrap DER bytes in PEM armor.
     *
     * @param string $der DER bytes.
     * @param string $label PEM label (e.g. `PUBLIC KEY`).
     * @return string PEM text.
     */
    private function pemWrap(string $der, string $label): string
    {
        return '-----BEGIN ' . $label . "-----\n"
            . trim(chunk_split(base64_encode($der), 64, "\n"))
            . "\n-----END " . $label . "-----\n";
    }

    /**
     * Require a base64url value from the browser response.
     *
     * @param mixed $value Submitted value.
     * @param string $reason Reason code when it is missing or malformed.
     * @return string Decoded bytes.
     * @throws WebAuthnException When the value is missing or malformed.
     */
    private function requireBase64url(mixed $value, string $reason): string
    {
        if (!is_string($value) || $value === '') {
            throw new WebAuthnException($reason, 'Required base64url field is missing.');
        }
        $decoded = self::base64urlDecode($value);
        if ($decoded === null) {
            throw new WebAuthnException($reason, 'Field is not valid base64url.');
        }
        return $decoded;
    }
}
