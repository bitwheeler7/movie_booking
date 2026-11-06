
<?php

$url = "http://127.0.0.1:8004/api/payments/webhook";

$secret = "local-dev-webhook-secret-change-before-production";

$body = json_encode([
    "payment_reference" => "PAY-UNLASLRJNBY0X2",
    "gateway_transaction_id" => "LOCAL-TXN-002",
    "status" => "SUCCESS",
    "paid_at" => date('c'),
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

echo "HTTP Status: " . curl_getinfo($ch, CURLINFO_HTTP_CODE) . PHP_EOL;
echo "Redirect URL: " . (curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: 'None') . PHP_EOL;
echo "Response: " . $response . PHP_EOL;

curl_close($ch);
