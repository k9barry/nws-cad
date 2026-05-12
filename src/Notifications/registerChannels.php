<?php

declare(strict_types=1);

use NwsCad\Notifications\ChannelRegistry;
use NwsCad\Notifications\Channels\NtfyChannel;
use NwsCad\Notifications\Channels\PushoverChannel;

ChannelRegistry::clear();
ChannelRegistry::register(NtfyChannel::descriptor());
ChannelRegistry::register(PushoverChannel::descriptor());
