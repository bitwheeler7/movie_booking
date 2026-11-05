
<?php

$url = "http://127.0.0.1:8004/api/payments/refund-webhook";

$secret = "local-dev-webhook-secret-change-before-production";

$body = json_encode([
    "refund_reference" => "REF-NWRQ90DWLBBGEE",
    "gateway_refund_id" => "LOCAL-REFUND-TXN-001",
    "status" => "SUCCESS",
    "refunded_at" => date('c'),
]);

$signature = hash_hmac('sha256', $body, $secret);

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "Accept: application/json",
        "X-Payment-Signature: $signature",
    ],
]);

$response = curl_exec($ch);

if ($response === false) {
    echo "cURL Error: " . curl_error($ch) . PHP_EOL;
} else {
    echo "HTTP Status: " . curl_getinfo($ch, CURLINFO_HTTP_CODE) . PHP_EOL;
    echo "Response: " . $response . PHP_EOL;
}

curl_close($ch);
