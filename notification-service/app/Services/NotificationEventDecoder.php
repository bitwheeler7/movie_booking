<?php

namespace App\Services;

use InvalidArgumentException;
use JsonException;

class NotificationEventDecoder
{
    /**
     * @throws JsonException
     */
    public function decode(mixed $body): array
    {
        if (is_string($body)) {
            $body = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        }

        if (! is_array($body) || array_is_list($body)) {
            throw new InvalidArgumentException('Notification event must be a JSON object.');
        }

        return $body;
    }
}
