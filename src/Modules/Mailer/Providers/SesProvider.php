<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers;

use function __;
use function gmdate;
use function hash;
use function hash_hmac;
use function is_array;
use function is_string;
use function sprintf;
use function wp_json_encode;

/**
 * Amazon SES (Simple Email Service) v2 API provider.
 *
 * Endpoint `POST https://email.{region}.amazonaws.com/v2/email/outbound-emails`
 * with AWS Signature Version 4 signing done in pure PHP
 * (HMAC-SHA256 chain, service `ses`) - no AWS SDK, no extra
 * dependency. A 200 response with a `MessageId` means the mail
 * was accepted.
 *
 * Pricing (Nov 2026): no free tier - $0.10 per 1,000 emails
 * (plus $0.12/GB data). Best for: high-volume transactional
 * mail on AWS, where cost per email matters most.
 *
 * @package TrayDigita\WP\Plugin\Core\Modules\Mailer\Providers
 */
final class SesProvider extends AbstractApiProvider
{
    /**
     * SES v2 API service name used in the SigV4 scope.
     */
    public const string SERVICE = 'ses';

    /**
     * SES regions offered in the settings dropdown.
     *
     * @var array<string, string>
     */
    public const array REGIONS = [
        'us-east-1' => 'US East (N. Virginia)',
        'us-east-2' => 'US East (Ohio)',
        'us-west-1' => 'US West (N. California)',
        'us-west-2' => 'US West (Oregon)',
        'eu-west-1' => 'EU (Ireland)',
        'eu-central-1' => 'EU (Frankfurt)',
        'eu-north-1' => 'EU (Stockholm)',
        'ap-southeast-1' => 'Asia Pacific (Singapore)',
        'ap-southeast-2' => 'Asia Pacific (Sydney)',
        'ap-northeast-1' => 'Asia Pacific (Tokyo)',
        'ca-central-1' => 'Canada (Central)',
        'sa-east-1' => 'South America (São Paulo)',
    ];

    /**
     * @return array{id: string, label: string, description: string, free_tier: string, best_for: string, link: string, fields: array<int, array<string, mixed>>}
     */
    public static function definition(): array
    {
        return [
            'id' => 'ses',
            'label' => 'Amazon SES',
            'description' => __(
                'Amazon SES — no free tier, $0.10 per 1,000 emails. Best for high-volume transactional mail on AWS, where cost per email matters most.',
                'traydigita-core'
            ),
            'free_tier' => '',
            'best_for' => 'High-volume transactional mail on AWS',
            'link' => '',
            'fields' => [
                [
                    'key' => 'ses_access_key_id',
                    'label' => __('SES access key ID', 'traydigita-core'),
                    'type' => 'text',
                    'hint' => __(
                        'An IAM access key with the ses:SendEmail permission (verify the sender identity in SES first; new accounts start in the sandbox).',
                        'traydigita-core'
                    ),
                    'secret' => false,
                    'required' => true,
                ],
                [
                    'key' => 'ses_secret_access_key',
                    'label' => __('SES secret access key', 'traydigita-core'),
                    'type' => 'password',
                    'hint' => __('Leave empty to keep the saved secret key. The key is never shown.', 'traydigita-core'),
                    'secret' => true,
                    'required' => true,
                ],
                [
                    'key' => 'ses_region',
                    'label' => __('SES region', 'traydigita-core'),
                    'type' => 'select',
                    'options' => self::REGIONS,
                    'hint' => __('The AWS region the SES identity was verified in.', 'traydigita-core'),
                    'secret' => false,
                    'required' => true,
                ],
            ],
        ];
    }

