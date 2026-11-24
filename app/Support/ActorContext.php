<?php

namespace App\Support;

use App\Domain\Enums\ActorType;
use App\Models\Device;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * 是誰做了這個操作：員工（含哪一台平板）、顧客或系統。寫入操作紀錄用。
 */
final readonly class ActorContext
{
    public const DEVICE_COOKIE = 'device_id';

    public function __construct(
        public ActorType $type,
        public ?int $userId = null,
        public ?int $deviceId = null,
    ) {}

    public static function system(): self
    {
        return new self(ActorType::System);
    }

    public static function customer(): self
    {
        return new self(ActorType::Customer);
    }

    public static function staff(User $user, ?Device $device): self
    {
        return new self(ActorType::Staff, $user->id, $device?->id);
    }

    public static function fromRequest(Request $request): self
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return self::customer();
        }

        $deviceId = $request->cookie(self::DEVICE_COOKIE);
        $device = is_numeric($deviceId) ? Device::find((int) $deviceId) : null;

        return self::staff($user, $device);
    }
}
