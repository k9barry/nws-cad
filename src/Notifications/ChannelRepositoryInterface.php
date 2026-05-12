<?php

declare(strict_types=1);

namespace NwsCad\Notifications;

interface ChannelRepositoryInterface
{
    /**
     * @return array<int,array{id:int,name:string,type:string,enabled:bool,base_url:string,config_json:string}>
     */
    public function listEnabled(): array;

    /**
     * @return array{id:int,name:string,type:string,enabled:bool,base_url:string,config_json:string}|null
     */
    public function findById(int $id): ?array;

    public function recordSend(int $channelId, ?int $callId, ?string $intent, SendResult $result): void;

    public function markFailure(int $channelId, string $message): void;
}