    /**
     * @param array{to: array<int, array{email: string, name: string}>, cc: array<int, array{email: string, name: string}>, bcc: array<int, array{email: string, name: string}>, to_text: string, subject: string, body: string, is_html: bool, from_email: string, from_name: string, reply_to: string, headers: array<string, string>, is_bulk: bool, attachments: array<int, string>} $mail Normalized mail.
     * @param array<string, mixed> $settings Mailer settings (flat keys).
     * @return array{success: bool, message: string}
     */
    public static function send(array $mail, array $settings): array
    {
        $guard = self::guard($mail);
        if ($guard !== null) {
            return $guard;
        }
        $accessKey = self::setting($settings, 'ses_access_key_id');
        $secretKey = self::setting($settings, 'ses_secret_access_key');
        $region = self::setting($settings, 'ses_region');
        if ($accessKey === '' || $secretKey === '' || $region === '') {
            return ['success' => false, 'message' => __('Enter the Amazon SES access key, secret key, and region in the Mailer settings first.', 'traydigita-core')];
        }
        if (!isset(self::REGIONS[$region])) {
            return ['success' => false, 'message' => __('The Amazon SES region is not valid.', 'traydigita-core')];
        }
        // SES v2 Simple messages carry no attachments: a mail
        // with attachments cannot be sent through this endpoint,
        // so fail clearly instead of silently dropping them.
        if ($mail['attachments'] !== []) {
            return ['success' => false, 'message' => __('Amazon SES (Simple message) does not support attachments; use the SMTP provider or another API provider for mails with attachments.', 'traydigita-core')];
        }
        $to = [];
        $cc = [];
        $bcc = [];
        foreach ($mail['to'] as $address) {
            $to[] = $address['email'];
        }
        foreach ($mail['cc'] as $address) {
            $cc[] = $address['email'];
        }
        foreach ($mail['bcc'] as $address) {
            $bcc[] = $address['email'];
        }
        $body = ['Text' => ['Data' => self::plainText($mail), 'Charset' => 'UTF-8']];
        if ($mail['is_html']) {
            $body['Html'] = ['Data' => $mail['body'], 'Charset' => 'UTF-8'];
        }
        $payload = [
            'FromEmailAddress' => self::formatAddress(['email' => $mail['from_email'], 'name' => $mail['from_name']]),
            'Destination' => ['ToAddresses' => $to],
            'Content' => [
                'Simple' => [
                    'Subject' => ['Data' => $mail['subject'], 'Charset' => 'UTF-8'],
                    'Body' => $body,
                ],
            ],
        ];
        if ($cc !== []) {
            $payload['Destination']['CcAddresses'] = $cc;
        }
        if ($bcc !== []) {
            $payload['Destination']['BccAddresses'] = $bcc;
        }
        if ($mail['reply_to'] !== '') {
            $payload['ReplyToAddresses'] = [$mail['reply_to']];
        }
        if ($mail['headers'] !== []) {
            $payload['Content']['Simple']['Headers'] = [];
            foreach ($mail['headers'] as $name => $value) {
                $payload['Content']['Simple']['Headers'][] = ['Name' => $name, 'Value' => $value];
            }
        }
        $host = 'email.' . $region . '.amazonaws.com';
        $url = 'https://' . $host . '/v2/email/outbound-emails';
        $bodyJson = (string) wp_json_encode($payload);
        $headers = self::signRequest($host, $bodyJson, $accessKey, $secretKey, $region);
        $result = self::postJson($url, $headers, $payload);
        if ($result['error'] !== '' || $result['code'] !== 200) {
            return ['success' => false, 'message' => self::apiErrorMessage($result, 'Amazon SES')];
        }
        $data = $result['data'];
        if (!is_array($data) || empty($data['MessageId']) || !is_string($data['MessageId'])) {
            return ['success' => false, 'message' => self::apiErrorMessage($result, 'Amazon SES')];
        }
        return ['success' => true, 'message' => __('Sent through Amazon SES.', 'traydigita-core')];
    }

    /**
     * AWS Signature Version 4 headers for one SES request.
     *
     * Pure PHP: HMAC-SHA256 chain (`AWS4` + date, region,
     * service, `aws4_request`), signing the canonical request
     * built from the method, path, signed headers, and the
     * SHA-256 of the JSON body.
     *
     * @param string $host SES endpoint host.
     * @param string $bodyJson JSON request body.
     * @param string $accessKey AWS access key ID.
     * @param string $secretKey AWS secret access key.
     * @param string $region AWS region.
     * @return array<string, string> Signed request headers.
     */
    private static function signRequest(string $host, string $bodyJson, string $accessKey, string $secretKey, string $region): array
    {
        $date = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $payloadHash = hash('sha256', $bodyJson);
        $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';
        $canonicalHeaders = 'content-type:application/json' . "\n"
            . 'host:' . $host . "\n"
            . 'x-amz-content-sha256:' . $payloadHash . "\n"
            . 'x-amz-date:' . $date . "\n";
        $canonicalRequest = 'POST' . "\n"
            . '/v2/email/outbound-emails' . "\n"
            . "\n"
            . $canonicalHeaders . "\n"
            . $signedHeaders . "\n"
            . $payloadHash;
        $scope = $dateStamp . '/' . $region . '/' . self::SERVICE . '/aws4_request';
        $stringToSign = 'AWS4-HMAC-SHA256' . "\n"
            . $date . "\n"
            . $scope . "\n"
            . hash('sha256', $canonicalRequest);
        $keyDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true);
        $keyRegion = hash_hmac('sha256', $region, $keyDate, true);
        $keyService = hash_hmac('sha256', self::SERVICE, $keyRegion, true);
        $keySigning = hash_hmac('sha256', 'aws4_request', $keyService, true);
        $signature = hash_hmac('sha256', $stringToSign, $keySigning);
        return [
            'X-Amz-Date' => $date,
            'X-Amz-Content-Sha256' => $payloadHash,
            'Authorization' => sprintf(
                'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
                $accessKey,
                $scope,
                $signedHeaders,
                $signature
            ),
        ];
    }
}
