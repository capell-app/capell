<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Core\Support\Json\JsonCodec;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Action;

class DismissHintAction extends Action
{
    public function handle(AuthenticatableUser $user, string $hintKey): void
    {
        $raw = DB::table('users')->where('id', $user->getKey())->value('dismissed_hints');
        $dismissed = is_string($raw) ? JsonCodec::decodeArray($raw) : [];
        $dismissed[] = $hintKey;

        DB::table('users')
            ->where('id', $user->getKey())
            ->update(['dismissed_hints' => json_encode(array_unique($dismissed))]);
    }
}
